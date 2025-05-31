<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Commands\LaravelOciDriverCommand;
use LaravelOCI\LaravelOciDriver\Commands\OciConfigCommand;
use LaravelOCI\LaravelOciDriver\Commands\OciConnectionCommand;
use LaravelOCI\LaravelOciDriver\Commands\OciSetupCommand;
use League\Flysystem\Filesystem;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Laravel Oracle Cloud Infrastructure Storage Driver Service Provider
 *
 * This service provider registers the OCI storage driver with Laravel's filesystem
 * and provides configuration publishing and command registration.
 */
final class LaravelOciDriverServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package using the latest spatie/laravel-package-tools features
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-oci-driver')
            ->hasConfigFile()
            ->hasCommands([
                LaravelOciDriverCommand::class,
                OciConfigCommand::class,
                OciConnectionCommand::class,
                OciSetupCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('mohamedhabibwork/laravel-oci-driver');
            });
    }

    /**
     * Register services in the container.
     */
    public function register(): void
    {
        parent::register();

        // Register the OCI storage driver
        $this->registerOciDriver();

        // Bind the main driver class
        $this->app->singleton(LaravelOciDriver::class, function () {
            return new LaravelOciDriver;
        });
    }

    /**
     * Bootstrap services after all providers have been registered.
     */
    public function boot(): void
    {
        parent::boot();

        // Additional boot logic can be added here
        $this->bootHealthChecks();
    }

    /**
     * Register the OCI storage driver with Laravel's storage system
     */
    protected function registerOciDriver(): void
    {
        Storage::extend('oci', function ($app, $config) {
            // Validate configuration before creating client
            $this->validateOciConfiguration($config);

            try {
                $client = OciClient::createWithConfiguration($config);
                $adapter = new OciAdapter($client);

                $filesystemAdapter = new FilesystemAdapter(
                    driver: new Filesystem(
                        adapter: $adapter,
                    ),
                    adapter: $adapter,
                    config: $config
                );

                // Configure temporary URL generation
                $this->configureTemporaryUrls($filesystemAdapter, $config);

                return $filesystemAdapter;
            } catch (\Exception $e) {
                // Log the error and provide a helpful message
                if ($app->bound('log')) {
                    $app['log']->error('Failed to create OCI storage driver', [
                        'error' => $e->getMessage(),
                        'config' => $this->sanitizeConfigForLogging($config),
                    ]);
                }

                throw new \RuntimeException(
                    sprintf('Failed to initialize OCI storage driver: %s', $e->getMessage()),
                    0,
                    $e
                );
            }
        });
    }

    /**
     * Configure temporary URL generation for the filesystem adapter.
     *
     * @param  array<string, mixed>  $config
     */
    protected function configureTemporaryUrls(FilesystemAdapter $adapter, array $config): void
    {
        $adapter->buildTemporaryUrlsUsing(function (
            string $path,
            \DateTimeInterface $expiresAt,
            array $options = []
        ) use ($config): string {
            try {
                // Use the existing config or fall back to the main OCI disk config
                $ociConfig = $config ?: config('filesystems.disks.oci', []);

                // Validate that we have the required configuration
                if (empty($ociConfig)) {
                    throw new \InvalidArgumentException('OCI configuration not found for temporary URL generation');
                }

                $client = OciClient::createWithConfiguration($ociConfig);

                return $client->createTemporaryUrl($path, Carbon::instance($expiresAt));
            } catch (\Exception $e) {
                // Log the error if logging is available
                if (app()->bound('log')) {
                    app('log')->error('Failed to create temporary URL', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Return empty string or throw based on configuration
                if (config('laravel-oci-driver.global.throw_exceptions', true)) {
                    throw $e;
                }

                return '';
            }
        });
    }

    /**
     * Validate OCI configuration before creating the client.
     *
     * @param  array<string, mixed>  $config  Configuration array
     *
     * @throws \InvalidArgumentException When configuration is invalid
     */
    protected function validateOciConfiguration(array $config): void
    {
        $requiredKeys = [
            'namespace', 'region', 'bucket', 'tenancy_id',
            'user_id', 'storage_tier', 'key_fingerprint', 'key_path',
        ];

        $missingKeys = array_diff($requiredKeys, array_keys($config));

        if (! empty($missingKeys)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Missing required OCI configuration keys: %s. Please ensure your .env file contains all required OCI_* variables.',
                    implode(', ', $missingKeys)
                )
            );
        }

        // Validate specific configuration values
        $this->validateConfigurationValues($config);
    }

    /**
     * Validate specific configuration values.
     *
     * @param  array<string, mixed>  $config  Configuration array
     *
     * @throws \InvalidArgumentException When configuration values are invalid
     */
    protected function validateConfigurationValues(array $config): void
    {
        // Skip validation in testing environment
        if (app()->environment('testing')) {
            return;
        }

        // Validate region format
        if (! preg_match('/^[a-z0-9-]+$/', $config['region'])) {
            throw new \InvalidArgumentException('Invalid OCI region format');
        }

        // Validate key fingerprint format
        if (! preg_match('/^[a-f0-9]{2}(:[a-f0-9]{2}){15}$/i', $config['key_fingerprint'])) {
            throw new \InvalidArgumentException('Invalid OCI key fingerprint format');
        }

        // Validate storage tier
        $validTiers = ['Standard', 'InfrequentAccess', 'Archive'];
        if (! in_array($config['storage_tier'], $validTiers, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid storage tier: %s. Valid options: %s',
                    $config['storage_tier'],
                    implode(', ', $validTiers)
                )
            );
        }

        // Validate key path exists if it's a file path
        if (! filter_var($config['key_path'], FILTER_VALIDATE_URL) && ! file_exists($config['key_path'])) {
            throw new \InvalidArgumentException(
                sprintf('OCI private key file not found: %s', $config['key_path'])
            );
        }
    }

    /**
     * Sanitize configuration for logging (remove sensitive data).
     *
     * @param  array<string, mixed>  $config  Configuration array
     * @return array<string, mixed> Sanitized configuration
     */
    protected function sanitizeConfigForLogging(array $config): array
    {
        $sensitiveKeys = ['key_path', 'key_fingerprint', 'tenancy_id', 'user_id'];

        $sanitized = $config;
        foreach ($sensitiveKeys as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '***REDACTED***';
            }
        }

        return $sanitized;
    }

    /**
     * Bootstrap health check functionality.
     */
    protected function bootHealthChecks(): void
    {
        // Register health check if the package is available
        if (class_exists('\Spatie\Health\Facades\Health')) {
            // Health check implementation would go here
            // This is a placeholder for future health check integration
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            LaravelOciDriver::class,
        ];
    }
}
