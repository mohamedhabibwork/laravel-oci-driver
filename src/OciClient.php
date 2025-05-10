<?php

namespace LaravelOCI\LaravelOciDriver;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Exception\SignerValidateException;
use LaravelOCI\LaravelOciDriver\Exception\SigningValidationFailedException;
use Ramsey\Uuid\Uuid;

final readonly class OciClient
{
    /**
     * OCI client configuration.
     */
    private function __construct(
        private array $config,
    ) {}

    /**
     * Create a new instance with the given configuration.
     */
    public static function createWithConfiguration(array $config): self
    {
        $instance = new self($config);
        $instance->validateConfiguration();

        return $instance;
    }

    /**
     * Validate the configuration array.
     *
     * @throws \InvalidArgumentException When configuration is invalid
     */
    private function validateConfiguration(): void
    {
        $requiredKeys = [
            'namespace', 'region', 'bucket', 'tenancy_id',
            'user_id', 'storage_tier', 'key_fingerprint', 'key_path',
        ];

        $missingKeys = array_diff($requiredKeys, array_keys($this->config));

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
     * Send a request to the OCI API.
     *
     * @throws GuzzleException
     */
    public function send(
        string $uri,
        string $method,
        array $header = [],
        ?string $body = null,
        ?string $contentType = 'application/json'
    ) {
        $authorizationHeaders = $this->getAuthorizationHeaders($uri, $method, $body, $contentType);

        $client = new Client([
            RequestOptions::ALLOW_REDIRECTS => false,
        ]);

        $request = new Request($method, $uri, array_merge($header, $authorizationHeaders), $body);

        return $client->send($request);
    }

    /**
     * Create a temporary URL for a file.
     */
    public function createTemporaryUrl(string $path, ?Carbon $expiresAt = null): string
    {
        $uri = sprintf('%s/p/', $this->getBucketUri());
        $expiresAt ??= now()->addHour();

        $body = json_encode([
            'accessType' => 'ObjectRead',
            'name' => Uuid::uuid7()->toString(),
            'objectName' => $path,
            'timeExpires' => $expiresAt->toIso8601String(),
        ]);

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
        string $contentType = 'application/json'
    ): array {
        $headers = [];
        $signer = new Signer($this->getTenancy(), $this->getUser(), $this->getFingerprint(), $this->getPrivateKey());
        $strings = $signer->getHeaders($uri, $method, $body, $contentType);

        foreach ($strings as $item) {
            [$name, $value] = explode(': ', $item, 2);
            $headers[ucfirst($name)] = trim($value);
        }

        return $headers;
    }

    /**
     * Get the storage tier.
     */
    public function getStorageTier(): StorageTier
    {
        return StorageTier::fromString($this->config['storage_tier']);
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
     * Bulk delete objects from the bucket.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/s3objectstorage/20160918/Object/BulkDelete
     *
     * @param  array<string>  $paths  List of object paths to delete
     * @return array{deleted: array<string>, errors: array<array{path: string, error: string}>}
     *
     * @throws GuzzleException
     */
    public function bulkDelete(array $paths): array
    {
        if (empty($paths)) {
            return ['deleted' => [], 'errors' => []];
        }

        // S3 API endpoint for bulk delete
        $uri = sprintf('%s?delete', $this->getBucketUri());

        // Create the XML payload according to S3 API specs
        $xml = new \SimpleXMLElement('<Delete></Delete>');
        $xml->addChild('Quiet', 'true'); // Quiet mode for simpler response

        foreach ($paths as $path) {
            $object = $xml->addChild('Object');
            $object->addChild('Key', $path);
        }

        $body = $xml->asXML();

        try {
            // Calculate content MD5 as required by S3 API
            $contentMD5 = base64_encode(md5($body, true));

            $response = $this->send(
                $uri,
                'POST',
                ['Content-MD5' => $contentMD5],
                $body,
                'application/xml'
            );

            if ($response->getStatusCode() === 200) {
                // Success - all objects deleted
                return ['deleted' => $paths, 'errors' => []];
            }

            // Parse response XML for errors
            $responseBody = $response->getBody()->getContents();
            $responseXml = simplexml_load_string($responseBody);

            $errors = [];
            $deleted = [];

            if (isset($responseXml->Error)) {
                foreach ($responseXml->Error as $error) {
                    $key = (string) $error->Key;
                    $message = (string) $error->Message;
                    $errors[] = ['path' => $key, 'error' => $message];
                }

                // Calculate which paths were successfully deleted
                $errorPaths = array_column($errors, 'path');
                $deleted = array_values(array_diff($paths, $errorPaths));
            }

            return ['deleted' => $deleted, 'errors' => $errors];
        } catch (GuzzleException $exception) {
            // If the request totally failed, report all paths as errors
            $errors = array_map(fn (string $path) => [
                'path' => $path,
                'error' => $exception->getMessage(),
            ], $paths);

            return ['deleted' => [], 'errors' => $errors];
        }
    }

    /**
     * Rename (move) an object in the bucket.
     *
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/RenameObject
     *
     * @param  string  $sourcePath  Source object path
     * @param  string  $destinationPath  Destination object path
     * @return bool True if successful, false otherwise
     *
     * @throws GuzzleException
     */
    public function renameObject(string $sourcePath, string $destinationPath): bool
    {
        $uri = sprintf('%s/actions/renameObject', $this->getBucketUri());

        $body = json_encode([
            'sourceName' => $sourcePath,
            'destinationName' => $destinationPath,
        ]);

        try {
            $response = $this->send($uri, 'POST', [], $body);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $exception) {
            if ($exception->getCode() === 404) {
                return false; // Source object not found
            }
            throw $exception;
        }
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

        $body = json_encode([
            'objectNames' => $paths,
            'hours' => $hours,
        ]);

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
        $uri = sprintf('%s/o/%s', $this->getBucketUri(), urlencode($path));

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
        $uri = sprintf('%s/o/%s', $this->getBucketUri(), urlencode($path));

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
     * @link https://docs.oracle.com/en-us/iaas/api/#/en/objectstorage/20160918/Object/ListObjects
     *
     * @param  array<string, mixed>  $options  Listing options
     * @return array<string, mixed> List of objects and prefixes
     *
     * @throws GuzzleException
     */
    public function listObjects(array $options = []): array
    {
        $uri = sprintf('%s/o', $this->getBucketUri());

        // Valid options: prefix, delimiter, start, end, limit
        $queryParams = array_filter([
            'prefix' => $options['prefix'] ?? null,
            'delimiter' => $options['delimiter'] ?? null,
            'start' => $options['start'] ?? null,
            'end' => $options['end'] ?? null,
            'limit' => $options['limit'] ?? null,
        ]);

        if (! empty($queryParams)) {
            $uri .= '?'.http_build_query($queryParams);
        }

        try {
            $response = $this->send($uri, 'GET');

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true) ?: [];
            }

            return [];
        } catch (GuzzleException $exception) {
            return [];
        }
    }
}
