<?php

namespace LaravelOCI\LaravelOciDriver;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Commands\LaravelOciDriverCommand;
use League\Flysystem\Filesystem;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Laravel Oracle Cloud Infrastructure Storage Driver Service Provider
 */
class LaravelOciDriverServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package using the latest spatie/laravel-package-tools features
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-oci-driver')
            // ->hasConfigFile()
            // ->hasCommand(LaravelOciDriverCommand::class)
            // ->hasInstallCommand(function (InstallCommand $command) {
            //     $command
            //         ->publishConfigFile()
            //         ->askToRunMigrations()
            //         ->askToStarRepoOnGitHub('mohamedhabibwork/laravel-oci-driver')
            //         ->endWith(function (InstallCommand $command) {
            //             $command->line('');
            //             $command->info('Thank you for installing Laravel OCI Driver!');
            //             $command->line('');
            //             $command->line('You can now use the "oci" driver in your filesystem configuration:');
            //             $command->line('');
            //             $command->line('\'disks\' => [');
            //             $command->line('    \'oci\' => [');
            //             $command->line('        \'driver\' => \'oci\',');
            //             $command->line('        \'namespace\' => env(\'OCI_NAMESPACE\'),');
            //             $command->line('        \'region\' => env(\'OCI_REGION\'),');
            //             $command->line('        \'bucket\' => env(\'OCI_BUCKET\'),');
            //             $command->line('        \'tenancy_id\' => env(\'OCI_TENANCY_ID\'),');
            //             $command->line('        \'user_id\' => env(\'OCI_USER_ID\'),');
            //             $command->line('        \'storage_tier\' => env(\'OCI_STORAGE_TIER\', \'Standard\'),');
            //             $command->line('        \'key_fingerprint\' => env(\'OCI_KEY_FINGERPRINT\'),');
            //             $command->line('        \'key_path\' => env(\'OCI_KEY_PATH\'),');
            //             $command->line('    ],');
            //             $command->line('],');
            //             $command->line('');
            //         });
            // })
            ;

        // Register the OCI storage driver
        $this->registerOciDriver();
    }

    /**
     * Register the OCI storage driver with Laravel's storage system
     */
    protected function registerOciDriver(): void
    {
        Storage::extend('oci', function ($app, $config) {
            $client = OciClient::createWithConfiguration($config);
            $adapter = new OciAdapter($client);

            $filesystemAdapter = new FilesystemAdapter(
                driver: new Filesystem(
                    adapter: $adapter,
                ),
                adapter: $adapter,
                config: $config
            );

            $filesystemAdapter->buildTemporaryUrlsUsing(function (
                string $path,
                \DateTimeInterface $expiresAt,
                array $options = []
            ): string {
                $config = [
                    'namespace' => config('filesystems.disks.oci.namespace'),
                    'region' => config('filesystems.disks.oci.region'),
                    'bucket' => config('filesystems.disks.oci.bucket'),
                    'tenancy_id' => config('filesystems.disks.oci.tenancy_id'),
                    'user_id' => config('filesystems.disks.oci.user_id'),
                    'storage_tier' => config('filesystems.disks.oci.storage_tier'),
                    'key_fingerprint' => config('filesystems.disks.oci.key_fingerprint'),
                    'key_path' => config('filesystems.disks.oci.key_path'),
                ];

                $client = OciClient::createWithConfiguration($config);

                return $client->createTemporaryUrl($path, Carbon::instance($expiresAt));
            });

            return $filesystemAdapter;
        });
    }
}
