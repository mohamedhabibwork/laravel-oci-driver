<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\KeyProvider;

use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;

/**
 * File-based key provider for OCI authentication.
 *
 * This provider reads the private key from a file on the filesystem.
 */
final readonly class FileKeyProvider implements KeyProviderInterface
{
    /**
     * Create a new file key provider instance.
     *
     * @param  string  $keyPath  Path to the private key file
     * @param  string  $tenancyId  OCI tenancy ID
     * @param  string  $userId  OCI user ID
     * @param  string  $fingerprint  Key fingerprint
     */
    public function __construct(
        private string $keyPath,
        private string $tenancyId,
        private string $userId,
        private string $fingerprint
    ) {}

    /**
     * Get the private key contents from the file.
     *
     * @return string The private key content
     *
     * @throws PrivateKeyFileNotFoundException When the key file cannot be read
     */
    public function getPrivateKey(): string
    {
        if (! file_exists($this->keyPath)) {
            throw PrivateKeyFileNotFoundException::invalidPath($this->keyPath);
        }

        if (! is_readable($this->keyPath)) {
            throw PrivateKeyFileNotFoundException::inaccessible($this->keyPath);
        }

        $content = file_get_contents($this->keyPath);

        if ($content === false) {
            throw PrivateKeyFileNotFoundException::inaccessible($this->keyPath);
        }

        return $content;
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
        $requiredKeys = ['key_path', 'tenancy_id', 'user_id', 'key_fingerprint'];
        $missingKeys = array_diff($requiredKeys, array_keys($config));

        if (! empty($missingKeys)) {
            throw new \InvalidArgumentException(
                sprintf('Missing required configuration keys: %s', implode(', ', $missingKeys))
            );
        }

        return new self(
            $config['key_path'],
            $config['tenancy_id'],
            $config['user_id'],
            $config['key_fingerprint']
        );
    }

    /**
     * Validate that the private key file is accessible and properly formatted.
     *
     * @return bool True if the key is valid
     *
     * @throws PrivateKeyFileNotFoundException When validation fails
     */
    public function validateKey(): bool
    {
        $privateKey = $this->getPrivateKey();

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
}
