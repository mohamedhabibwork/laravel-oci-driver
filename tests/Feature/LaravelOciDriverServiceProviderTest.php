<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;
use League\Flysystem\Filesystem;

final class LaravelOciDriverServiceProviderTest extends TestCase
{
    public function test_disk_initialization_registers_temporary_urls(): void
    {
        $disk = Storage::disk('oci');

        expect($disk)->toBeInstanceOf(FilesystemAdapter::class);
        expect($disk->getDriver())->toBeInstanceOf(Filesystem::class);
        expect($disk->getAdapter())->toBeInstanceOf(OciAdapter::class);
        expect($disk->providesTemporaryUrls())->toBeTrue();
    }

    public function test_disk_initialization_failure_logs_sanitized_configuration(): void
    {
        $config = config('filesystems.disks.oci');
        unset($config['bucket']);
        config()->set('filesystems.disks.oci', $config);

        $sanitizedConfig = $config;
        foreach (['key_path', 'key_fingerprint', 'tenancy_id', 'user_id'] as $key) {
            $sanitizedConfig[$key] = '***REDACTED***';
        }

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to create OCI storage driver', [
                'error' => 'Missing required configuration keys: bucket',
                'config' => $sanitizedConfig,
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to initialize OCI storage driver: Missing required configuration keys: bucket');

        Storage::disk('oci');
    }
}
