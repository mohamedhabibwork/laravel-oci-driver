<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Commands\LaravelOciDriverCommand;
use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\OciClient;

/**
 * Set up an actual OCI disk for testing
 */
function setupOciDiskForTesting(): void
{
    // Create a temporary key file for testing if it doesn't exist
    if (! file_exists(__DIR__.'/test-key.pem')) {
        $privateKey = <<<'EOD'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj
MzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu
NMoSfm76oqFvAp8Gy0iz5sxjZmSnXyCdPEovGhLa0VzMaQ8s+CLOyS56YyCFGeJZ
-----END PRIVATE KEY-----
EOD;
        file_put_contents(__DIR__.'/test-key.pem', $privateKey);
    }

    // Configure Storage facade to use our test adapter
    $client = OciClient::createWithConfiguration(Config::get('filesystems.disks.oci'));
    $adapter = new OciAdapter($client);

    // Create filesystem instance with our adapter
    $filesystem = new \Illuminate\Filesystem\FilesystemAdapter(
        new \League\Flysystem\Filesystem($adapter),
        $adapter,
        Config::get('filesystems.disks.oci')
    );

    // Register a custom storage driver for testing
    Storage::extend('oci', fn () => $filesystem);
}

/**
 * Clean up after tests
 */
afterEach(function () {
    if (file_exists(__DIR__.'/test-key.pem')) {
        unlink(__DIR__.'/test-key.pem');
    }
});

it('shows error message when oci disk is not configured', function () {
    // Ensure we completely remove the oci disk configuration
    Config::set('filesystems.disks', []);

    // Run the command
    $this->artisan(LaravelOciDriverCommand::class)
        ->expectsOutput('Laravel OCI Driver Status Check')
        ->expectsOutput("The 'oci' disk is not configured in your filesystems.php config file.")
        ->assertExitCode(1);
});

it('shows configuration details when oci disk is properly configured', function () {
    // Set up test configuration
    setupOciDiskForTesting();

    // Call the command
    $command = $this->artisan(LaravelOciDriverCommand::class);

    // Verify the command displays the configuration details
    $command->expectsOutput('Laravel OCI Driver Status Check');

    // Verify the table output using the actual test configuration
    $config = Config::get('filesystems.disks.oci');
    $command->expectsTable(
        ['Setting', 'Value'],
        [
            ['Namespace', $config['namespace']],
            ['Region', $config['region']],
            ['Bucket', $config['bucket']],
            ['Storage Tier', $config['storage_tier']],
        ]
    );
});
