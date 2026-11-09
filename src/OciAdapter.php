<?php

namespace LaravelOCI\LaravelOciDriver;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\Support\MultipartUploader;
use LaravelOCI\LaravelOciDriver\Support\ResponseGuard;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Oracle Cloud Infrastructure Object Storage Adapter for Laravel Flysystem
 */
final readonly class OciAdapter implements FilesystemAdapter
{
    /**
     * @param  OciClient  $client  OCI client for API requests
     */
    public function __construct(private OciClient $client) {}

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
        return $this->exists($path);
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
     * Delete a directory and all of its contents using bulk delete for efficiency
     *
     * This method uses Oracle's BulkDelete API to efficiently remove all objects under a specific directory prefix.
     * Since OCI doesn't have real directory objects, we look for all objects that share the given prefix and
     * delete them in a single API call for better performance.
     *
     * @param  string  $path  Directory path
     *
     * @throws RuntimeException When unable to list or delete objects
     */
    public function deleteDirectory(string $path): void
    {
        $prefixedPath = $this->client->getPrefixedPath($path);
        $dirPath = rtrim($prefixedPath, '/').'/';

        // List all objects in the bucket with the directory prefix
        $uri = sprintf('%s/o', $this->client->getBucketUri());
        $queryParams = ['prefix' => $dirPath];
        $requestUri = $uri.'?'.http_build_query($queryParams);

        try {
            $response = $this->client->send($requestUri, 'GET');

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), false);

                // Early return if no objects found
                if (empty($data->objects)) {
                    return;
                }

                // Extract all object paths for bulk deletion
                $objectPaths = array_map(
                    fn ($object) => $object->name,
                    $data->objects
                );

                // Add directory placeholder itself if it exists (will be ignored if not)
                $objectPaths[] = $dirPath;

                // Use bulk delete to remove all objects in a single API call
                $result = $this->client->bulkDelete($objectPaths);

                // Log any errors that occurred during bulk deletion
                if (! empty($result['errors']) && class_exists('\Illuminate\Support\Facades\Log')) {
                    Log::warning(
                        'Some files could not be deleted during directory removal',
                        ['errors' => $result['errors'], 'directory' => $dirPath]
                    );
                }
            }
        } catch (GuzzleException $exception) {
            throw new RuntimeException(
                sprintf('Error during directory deletion: %s', $exception->getMessage()),
                $exception->getCode(),
                $exception
            );
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
        $this->write($path, '', $config);
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
                if ($checksum && $checksumSource !== null && $ociConfig->get('upload.enable_checksum', true)) {
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
        if (! (stream_get_meta_data($resource)['seekable'] ?? false)) {
            return null;
        }

        $stat = fstat($resource);
        $position = ftell($resource);
        if ($stat === false || $position === false || ! isset($stat['size'])) {
            return null;
        }

        return max(0, $stat['size'] - $position);
    }

    /**
     * Set storage tier for a file using the visibility parameter
     *
     * This maps the Flysystem visibility concept to OCI storage tiers:
     * - 'public': Standard tier (frequently accessed objects, good performance)
     * - 'private': Archive tier (rarely accessed objects, lower cost)
     * - 'infrequent': InfrequentAccess tier (balance between performance and cost)
     *
     * @param  string  $path  File path
     * @param  string  $visibility  Maps to storage tier ('public', 'private', 'infrequent')
     *
     * @throws \InvalidArgumentException When invalid visibility value is provided
     * @throws UnableToSetVisibility When the operation fails
     */
    public function setVisibility(string $path, string $visibility): void
    {
        // Map visibility to storage tier
        $storageTier = match ($visibility) {
            'public' => StorageTier::STANDARD,
            'private' => StorageTier::ARCHIVE,
            'infrequent' => StorageTier::INFREQUENT_ACCESS,
            default => throw new \InvalidArgumentException("Invalid visibility value: {$visibility}. Use 'public', 'private', or 'infrequent'."),
        };

        try {
            $success = $this->client->updateObjectStorageTier($path, $storageTier);

            if (! $success) {
                throw UnableToSetVisibility::atLocation(
                    $path,
                    'Failed to set storage tier'
                );
            }
        } catch (\Exception $exception) {
            throw UnableToSetVisibility::atLocation(
                $path,
                "Error: {$exception->getMessage()}",
                $exception
            );
        }
    }

    /**
     * Get storage tier of a file mapped to visibility concept
     *
     * This maps OCI storage tiers back to Flysystem visibility concept:
     * - Standard tier -> 'public'
     * - Archive tier -> 'private'
     * - InfrequentAccess tier -> 'infrequent'
     *
     * @param  string  $path  File path
     * @return FileAttributes File attributes with visibility value
     *
     * @throws UnableToRetrieveMetadata When unable to get file info
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            $objectInfo = $this->client->getObjectInfo($path);

            if ($objectInfo === null) {
                throw UnableToRetrieveMetadata::visibility(
                    $path,
                    'File not found'
                );
            }

            // Extract the storage tier from object metadata
            $storageTier = $objectInfo['storage-tier'] ?? 'Standard';

            // Map storage tier to visibility
            $visibility = match ($storageTier) {
                'Archive' => 'private',
                'InfrequentAccess' => 'infrequent',
                default => 'public', // Standard
            };

            return new FileAttributes($path, null, $visibility);
        } catch (\Exception $exception) {
            throw UnableToRetrieveMetadata::visibility(
                $path,
                "Error: {$exception->getMessage()}",
                $exception
            );
        }
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
            $lastModified = Carbon::parse($response->getHeaderLine('last-modified'))->timestamp;

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
     * List contents of a directory
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/ListObjects
     *
     * @param  string  $path  Directory path
     * @param  bool  $deep  Whether to recurse into subdirectories
     * @return iterable<FileAttributes> List of file attributes
     *
     * @throws UnableToListContents
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefixedPath = $this->client->getPrefixedPath($path);
        $prefix = rtrim($prefixedPath, '/');
        $prefix = ! empty($prefix) ? $prefix.'/' : '';
        $delimiter = $deep ? null : '/';

        try {
            // Use the client's listObjects method for better error handling and pagination
            $result = $this->client->listObjects([
                'prefix' => $prefix,
                'delimiter' => $delimiter,
                // Use a reasonable limit to prevent memory issues
                'limit' => 1000,
            ]);

            // Convert to FileAttributes collection
            $files = collect();

            // Process regular objects
            foreach ($result['objects'] ?? [] as $object) {
                // Skip the directory placeholder itself when listing
                if ($object['name'] === $prefix && ! empty($prefix)) {
                    continue;
                }

                // For non-recursive listing, skip nested objects
                if (
                    ! $deep && ! empty($prefix) &&
                    str_contains(substr($object['name'], strlen($prefix)), '/')
                ) {
                    continue;
                }

                // Extract file metadata
                $fileSize = $object['size'] ?? null;
                $lastModified = null;
                if (isset($object['timeModified'])) {
                    $timestamp = strtotime($object['timeModified']);
                    $lastModified = $timestamp !== false ? $timestamp : null;
                }
                $mimeType = $object['contentType'] ?? null;

                // Determine if it's a directory by checking for trailing slash
                $isDirectory = str_ends_with($object['name'], '/');

                $files->push(
                    new FileAttributes(
                        path: $object['name'],
                        fileSize: $isDirectory ? null : (int) $fileSize,
                        visibility: null,
                        lastModified: $lastModified,
                        mimeType: $mimeType,
                        extraMetadata: [
                            'type' => $isDirectory ? 'dir' : 'file',
                            'etag' => $object['etag'] ?? null,
                            'md5' => $object['md5'] ?? null,
                            'storage_tier' => $object['storageTier'] ?? null,
                        ]
                    )
                );
            }

            // If not recursive, also process prefixes (directories)
            if (! $deep && isset($result['prefixes'])) {
                foreach ($result['prefixes'] as $dirPrefix) {
                    $files->push(
                        new FileAttributes(
                            path: $dirPrefix,
                            fileSize: null,
                            visibility: null,
                            lastModified: null,
                            mimeType: null,
                            extraMetadata: ['type' => 'dir']
                        )
                    );
                }
            }

            return $files;
        } catch (\Exception $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }
    }

    /**
     * Move a file using the native OCI renameObject API
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
        $prefixedSource = $this->client->getPrefixedPath($source);
        $prefixedDestination = $this->client->getPrefixedPath($destination);
        try {
            $success = $this->client->renameObject($prefixedSource, $prefixedDestination);

            if (! $success) {
                throw UnableToMoveFile::because(
                    'operation failed',
                    $source,
                    $destination
                );
            }
        } catch (GuzzleException $exception) {
            // Fall back to copy + delete if rename fails
            try {
                $this->copy($prefixedSource, $prefixedDestination, $config);
                $this->delete($prefixedSource);
            } catch (\Exception $e) {
                throw UnableToMoveFile::fromLocationTo(
                    $source,
                    $destination,
                    $e
                );
            }
        }
    }

    /**
     * Copy a file
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
        $prefixedSource = $this->client->getPrefixedPath($source);
        $prefixedDestination = $this->client->getPrefixedPath($destination);
        $uri = sprintf('%s/actions/copyObject', $this->client->getBucketUri());

        // Extract any custom storage tier or content-type from config
        $destinationStorageTier = $config->get('storage_tier', $this->client->getStorageTier()->value());
        $contentType = $config->get('content_type');

        $body = json_encode([
            'sourceObjectName' => $prefixedSource,
            'destinationRegion' => $this->client->getRegion(),
            'destinationNamespace' => $this->client->getNamespace(),
            'destinationBucket' => $this->client->getBucket(),
            'destinationObjectName' => $prefixedDestination,
            'destinationStorageTier' => $destinationStorageTier,
            // Include metadata directives and content-type if specified
            ...($contentType ? ['contentType' => $contentType] : []),
            ...($config->get('metadata') ? ['metadata' => $config->get('metadata')] : []),
        ]);

        try {
            $response = $this->client->send($uri, 'POST', [], $body);

            if ($response->getStatusCode() !== 200) {
                throw UnableToCopyFile::fromLocationTo(
                    $source,
                    $destination,
                    new RuntimeException("HTTP {$response->getStatusCode()}")
                );
            }
        } catch (GuzzleException $exception) {
            throw UnableToCopyFile::fromLocationTo(
                $source,
                $destination,
                $exception
            );
        }
    }

    /**
     * Restore objects from Archive storage tier
     *
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/RestoreObjects
     *
     * @param  string  $path  Path to restore
     * @param  int  $hours  Number of hours to make the restored objects available (10-240000 hours)
     * @return bool True if restore request was successful
     */
    public function restore(string $path, int $hours = 24): bool
    {
        $prefixedPath = $this->client->getPrefixedPath($path);

        return $this->client->restoreObjects([$prefixedPath], $hours);
    }

    /**
     * Update storage tier for an object
     *
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/UpdateObjectStorageTier
     *
     * @param  string  $path  Object path
     * @param  string|StorageTier  $storageTier  Storage tier to change to
     * @return bool True if successful
     */
    public function updateStorageTier(string $path, string|StorageTier $storageTier): bool
    {
        $prefixedPath = $this->client->getPrefixedPath($path);
        if (is_string($storageTier)) {
            $storageTier = StorageTier::fromString($storageTier);
        }

        return $this->client->updateObjectStorageTier($prefixedPath, $storageTier);
    }

    /**
     * Get a public URL for a file
     *
     * @param  string  $path  File path
     * @return string Public URL
     */
    public function getUrl($path): string
    {
        // https://objectstorage.{reg}.oraclecloud.com/n/{namespace}/b/{bucket}/o/{path}
        $namespace = $this->client->getNamespace();
        $bucket = $this->client->getBucket();
        $prefixedPath = $this->client->getPrefixedPath($path);

        return "https://objectstorage.{$this->client->getRegion()}.oraclecloud.com/n/{$namespace}/b/{$bucket}/o/{$prefixedPath}";
    }

    /**
     * Create a temporary URL for a file
     *
     * @param  string  $path  File path
     * @param  Carbon|null  $expiresAt  Expiration time (defaults to 1 hour from now)
     * @return string Temporary URL or empty string if creation failed
     */
    public function createTemporaryUrl(string $path, ?Carbon $expiresAt = null): string
    {
        return $this->client->createTemporaryUrl($path, $expiresAt);
    }
}
