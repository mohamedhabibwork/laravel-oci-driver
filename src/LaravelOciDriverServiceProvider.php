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
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
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
     * Register the OCI storage driver with Laravel's storage system
     */
    protected function registerOciDriver(): void
    {
        // Laravel rebinds this callback to the filesystem manager.
        $configureTemporaryUrls = $this->configureTemporaryUrls(...);
        $sanitizeConfigForLogging = $this->sanitizeConfigForLogging(...);

        Storage::extend('oci', function ($app, $config) use ($configureTemporaryUrls, $sanitizeConfigForLogging) {
            try {
                // The filesystem manager does not pass the disk name to custom creators,
                // so use `name` if present, else find the disk by its config, else 'oci'.
                $diskName = $config['name'] ?? array_search($config, (array) $app['config']->get('filesystems.disks', []), true);
                $diskName = is_string($diskName) ? $diskName : 'oci';

                $ociConfig = OciConfig::fromDiskConfig($config, $diskName);
                $ociConfig->assertValid($diskName);

                $client = OciClient::createWithConfiguration($ociConfig);
                $adapter = new OciAdapter($client);

                $filesystemAdapter = new FilesystemAdapter(
                    driver: new Filesystem(
                        adapter: $adapter,
                    ),
                    adapter: $adapter,
                    config: $config
                );

                // Configure temporary URL generation
                $configureTemporaryUrls($filesystemAdapter, $client);

                return $filesystemAdapter;
            } catch (\Exception $e) {
                // Log the error and provide a helpful message
                if ($app->bound('log')) {
                    $app['log']->error('Failed to create OCI storage driver', [
                        'error' => $e->getMessage(),
                        'config' => $sanitizeConfigForLogging($config),
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
     * Reuses the disk's own client, so each disk signs with its own credentials
     * and no client is built per call.
     */
    protected function configureTemporaryUrls(FilesystemAdapter $adapter, OciClient $client): void
    {
        $adapter->buildTemporaryUrlsUsing(function (
            string $path,
            \DateTimeInterface $expiresAt,
            array $options = []
        ) use ($client): string {
            try {
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
     * Sanitize configuration for logging (remove sensitive data).
     *
     * @param  array<string, mixed>  $config  Configuration array
     * @return array<string, mixed> Sanitized configuration
     */
    protected function sanitizeConfigForLogging(array $config): array
    {
        $sensitiveKeys = ['key_path', 'private_key', 'private_key_base64', 'key_fingerprint', 'tenancy_id', 'user_id'];

        $sanitized = $config;
        foreach ($sensitiveKeys as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '***REDACTED***';
            }
        }

        return $sanitized;
    }
}
