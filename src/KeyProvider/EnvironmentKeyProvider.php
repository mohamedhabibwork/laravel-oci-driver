<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\KeyProvider;

/**
 * Environment-based key provider for OCI authentication.
 *
 * This provider reads the private key content directly from environment variables.
 * Useful for containerized deployments where storing key files is not practical.
 */
final readonly class EnvironmentKeyProvider implements KeyProviderInterface
{
    /**
     * Create a new environment key provider instance.
     *
     * @param  string  $privateKeyContent  The private key content
     * @param  string  $tenancyId  OCI tenancy ID
     * @param  string  $userId  OCI user ID
     * @param  string  $fingerprint  Key fingerprint
     */
    public function __construct(
        private string $privateKeyContent,
        private string $tenancyId,
        private string $userId,
        private string $fingerprint
    ) {}

    /**
     * Get the private key content.
     *
     * @return string The private key content
     */
    public function getPrivateKey(): string
    {
        return $this->privateKeyContent;
    }

    /**
     * Get the key ID for OCI authentication.
     *
     * @return string The key ID in the format: tenancy/user/fingerprint
     */
    public function getKeyId(): string
    {
        return sprintf('%s/%s/%s', $this->tenancyId, $this->userId, $this->fingerprint);
    }

    /**
     * Create a new instance from configuration array.
     *
     * @param  array<string, mixed>  $config  Configuration array
     *
     * @throws \InvalidArgumentException When required configuration is missing
     */
    public static function fromConfig(array $config): static
    {
        $requiredKeys = ['private_key_content', 'tenancy_id', 'user_id', 'key_fingerprint'];
        $missingKeys = array_diff($requiredKeys, array_keys($config));

        if (! empty($missingKeys)) {
            throw new \InvalidArgumentException(
                sprintf('Missing required configuration keys: %s', implode(', ', $missingKeys))
            );
        }

        return new self(
            $config['private_key_content'],
            $config['tenancy_id'],
            $config['user_id'],
            $config['key_fingerprint']
        );
    }

    /**
     * Validate that the private key content is properly formatted.
     *
     * @return bool True if the key is valid
     *
     * @throws \InvalidArgumentException When validation fails
     */
    public function validateKey(): bool
    {
        $privateKey = $this->getPrivateKey();

        if (empty($privateKey)) {
            throw new \InvalidArgumentException('Private key content is empty');
        }

        // Basic validation - check if it looks like a PEM key
        if (! str_contains($privateKey, '-----BEGIN') || ! str_contains($privateKey, '-----END')) {
            throw new \InvalidArgumentException('Private key does not appear to be in PEM format');
        }

        // Try to load the key with OpenSSL
        $keyResource = openssl_pkey_get_private($privateKey);
        if ($keyResource === false) {
            throw new \InvalidArgumentException('Private key is not valid or cannot be parsed by OpenSSL');
        }

        return true;
    }

    /**
     * Create an instance with base64-decoded private key content.
     *
     * Useful when the private key is stored as base64 in environment variables
     * to avoid issues with newlines and special characters.
     *
     * @param  string  $base64PrivateKey  Base64-encoded private key
     * @param  string  $tenancyId  OCI tenancy ID
     * @param  string  $userId  OCI user ID
     * @param  string  $fingerprint  Key fingerprint
     *
     * @throws \InvalidArgumentException When base64 decoding fails
     */
    public static function fromBase64(
        string $base64PrivateKey,
        string $tenancyId,
        string $userId,
        string $fingerprint
    ): static {
        $privateKeyContent = base64_decode($base64PrivateKey, true);

        if ($privateKeyContent === false) {
            throw new \InvalidArgumentException('Invalid base64-encoded private key');
        }

        return new static($privateKeyContent, $tenancyId, $userId, $fingerprint);
    }
}
