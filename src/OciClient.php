<?php

namespace LaravelOCI\LaravelOciDriver;

use Carbon\Carbon;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Exception\SignerValidateException;
use LaravelOCI\LaravelOciDriver\Exception\SigningValidationFailedException;
use LaravelOCI\LaravelOciDriver\Support\HttpClientFactory;
use LaravelOCI\LaravelOciDriver\Support\ObjectNameEncoder;
use LaravelOCI\LaravelOciDriver\Support\ResponseGuard;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;

final readonly class OciClient
{
    /** Object fields requested on listings. */
    public const LIST_FIELDS = 'name,size,timeCreated,timeModified,md5,storageTier';

    /**
     * OCI client configuration.
     */
    private function __construct(
        private array $config,
        private ClientInterface $http,
        private Signer $signer,
    ) {}

    /**
     * Create a new instance with the given configuration (array or OciConfig).
     *
     * @param  array<string, mixed>|OciConfig  $config
     */
    public static function createWithConfiguration(array|OciConfig $config, ?ClientInterface $http = null): self
    {
        if ($config instanceof OciConfig) {
            $configArray = $config->toArray();
        } else {
            $configArray = $config;
        }
        self::validateConfiguration($configArray);

        return new self(
            $configArray,
            $http ?? HttpClientFactory::make($configArray),
            Signer::fromConfig($configArray),
        );
    }

    /**
     * Create a new instance from an OciConfig instance directly.
     */
    public static function fromOciConfig(OciConfig $ociConfig, ?ClientInterface $http = null): self
    {
        return self::createWithConfiguration($ociConfig, $http);
    }

    /**
     * Validate the configuration array.
     *
     * @throws \InvalidArgumentException When configuration is invalid
     */
    private static function validateConfiguration(array $config): void
    {
        $missingKeys = array_values(array_filter(
            ['namespace', 'region', 'bucket', 'tenancy_id', 'user_id', 'key_fingerprint'],
            static fn (string $key): bool => ! isset($config[$key]) || $config[$key] === '',
        ));

        $hasKey = false;
        foreach (['private_key', 'private_key_base64', 'key_path'] as $key) {
            $hasKey = $hasKey || (isset($config[$key]) && $config[$key] !== '');
        }
        if (! $hasKey) {
            $missingKeys[] = 'private_key|private_key_base64|key_path';
        }

        if (! empty($missingKeys)) {
            throw new \InvalidArgumentException(
                sprintf('Missing required configuration keys: %s', implode(', ', $missingKeys))
            );
        }
    }

    /**
     * Get the bucket name.
     */
    public function getBucket(): string
    {
        return $this->config['bucket'];
    }

    /**
     * Get the full bucket URI for API requests.
     */
    public function getBucketUri(): string
    {
        return sprintf(
            '%s/n/%s/b/%s',
            $this->getHost(),
            $this->getNamespace(),
            $this->getBucket(),
        );
    }

    /**
     * Send a signed request to the OCI API.
     *
     * @param  array<string, string>  $header
     * @param  array<string, mixed>  $options  Extra Guzzle request options (e.g. ['stream' => true])
     *
     * @throws GuzzleException
     */
    public function send(
        string $uri,
        string $method,
        array $header = [],
        StreamInterface|string|null $body = null,
        ?string $contentType = 'application/json',
        array $options = []
    ): ResponseInterface {
        $excludeBodyHeaders = false;
        if ($body instanceof StreamInterface) {
            if (strtoupper($method) === 'PUT') {
                $signedBody = null;
                $excludeBodyHeaders = true;
            } else {
                $signedBody = (string) $body;
                if ($body->isSeekable()) {
                    $body->rewind();
                }
            }
        } else {
            $signedBody = $body;
        }

        $authorizationHeaders = $this->getAuthorizationHeaders($uri, $method, $signedBody, $contentType, $excludeBodyHeaders);

        $request = new Request($method, $uri, array_merge($header, $authorizationHeaders), $body);

        return $this->http->send($request, $options);
    }

    /**
     * Helper to apply the configured URL path prefix to an object path.
     */
    private function applyPathPrefix(string $path): string
    {
        $prefix = $this->getPrefix();
        $path = ltrim($path, '/');

        if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
            return $path;
        }

        // An empty path addresses the prefix itself.
        return $path === '' ? $prefix : $prefix.'/'.$path;
    }

    /**
     * Build the object URI for an already-prefixed object name.
     */
    public function getObjectUri(string $objectName): string
    {
        return $this->getBucketUri().'/o/'.ObjectNameEncoder::encode($objectName);
    }

    /**
     * Get the configured prefix.
     */
    public function getPrefix(): string
    {
        $prefix = $this->config['url_path_prefix'] ?? '';

        // Normalize prefix: remove leading/trailing slashes
        $prefix = trim($prefix, '/');

        return $prefix;
    }

    /**
     * Check if prefix is enabled and configured.
     */
    public function isPrefixEnabled(): bool
    {
        return $this->getPrefix() !== '';
    }

    /**
     * Create a temporary URL for a file.
     */
    public function createTemporaryUrl(string $path, ?Carbon $expiresAt = null): string
    {
        $uri = sprintf('%s/p/', $this->getBucketUri());
        $expiresAt ??= now()->addHour();

        $bodyData = [
            'accessType' => 'ObjectRead',
            'name' => Uuid::uuid7()->toString(),
            'objectName' => $this->applyPathPrefix($path),
            'timeExpires' => $expiresAt->toIso8601String(),
        ];

        $body = json_encode($bodyData);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode request body to JSON');
        }

        try {
            $response = $this->send($uri, 'POST', [], $body);

            if ($response->getStatusCode() === 200) {
                $preAuthenticatedRequest = json_decode($response->getBody()->getContents());

                return $preAuthenticatedRequest->fullPath;
            }
        } catch (GuzzleException $exception) {
            Log::error('Failed to create temporary URL', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
        }

        return '';
    }

    /**
     * Get the key fingerprint.
     */
    public function getFingerprint(): string
    {
        return $this->config['key_fingerprint'];
    }

    /**
     * Get the host for the OCI region.
     */
    public function getHost(): string
    {
        return sprintf('https://objectstorage.%s.oraclecloud.com', $this->getRegion());
    }

    /**
     * Get the namespace.
     */
    public function getNamespace(): string
    {
        return $this->config['namespace'];
    }

    /**
     * Get the private key path.
     */
    public function getPrivateKey(): string
    {
        return $this->config['key_path'];
    }

    /**
     * Get the region.
     */
    public function getRegion(): string
    {
        return $this->config['region'];
    }

    /**
     * Get the authorization headers for a request.
     *
     * @throws PrivateKeyFileNotFoundException
     * @throws SignerValidateException
     * @throws SigningValidationFailedException
     */
    public function getAuthorizationHeaders(
        string $uri,
        string $method,
        ?string $body = null,
        string $contentType = 'application/json',
        bool $excludeBodyHeaders = false
    ): array {
        $headers = [];
        $strings = $this->signer->getHeaders($uri, $method, $body, $contentType, null, $excludeBodyHeaders);

        foreach ($strings as $item) {
            [$name, $value] = explode(': ', $item, 2);
            $headers[ucfirst($name)] = trim($value);
        }

        return $headers;
    }

    /**
     * Get the configuration as an OciConfig.
     */
    public function getOciConfig(): OciConfig
    {
        return new OciConfig($this->config);
    }

    /**
     * Get the storage tier.
     */
    public function getStorageTier(): StorageTier
    {
        return StorageTier::fromString($this->config['storage_tier'] ?? 'Standard');
    }

    /**
     * Get the tenancy ID.
     */
    public function getTenancy(): string
    {
        return $this->config['tenancy_id'];
    }

    /**
     * Get the user ID.
     */
    public function getUser(): string
    {
        return $this->config['user_id'];
    }

    /**
     * Build a signed DELETE request for an already-prefixed object name.
     */
    public function deleteObjectRequest(string $prefixedName): RequestInterface
    {
        $uri = $this->getObjectUri($prefixedName);

        return new Request('DELETE', $uri, $this->getAuthorizationHeaders($uri, 'DELETE'));
    }

    /**
     * Delete many objects concurrently with native single-object DELETEs.
     *
     * A 404 counts as deleted. Paths are run through the configured prefix rule.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/DeleteObject
     *
     * @param  array<string>  $paths  List of object paths to delete
     * @return array{deleted: list<string>, errors: list<array{path: string, error: string}>}
     */
    public function bulkDelete(array $paths): array
    {
        $paths = array_values($paths);
        if ($paths === []) {
            return ['deleted' => [], 'errors' => []];
        }

        $requests = function () use ($paths): \Generator {
            foreach ($paths as $path) {
                yield $this->deleteObjectRequest($this->applyPathPrefix($path));
            }
        };

        /** @var array<int, string|null> $failures index => error message, null when deleted */
        $failures = [];

        (new Pool($this->http, $requests(), [
            'concurrency' => max(1, (int) $this->getOciConfig()->get('delete_concurrency', 10)),
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$failures): void {
                $status = $response->getStatusCode();
                $failures[$index] = in_array($status, [200, 204, 404], true)
                    ? null
                    : OciRequestFailed::fromResponse($response, 'delete')->getMessage();
            },
            'rejected' => function (mixed $reason, int $index) use (&$failures): void {
                $failures[$index] = $reason instanceof \Throwable ? $reason->getMessage() : 'delete failed';
            },
        ]))->promise()->wait();

        $deleted = [];
        $errors = [];
        foreach ($paths as $index => $path) {
            $error = array_key_exists($index, $failures) ? $failures[$index] : 'no response received';
            if ($error === null) {
                $deleted[] = $path;
            } else {
                $errors[] = ['path' => $path, 'error' => $error];
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Rename (move) an object in the bucket.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/RenameObject
     *
     * @param  string  $sourcePath  Source object path
     * @param  string  $destinationPath  Destination object path
     * @return bool True if successful, false otherwise (including transport errors)
     */
    public function renameObject(string $sourcePath, string $destinationPath): bool
    {
        try {
            $response = $this->send(
                $this->renameObjectUri(),
                'POST',
                [],
                $this->renameObjectBody($sourcePath, $destinationPath),
            );

            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * URI of the renameObject action.
     *
     * @internal
     */
    public function renameObjectUri(): string
    {
        return sprintf('%s/actions/renameObject', $this->getBucketUri());
    }

    /**
     * JSON body of a renameObject request (names are prefixed idempotently).
     *
     * @internal
     */
    public function renameObjectBody(string $sourcePath, string $destinationPath): string
    {
        return json_encode([
            'sourceName' => $this->applyPathPrefix($sourcePath),
            'newName' => $this->applyPathPrefix($destinationPath),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Restore archived objects to a storage tier.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/RestoreObjects
     *
     * @param  array<string>  $paths  List of object paths to restore
     * @param  int  $hours  Number of hours to make the restored objects available (10-240000 hours)
     * @return bool True if the restoration request was successful
     *
     * @throws GuzzleException
     * @throws \InvalidArgumentException When hours is out of range
     */
    public function restoreObjects(array $paths, int $hours = 24): bool
    {
        if ($hours < 10 || $hours > 240000) {
            throw new \InvalidArgumentException('Hours must be between 10 and 240000');
        }

        if (empty($paths)) {
            return true; // Nothing to restore
        }

        $uri = sprintf('%s/actions/restoreObjects', $this->getBucketUri());

        $bodyData = [
            'objectNames' => array_map(fn ($p) => $this->applyPathPrefix($p), $paths),
            'hours' => $hours,
        ];

        $body = json_encode($bodyData);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode request body to JSON');
        }

        try {
            $response = $this->send($uri, 'POST', [], $body);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $exception) {
            return false;
        }
    }

    /**
     * Update the storage tier of an object.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/UpdateObjectStorageTier
     *
     * @param  string  $path  Object path
     * @param  StorageTier  $storageTier  New storage tier
     * @return bool True if successful, false otherwise
     *
     * @throws GuzzleException
     */
    public function updateObjectStorageTier(string $path, StorageTier $storageTier): bool
    {
        $uri = sprintf('%s/o/%s', $this->getBucketUri(), rawurlencode($this->applyPathPrefix($path)));

        try {
            $response = $this->send(
                $uri,
                'POST',
                ['Storage-Tier' => $storageTier->value()],
                null
            );

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $exception) {
            return false;
        }
    }

    /**
     * Get detailed information about an object.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/HeadObject
     *
     * @param  string  $path  Object path
     * @return array<string, mixed>|null Object metadata or null if not found
     *
     * @throws GuzzleException
     */
    public function getObjectInfo(string $path): ?array
    {
        $uri = sprintf('%s/o/%s', $this->getBucketUri(), rawurlencode($this->applyPathPrefix($path)));

        try {
            $response = $this->send($uri, 'HEAD');

            if ($response->getStatusCode() === 200) {
                $metadata = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $metadata[$name] = $values[0];
                }

                return [
                    'size' => (int) ($metadata['Content-Length'] ?? 0),
                    'last_modified' => $metadata['Last-Modified'] ?? null,
                    'content_type' => $metadata['Content-Type'] ?? null,
                    'storage_tier' => $metadata['Storage-Tier'] ?? null,
                    'etag' => $metadata['ETag'] ?? null,
                    'metadata' => array_filter($metadata, fn ($key) => str_starts_with($key, 'x-amz-meta-'), ARRAY_FILTER_USE_KEY),
                ];
            }

            return null;
        } catch (GuzzleException $exception) {
            if ($exception->getCode() === 404) {
                return null; // Object not found
            }
            throw $exception;
        }
    }

    /**
     * List objects in the bucket with advanced options.
     *
     * Returns the decoded OCI page (`objects`, `prefixes`, `nextStartWith`). The `prefix`
     * option is run through the configured prefix rule.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/ListObjects
     *
     * @param  array<string, mixed>  $options  Listing options
     * @return array<string, mixed> List of objects and prefixes
     *
     * @throws OciRequestFailed
     * @throws GuzzleException
     */
    public function listObjects(array $options = []): array
    {
        $uri = sprintf('%s/o', $this->getBucketUri());

        // Valid options: prefix, delimiter, start, end, limit
        $queryParams = array_filter([
            'prefix' => isset($options['prefix']) ? $this->applyPathPrefix($options['prefix']) : null,
            'delimiter' => $options['delimiter'] ?? null,
            'start' => $options['start'] ?? null,
            'end' => $options['end'] ?? null,
            'limit' => $options['limit'] ?? null,
        ]);

        if (! empty($queryParams)) {
            $uri .= '?'.http_build_query($queryParams);
        }

        $response = ResponseGuard::ok($this->send($uri, 'GET'), 'list');

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /**
     * Fetch one page of a listing. The prefix is used as given (already includes any configured prefix).
     *
     * @return array{objects: list<array<string, mixed>>, prefixes: list<string>, nextStartWith: ?string}
     *
     * @throws OciRequestFailed
     * @throws GuzzleException
     */
    public function listObjectsPage(string $fullPrefix, ?string $delimiter, ?string $start, int $limit = 1000): array
    {
        $query = array_filter([
            'prefix' => $fullPrefix,
            'delimiter' => $delimiter,
            'start' => $start,
            'limit' => $limit,
            'fields' => self::LIST_FIELDS,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = ResponseGuard::ok(
            $this->send(sprintf('%s/o?%s', $this->getBucketUri(), http_build_query($query)), 'GET'),
            'list',
        );

        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];

        return [
            'objects' => array_values($data['objects'] ?? []),
            'prefixes' => array_values($data['prefixes'] ?? []),
            'nextStartWith' => isset($data['nextStartWith']) && $data['nextStartWith'] !== '' ? (string) $data['nextStartWith'] : null,
        ];
    }

    /**
     * Public method for testing: get the fully-prefixed object path.
     */
    public function getPrefixedPath(string $path): string
    {
        return $this->applyPathPrefix($path);
    }

    /**
     * Remove prefix from a path (useful for displaying user-friendly paths).
     */
    public function removePrefixFromPath(string $prefixedPath): string
    {
        $prefix = $this->getPrefix();

        if ($prefix === '' || ! str_starts_with($prefixedPath, $prefix.'/')) {
            return $prefixedPath;
        }

        return substr($prefixedPath, strlen($prefix) + 1);
    }

    /**
     * Get all objects under the configured prefix.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listPrefixedObjects(array $options = []): array
    {
        $prefix = $this->getPrefix();

        // If prefix is configured, add it to the options
        if ($prefix !== '') {
            $options['prefix'] = isset($options['prefix'])
                ? $prefix.'/'.ltrim($options['prefix'], '/')
                : $prefix.'/';
        }

        return $this->listObjects($options);
    }
}
