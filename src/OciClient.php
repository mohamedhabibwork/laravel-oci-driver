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
}
