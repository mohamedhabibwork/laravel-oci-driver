<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Config;

use LaravelOCI\LaravelOciDriver\Enums\ConnectionType;
use LaravelOCI\LaravelOciDriver\Enums\LogLevel;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

/**
 * OCI Configuration Helper
 *
 * Provides a centralized way to access and validate OCI configuration.
 */
final readonly class OciConfig
{
    /**
     * Create a new OCI config instance.
     *
     * @param  array<string, mixed>  $config  Configuration array
     */
    public function __construct(
        private array $config
    ) {}

    /**
     * Create from Laravel configuration.
     *
     * @param  string  $connection  Connection name
     */
    public static function fromConnection(string $connection = 'default'): static
    {
        $config = config("filesystems.disks.{$connection}", config("laravel-oci-driver.connections.{$connection}", []));

        if (empty($config)) {
            throw new \InvalidArgumentException("OCI connection '{$connection}' not found in configuration");
        }

        return new self($config);
    }

    /**
     * Create from disk configuration.
     *
     * @param  string  $disk  Disk name
     */
    public static function fromDisk(string $disk = 'oci'): static
    {
        $config = config("filesystems.disks.{$disk}", []);

        if (empty($config) || ($config['driver'] ?? null) !== 'oci') {
            throw new \InvalidArgumentException("OCI disk '{$disk}' not found or not configured");
        }

        return new self($config);
    }

    /**
     * Get tenancy ID.
     */
    public function getTenancyId(): string
    {
        return $this->getRequired('tenancy_id');
    }

    /**
     * Get user ID.
     */
    public function getUserId(): string
    {
        return $this->getRequired('user_id');
    }

    /**
     * Get key fingerprint.
     */
    public function getKeyFingerprint(): string
    {
        return $this->getRequired('key_fingerprint');
    }

    /**
     * Get private key path.
     */
    public function getKeyPath(): string
    {
        return $this->getRequired('key_path');
    }

    /**
     * Get namespace.
     */
    public function getNamespace(): string
    {
        return $this->getRequired('namespace');
    }

    /**
     * Get region.
     */
    public function getRegion(): string
    {
        return $this->getRequired('region');
    }

    /**
     * Get bucket name.
     */
    public function getBucket(): string
    {
        return $this->getRequired('bucket');
    }

    /**
     * Get storage tier.
     */
    public function getStorageTier(): StorageTier
    {
        $tier = $this->get('storage_tier', 'Standard');

        return StorageTier::fromString($tier);
    }

    /**
     * Get timeout configuration.
     */
    public function getTimeout(): int
    {
        return (int) $this->get('timeout', 30);
    }

    /**
     * Get connect timeout configuration.
     */
    public function getConnectTimeout(): int
    {
        return (int) $this->get('connect_timeout', 10);
    }

    /**
     * Get retry attempts configuration.
     */
    public function getRetryAttempts(): int
    {
        return (int) $this->get('retry_attempts', 3);
    }

    /**
     * Get retry delay configuration.
     */
    public function getRetryDelay(): int
    {
        return (int) $this->get('retry_delay', 1000);
    }

    /**
     * Get temporary URL configuration.
     *
     * @return array<string, mixed>
     */
    public function getTemporaryUrlConfig(): array
    {
        return $this->get('temporary_url', [
            'default_expiry' => 3600,
            'max_expiry' => 86400,
        ]);
    }

    /**
     * Get upload configuration.
     *
     * @return array<string, mixed>
     */
    public function getUploadConfig(): array
    {
        return $this->get('upload', [
            'chunk_size' => 8388608, // 8MB
            'multipart_threshold' => 104857600, // 100MB
            'enable_checksum' => true,
        ]);
    }

    /**
     * Get cache configuration.
     *
     * @return array<string, mixed>
     */
    public function getCacheConfig(): array
    {
        return $this->get('cache', [
            'enabled' => true,
            'ttl' => 300,
            'prefix' => 'oci_driver',
        ]);
    }

    /**
     * Get logging configuration.
     *
     * @return array<string, mixed>
     */
    public function getLoggingConfig(): array
    {
        return $this->get('logging', [
            'enabled' => false,
            'level' => 'info',
            'channel' => 'default',
        ]);
    }

    /**
     * Get logging level as enum.
     */
    public function getLogLevel(): LogLevel
    {
        $level = $this->get('logging.level', 'info');

        return LogLevel::fromString($level);
    }

    /**
     * Check if caching is enabled.
     */
    public function isCacheEnabled(): bool
    {
        return (bool) $this->get('cache.enabled', true);
    }

    /**
     * Check if logging is enabled.
     */
    public function isLoggingEnabled(): bool
    {
        return (bool) $this->get('logging.enabled', false);
    }

    /**
     * Get all configuration as array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Get configuration value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $value = $this->config;

            foreach ($keys as $k) {
                if (! is_array($value) || ! array_key_exists($k, $value)) {
                    return $default;
                }
                $value = $value[$k];
            }

            return $value;
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * Get required configuration value.
     *
     * @throws \InvalidArgumentException When configuration is missing
     */
    private function getRequired(string $key): string
    {
        $value = $this->get($key);

        if (empty($value) || ! is_string($value)) {
            throw new \InvalidArgumentException("Required OCI configuration '{$key}' is missing or empty");
        }

        return $value;
    }

    /**
     * Validate the configuration.
     *
     * @return array<string> List of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        $requiredKeys = [
            'tenancy_id', 'user_id', 'key_fingerprint', 'key_path',
            'namespace', 'region', 'bucket',
        ];

        foreach ($requiredKeys as $key) {
            try {
                $this->getRequired($key);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Validate fingerprint format
        try {
            $fingerprint = $this->getKeyFingerprint();
            if (! preg_match('/^[a-f0-9]{2}(:[a-f0-9]{2}){15}$/i', $fingerprint)) {
                $errors[] = "Invalid key fingerprint format: {$fingerprint}";
            }
        } catch (\InvalidArgumentException) {
            // Already handled above
        }

        // Validate region format
        try {
            $region = $this->getRegion();
            if (! preg_match('/^[a-z0-9-]+$/', $region)) {
                $errors[] = "Invalid region format: {$region}";
            }
        } catch (\InvalidArgumentException) {
            // Already handled above
        }

        // Validate key path exists (skip in testing)
        if (! $this->isTestingEnvironment()) {
            try {
                $keyPath = $this->getKeyPath();
                if (! file_exists($keyPath)) {
                    $errors[] = "Private key file not found: {$keyPath}";
                }
            } catch (\InvalidArgumentException) {
                // Already handled above
            }
        }

        return $errors;
    }

    /**
     * Check if the configuration is valid.
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Get connection type.
     */
    public function getConnectionType(): ConnectionType
    {
        $type = $this->get('connection_type', 'primary');

        return ConnectionType::fromString($type);
    }

    /**
     * Create a new configuration with switched connection.
     */
    public static function switchConnection(string $connection): static
    {
        return self::fromConnection($connection);
    }

    /**
     * Get all available connections.
     *
     * @return array<string>
     */
    public static function getAvailableConnections(): array
    {
        $connections = config('laravel-oci-driver.connections', []);

        return array_map('strval', array_keys($connections));
    }

    /**
     * Check if a connection exists.
     */
    public static function connectionExists(string $connection): bool
    {
        return in_array($connection, self::getAvailableConnections(), true);
    }

    /**
     * Get default connection name.
     */
    public static function getDefaultConnection(): string
    {
        return config('laravel-oci-driver.default', 'default');
    }

    /**
     * Create configuration for multiple connections.
     *
     * @param  array<string>  $connections
     * @return array<string, static>
     */
    public static function forConnections(array $connections): array
    {
        $configs = [];

        foreach ($connections as $connection) {
            try {
                $configs[$connection] = self::fromConnection($connection);
            } catch (\InvalidArgumentException $e) {
                // Skip invalid connections
                continue;
            }
        }

        return $configs;
    }

    /**
     * Get connection configuration summary.
     *
     * @return array<string, mixed>
     */
    public function getConnectionSummary(): array
    {
        return [
            'connection_type' => $this->getConnectionType()->getDisplayName(),
            'region' => $this->getRegion(),
            'namespace' => $this->getNamespace(),
            'bucket' => $this->getBucket(),
            'storage_tier' => $this->getStorageTier()->value,
            'cache_enabled' => $this->isCacheEnabled(),
            'logging_enabled' => $this->isLoggingEnabled(),
            'log_level' => $this->getLogLevel()->value(),
        ];
    }

    /**
     * Clone configuration with overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withOverrides(array $overrides): static
    {
        $newConfig = array_merge($this->config, $overrides);

        return new static($newConfig);
    }

    /**
     * Check if we're in a testing environment.
     */
    private function isTestingEnvironment(): bool
    {
        // Check if Laravel app is available and in testing mode
        if (function_exists('app') && app()->bound('env')) {
            return app()->environment('testing');
        }

        return config('app.env') === 'testing' || defined('PHPUNIT_RUNNING');
    }

    /**
     * Get URL path prefix for all object operations.
     */
    public function getUrlPathPrefix(): string
    {
        $prefix = $this->get('url_path_prefix', '');
        if ($prefix === null) {
            return '';
        }
        // Normalize: remove leading/trailing slashes, but keep empty string if not set
        $prefix = trim($prefix, '/');
        return $prefix !== '' ? $prefix.'/' : '';
    }
}
