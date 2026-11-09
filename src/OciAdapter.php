<?php

namespace LaravelOCI\LaravelOciDriver;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\Support\MultipartUploader;
use LaravelOCI\LaravelOciDriver\Support\ResponseGuard;
use LaravelOCI\LaravelOciDriver\Support\WorkRequestPoller;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Oracle Cloud Infrastructure Object Storage Adapter for Laravel Flysystem
 */
final readonly class OciAdapter implements FilesystemAdapter
{
    private const LIST_PAGE_SIZE = 1000;

    private WorkRequestPoller $workRequestPoller;

    /**
     * @param  OciClient  $client  OCI client for API requests
     * @param  ?\Closure  $sleep  Receives microseconds; replaces usleep while polling copy work requests (tests)
     */
    public function __construct(private OciClient $client, ?\Closure $sleep = null)
    {
        $ociConfig = $client->getOciConfig();
        $this->workRequestPoller = new WorkRequestPoller(
            $client,
            (int) $ociConfig->get('copy_timeout', 300),
            (int) $ociConfig->get('copy_poll_interval', 1000),
            $sleep,
        );
    }

    /**
     * Check if a file exists at the given path
     *
     * @param  string  $path  File path
     * @return bool True if file exists, false otherwise
     */
    public function fileExists(string $path): bool
    {
        return $this->exists($path);
    }

    /**
     * Check if a directory exists at the given path
     *
     * @param  string  $path  Directory path
     * @return bool True if directory exists, false otherwise
     */
    public function directoryExists(string $path): bool
    {
        try {
            $page = $this->client->listObjectsPage($this->directoryPrefix($path), null, null, 1);

            return $page['objects'] !== [] || $page['prefixes'] !== [];
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    /**
     * The full (configured-prefix-aware) listing prefix of a directory, with a trailing slash; '' for the root.
     */
    private function directoryPrefix(string $path): string
    {
        $prefixed = rtrim($this->client->getPrefixedPath($path), '/');

        return $prefixed === '' ? '' : $prefixed.'/';
    }

    /**
     * Check if a file or directory exists at the given path
     *
     * @param  string  $path  Path to check
     * @return bool True if exists, false otherwise
     */
    private function exists(string $path): bool
    {
        $uri = $this->client->getObjectUri($this->client->getPrefixedPath($path));

        try {
            $response = $this->client->send($uri, 'HEAD');

            return match ($response->getStatusCode()) {
                200 => true,
                404 => false,
                default => throw OciRequestFailed::fromResponse($response, 'exists'),
            };
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    /**
     * Write a file using a stream
     *
     * @param  string  $path  Path to write to
     * @param  resource  $contents  Contents to write
     * @param  Config  $config  Configuration options
     *
     * @throws UnableToWriteFile
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $stream = Utils::streamFor($contents);
        $size = $this->remainingSize($contents);
        $position = $size === null ? false : ftell($contents);

        // The HTTP layer rewinds seekable bodies, so pin the start to the current position.
        if ($position) {
            $stream = new LimitStream($stream, -1, $position);
        }

        $this->upload($path, $stream, $size, $config, false);
    }

    /**
     * Read a file's contents
     *
     * @param  string  $path  Path to read from
     * @return string File contents
     *
     * @throws UnableToReadFile
     */
    public function read(string $path): string
    {
        $uri = $this->client->getObjectUri($this->client->getPrefixedPath($path));

        try {
            $response = ResponseGuard::ok($this->client->send($uri, 'GET'), 'read');

            return $response->getBody()->getContents();
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Read a file as a stream
     *
     * @param  string  $path  Path to read from
     * @return resource File stream
     *
     * @throws UnableToReadFile
     */
    public function readStream(string $path)
    {
        $uri = $this->client->getObjectUri($this->client->getPrefixedPath($path));

        try {
            $response = ResponseGuard::ok(
                $this->client->send($uri, 'GET', options: ['stream' => true]),
                'readStream',
            );

            $resource = $response->getBody()->detach();
            if ($resource === null) {
                throw new RuntimeException('Response body could not be converted to a stream.');
            }

            return $resource;
        } catch (OciRequestFailed|GuzzleException|RuntimeException $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Delete a directory and everything under it.
     *
     * OCI has no real directories, so every object sharing the directory prefix (including
     * the placeholder) is listed page by page and deleted concurrently.
     *
     * @param  string  $path  Directory path
     *
     * @throws UnableToDeleteDirectory
     */
    public function deleteDirectory(string $path): void
    {
        $fullPrefix = $this->directoryPrefix($path);
        $failed = [];
        $start = null;

        try {
            do {
                $page = $this->client->listObjectsPage($fullPrefix, null, $start, self::LIST_PAGE_SIZE);
                $names = array_column($page['objects'], 'name');

                if ($names !== []) {
                    foreach ($this->client->bulkDelete($names)['errors'] as $error) {
                        $failed[] = $error['path'];
                    }
                }

                $next = $page['nextStartWith'];
                $advance = $next !== null && $next !== $start;
                $start = $next;
            } while ($advance);
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }

        if ($failed !== []) {
            throw UnableToDeleteDirectory::atLocation($path, 'failed to delete: '.implode(', ', array_slice($failed, 0, 20)));
        }
    }

    /**
     * Delete a file
     *
     * @param  string  $path  File path
     *
     * @throws UnableToDeleteFile
     */
    public function delete(string $path): void
    {
        $prefixedPath = $this->client->getPrefixedPath($path);
        $isDirectoryPath = $prefixedPath === '' || str_ends_with($prefixedPath, '/');

        // If it appears to be a directory, check if it has contents
        if ($isDirectoryPath) {
            $dirPath = rtrim($prefixedPath, '/').'/';
            $queryParams = ['prefix' => $dirPath, 'limit' => 1]; // Just need to know if at least one object exists
            $requestUri = sprintf('%s/o', $this->client->getBucketUri()).'?'.http_build_query($queryParams);

            try {
                $response = $this->client->send($requestUri, 'GET');

                if ($response->getStatusCode() === 200) {
                    $data = json_decode($response->getBody()->getContents(), false);

                    // If there are objects with this prefix, use the directory deletion method
                    if (! empty($data->objects)) {
                        $this->deleteDirectory($path);

                        return;
                    }
                }
            } catch (GuzzleException $exception) {
                throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        // Otherwise, proceed with single file deletion
        $uri = $this->client->getObjectUri($prefixedPath);

        try {
            ResponseGuard::ok($this->client->send($uri, 'DELETE'), 'delete', 200, 204, 404);
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Create a directory
     *
     * @param  string  $path  Directory path
     * @param  Config  $config  Configuration options
     */
    public function createDirectory(string $path, Config $config): void
    {
        $prefixed = rtrim($this->client->getPrefixedPath($path), '/');

        try {
            ResponseGuard::ok($this->client->send(
                $this->client->getObjectUri($prefixed === '' ? '/' : $prefixed.'/'),
                'PUT',
                ['Content-Length' => '0'],
                '',
                'application/x-directory',
            ), 'createDirectory');
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Write a file with enhanced metadata and storage options
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/PutObject
     *
     * @param  string  $path  Path to write to
     * @param  string  $contents  Contents to write
     * @param  Config  $config  Configuration options
     *
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, Utils::streamFor($contents), strlen($contents), $config, true, $contents);
    }

    /**
     * Single PUT for known small bodies, native multipart for large or unknown-size ones.
     *
     * @param  ?int  $size  Bytes to upload, or null when unknown
     * @param  ?string  $checksumSource  String body to hash (never set for streams)
     */
    private function upload(string $path, StreamInterface $body, ?int $size, Config $config, bool $checksum, ?string $checksumSource = null): void
    {
        $prefixedPath = $this->client->getPrefixedPath($path);
        $ociConfig = $this->client->getOciConfig();

        try {
            $headers = $this->buildWriteHeaders($path, $config);

            if ($size !== null && $size <= (int) $ociConfig->get('upload.multipart_threshold', 104857600)) {
                if ($checksum && $checksumSource !== null && $config->get('checksum', true) && $ociConfig->get('upload.enable_checksum', true)) {
                    $headers['Content-MD5'] = base64_encode(md5($checksumSource, true));
                }

                ResponseGuard::ok($this->client->send(
                    $this->client->getObjectUri($prefixedPath),
                    'PUT',
                    $headers + ['Content-Length' => (string) $size],
                    $body,
                    $headers['Content-Type'],
                ), 'write');

                return;
            }

            (new MultipartUploader($this->client, $ociConfig->getChunkSize()))
                ->upload($prefixedPath, $body, $headers);
        } catch (OciRequestFailed|GuzzleException|\LengthException|\UnexpectedValueException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildWriteHeaders(string $path, Config $config): array
    {
        $headers = [
            'storage-tier' => (string) $config->get('storage_tier', $this->client->getStorageTier()->value()),
            'Content-Type' => (string) ($config->get('content_type')
                ?? (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path)
                ?? 'application/octet-stream'),
        ];

        foreach ($config->get('metadata', []) as $key => $value) {
            $headers['opc-meta-'.$key] = (string) $value;
        }

        return $headers;
    }

    /**
     * Bytes left to read from the resource's current position, or null when unknown (pipes, sockets).
     *
     * @param  resource  $resource
     */
    private function remainingSize($resource): ?int
    {
        if (! stream_get_meta_data($resource)['seekable']) {
            return null;
        }

        $stat = fstat($resource);
        $position = ftell($resource);
        if ($stat === false || $position === false) {
            return null;
        }

        return max(0, $stat['size'] - $position);
    }

    /**
     * Set the visibility of a file.
     *
     * OCI Object Storage has no per-object visibility. When `visibility_mapping` is configured
     * (for example `['public' => 'Standard', 'private' => 'InfrequentAccess']`), the visibility is
     * translated to a storage tier and applied; otherwise this operation is unsupported.
     *
     * @param  string  $path  File path
     * @param  string  $visibility  Visibility key present in `visibility_mapping`
     *
     * @throws UnableToSetVisibility When unsupported, unmapped, or the update fails
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $mapping = $this->visibilityMapping();

        if ($mapping === null) {
            throw UnableToSetVisibility::atLocation(
                $path,
                'OCI Object Storage has no per-object visibility; control access at bucket level or set visibility_mapping'
            );
        }

        if (! isset($mapping[$visibility]) || ! is_string($mapping[$visibility])) {
            throw UnableToSetVisibility::atLocation($path, "Visibility '{$visibility}' is not present in visibility_mapping");
        }

        $tier = StorageTier::tryFrom($mapping[$visibility]);

        if ($tier === null) {
            throw UnableToSetVisibility::atLocation(
                $path,
                "visibility_mapping maps '{$visibility}' to unknown storage tier '{$mapping[$visibility]}'"
            );
        }

        try {
            $this->updateStorageTier($path, $tier);
        } catch (\Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Get the visibility of a file.
     *
     * Without `visibility_mapping` this verifies the object exists and returns the disk's
     * configured default visibility. With a mapping, the object's storage tier is reverse-mapped
     * to a visibility, falling back to the default when the tier is not mapped.
     *
     * @param  string  $path  File path
     * @return FileAttributes File attributes with visibility value
     *
     * @throws UnableToRetrieveMetadata When the object is missing or the request fails
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            $objectInfo = $this->client->getObjectInfo($path);
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToRetrieveMetadata::visibility($path, $exception->getMessage(), $exception);
        }

        if ($objectInfo === null) {
            throw UnableToRetrieveMetadata::visibility($path, 'File not found');
        }

        $visibility = $this->defaultVisibility();
        $tier = $objectInfo['storage_tier'] ?? null;

        foreach ($this->visibilityMapping() ?? [] as $name => $mappedTier) {
            if ($tier !== null && $mappedTier === $tier) {
                $visibility = (string) $name;
                break;
            }
        }

        return new FileAttributes($path, null, $visibility);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function visibilityMapping(): ?array
    {
        $mapping = $this->client->getOciConfig()->get('visibility_mapping');

        return is_array($mapping) && $mapping !== [] ? $mapping : null;
    }

    private function defaultVisibility(): string
    {
        $visibility = $this->client->getOciConfig()->get('visibility', 'private');

        return is_string($visibility) && $visibility !== '' ? $visibility : 'private';
    }

    /**
     * Get the mime type of a file
     *
     * @param  string  $path  File path
     * @return FileAttributes File attributes with mime type
     *
     * @throws UnableToReadFile
     */
    public function mimeType(string $path): FileAttributes
    {
        $uri = $this->client->getObjectUri($this->client->getPrefixedPath($path));

        try {
            $response = ResponseGuard::ok($this->client->send($uri, 'HEAD'), 'mimeType');
            if (! $response->hasHeader('Content-Type')) {
                throw new RuntimeException('Missing Content-Type header.');
            }
            $mimeType = $response->getHeaderLine('Content-Type');

            return new FileAttributes(path: $path, mimeType: $mimeType);
        } catch (OciRequestFailed|GuzzleException|RuntimeException $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Get the last modified time of a file
     *
     * @param  string  $path  File path
     * @return FileAttributes File attributes with last modified time
     *
     * @throws UnableToReadFile
     */
    public function lastModified(string $path): FileAttributes
    {
        $uri = $this->client->getObjectUri($this->client->getPrefixedPath($path));

        try {
            $response = ResponseGuard::ok($this->client->send($uri, 'HEAD'), 'lastModified');
            if (! $response->hasHeader('last-modified')) {
                throw new RuntimeException('Missing Last-Modified header.');
            }
            $lastModified = Carbon::parse($response->getHeaderLine('last-modified'))->getTimestamp();

            return new FileAttributes(path: $path, lastModified: $lastModified);
        } catch (OciRequestFailed|GuzzleException|RuntimeException $exception) {
            throw UnableToRetrieveMetadata::lastModified($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * Get the size of a file
     *
     * @param  string  $path  File path
     * @return FileAttributes File attributes with size
     *
     * @throws UnableToReadFile
     */
    public function fileSize(string $path): FileAttributes
    {
        $uri = $this->client->getObjectUri($this->client->getPrefixedPath($path));

        try {
            $response = ResponseGuard::ok($this->client->send($uri, 'HEAD'), 'fileSize');
            if (! $response->hasHeader('Content-Length')) {
                throw new RuntimeException('Missing Content-Length header.');
            }
            $fileSize = (int) $response->getHeaderLine('Content-Length');

            return new FileAttributes(path: $path, fileSize: $fileSize);
        } catch (OciRequestFailed|GuzzleException|RuntimeException $exception) {
            throw UnableToRetrieveMetadata::fileSize($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * List contents of a directory, following OCI's pagination lazily.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/ListObjects
     *
     * @param  string  $path  Directory path
     * @param  bool  $deep  Whether to recurse into subdirectories
     * @return iterable<StorageAttributes>
     *
     * @throws UnableToListContents
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $fullPrefix = $this->directoryPrefix($path);
        $delimiter = $deep ? null : '/';
        $yieldedDirectories = [];
        $start = null;

        do {
            try {
                $page = $this->client->listObjectsPage($fullPrefix, $delimiter, $start, self::LIST_PAGE_SIZE);
            } catch (OciRequestFailed|GuzzleException $exception) {
                throw UnableToListContents::atLocation($path, $deep, $exception);
            }

            foreach ($page['objects'] as $object) {
                $name = (string) ($object['name'] ?? '');

                if ($name === '' || $name === $fullPrefix) {
                    continue;
                }

                if (str_ends_with($name, '/')) {
                    $directory = rtrim($this->client->removePrefixFromPath($name), '/');
                    if (! isset($yieldedDirectories[$directory])) {
                        $yieldedDirectories[$directory] = true;

                        yield new DirectoryAttributes($directory);
                    }

                    continue;
                }

                yield new FileAttributes(
                    $this->client->removePrefixFromPath($name),
                    isset($object['size']) ? (int) $object['size'] : null,
                    null,
                    isset($object['timeModified']) ? $this->toTimestamp((string) $object['timeModified']) : null,
                    null,
                    [
                        'etag' => $object['etag'] ?? null,
                        'md5' => $object['md5'] ?? null,
                        'storage_tier' => $object['storageTier'] ?? null,
                    ],
                );
            }

            foreach ($page['prefixes'] as $prefix) {
                $directory = rtrim($this->client->removePrefixFromPath($prefix), '/');
                if ($directory !== '' && ! isset($yieldedDirectories[$directory])) {
                    $yieldedDirectories[$directory] = true;

                    yield new DirectoryAttributes($directory);
                }
            }

            $next = $page['nextStartWith'];
            // A repeated token would loop forever; treat it as the end of the listing.
            $more = $next !== null && $next !== $start;
            $start = $next;
        } while ($more);
    }

    private function toTimestamp(string $time): ?int
    {
        $timestamp = strtotime($time);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Move a file using the native OCI renameObject API (atomic, no copy + delete fallback)
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/RenameObject
     *
     * @param  string  $source  Source path
     * @param  string  $destination  Destination path
     * @param  Config  $config  Configuration options
     *
     * @throws UnableToMoveFile
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $response = $this->client->send(
                $this->client->renameObjectUri(),
                'POST',
                [],
                $this->client->renameObjectBody($source, $destination),
            );
            ResponseGuard::ok($response, 'rename object');
        } catch (OciRequestFailed|GuzzleException $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * Copy a file using the asynchronous OCI copyObject API, waiting for its work request to finish
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/CopyObject
     *
     * @param  string  $source  Source path
     * @param  string  $destination  Destination path
     * @param  Config  $config  Configuration options
     *
     * @throws UnableToCopyFile
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $uri = sprintf('%s/actions/copyObject', $this->client->getBucketUri());

        $payload = [
            'sourceObjectName' => $this->client->getPrefixedPath($source),
            'destinationRegion' => $this->client->getRegion(),
            'destinationNamespace' => $this->client->getNamespace(),
            'destinationBucket' => $this->client->getBucket(),
            'destinationObjectName' => $this->client->getPrefixedPath($destination),
        ];

        $metadata = $config->get('metadata');
        if (is_array($metadata) && $metadata !== []) {
            $payload['destinationObjectMetadata'] = $metadata;
        }

        $tier = $config->get('storage_tier');
        if (is_string($tier) && $tier !== '') {
            $payload['destinationObjectStorageTier'] = $tier;
        }

        try {
            $response = $this->client->send(
                $uri,
                'POST',
                ['opc-retry-token' => Uuid::uuid4()->toString()],
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
            ResponseGuard::ok($response, 'copy object', 202);

            $workRequestId = $response->getHeaderLine('opc-work-request-id');
            if ($workRequestId === '') {
                throw new RuntimeException('copy object response is missing opc-work-request-id');
            }

            $this->workRequestPoller->wait($workRequestId);
        } catch (OciRequestFailed|GuzzleException|RuntimeException $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * Restore an archived object.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/RestoreObjects
     *
     * @param  string  $path  Path to restore
     * @param  int  $hours  Number of hours to make the restored object available (10-240000 hours)
     * @return bool True when the restore request was accepted
     *
     * @throws UnableToWriteFile When the request fails
     */
    public function restore(string $path, int $hours = 24): bool
    {
        try {
            $response = $this->client->send(
                $this->client->restoreObjectUri(),
                'POST',
                [],
                $this->client->restoreObjectBody($path, $hours),
            );
            ResponseGuard::ok($response, 'restore object', 200, 202);
        } catch (OciRequestFailed|GuzzleException|\JsonException|\InvalidArgumentException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        return true;
    }

    /**
     * Update the storage tier of an object.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/UpdateObjectStorageTier
     *
     * @param  string  $path  Object path
     * @param  string|StorageTier  $storageTier  Storage tier to change to
     * @return bool True when the tier was updated
     *
     * @throws UnableToWriteFile When the request fails
     */
    public function updateStorageTier(string $path, string|StorageTier $storageTier): bool
    {
        if (is_string($storageTier)) {
            $storageTier = StorageTier::fromString($storageTier);
        }

        try {
            $response = $this->client->send(
                $this->client->updateStorageTierUri(),
                'POST',
                [],
                $this->client->updateStorageTierBody($path, $storageTier),
            );
            ResponseGuard::ok($response, 'update storage tier');
        } catch (OciRequestFailed|GuzzleException|\JsonException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        return true;
    }

    /**
     * Get the underlying OCI client.
     */
    public function getClient(): OciClient
    {
        return $this->client;
    }

    /**
     * Get a public URL for a file
     *
     * @param  string  $path  File path
     * @return string Public URL
     */
    public function getUrl($path): string
    {
        return $this->client->getObjectUrl($path);
    }

    /**
     * Create a temporary URL for a file
     *
     * @param  string  $path  File path
     * @param  Carbon|null  $expiresAt  Expiration time (defaults to the configured default expiry)
     * @param  array{access_type?: string}  $options
     * @return string Temporary URL
     */
    public function createTemporaryUrl(string $path, ?Carbon $expiresAt = null, array $options = []): string
    {
        return $this->client->createTemporaryUrl($path, $expiresAt, $options);
    }
}
