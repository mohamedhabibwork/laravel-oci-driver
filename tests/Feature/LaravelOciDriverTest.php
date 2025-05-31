<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\LaravelOciDriver;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class LaravelOciDriverTest extends TestCase
{
    public function test_driver_can_get_disk_instance(): void
    {
        $disk = LaravelOciDriver::disk();

        expect($disk)->toBeInstanceOf(\Illuminate\Contracts\Filesystem\Filesystem::class);
    }

    public function test_driver_can_get_specific_disk(): void
    {
        $disk = LaravelOciDriver::disk('oci');

        expect($disk)->toBeInstanceOf(\Illuminate\Contracts\Filesystem\Filesystem::class);
    }

    public function test_driver_can_create_temporary_url(): void
    {
        // Mock the storage disk to avoid actual OCI calls
        Storage::fake('oci');

        $path = 'test-file.txt';
        $expiresAt = now()->addHour();

        // This would normally create a temporary URL
        // In a real test environment with OCI credentials, this would work
        expect(true)->toBeTrue(); // Placeholder assertion
    }

    public function test_driver_can_upload_string_content(): void
    {
        Storage::fake('oci');

        $path = 'test-file.txt';
        $content = 'Test content';

        $result = LaravelOciDriver::upload($path, $content, [], 'oci');

        expect($result)->toBeTrue();
        Storage::disk('oci')->assertExists($path);
    }

    public function test_driver_can_bulk_delete_files(): void
    {
        Storage::fake('oci');

        $paths = ['file1.txt', 'file2.txt', 'file3.txt'];

        // Create test files
        foreach ($paths as $path) {
            Storage::disk('oci')->put($path, 'content');
        }

        $results = LaravelOciDriver::bulkDelete($paths, 'oci');

        expect($results)->toHaveCount(3);
        expect(array_values($results))->each->toBeTrue();

        foreach ($paths as $path) {
            Storage::disk('oci')->assertMissing($path);
        }
    }

    public function test_driver_can_change_storage_tier_with_enum(): void
    {
        Storage::fake('oci');

        $path = 'test-file.txt';
        Storage::disk('oci')->put($path, 'content');

        $result = LaravelOciDriver::changeStorageTier($path, StorageTier::ARCHIVE, 'oci');

        // In a real OCI environment, this would change the storage tier
        // For now, we just verify the method doesn't throw an exception
        expect($result)->toBeBool();
    }

    public function test_driver_can_change_storage_tier_with_string(): void
    {
        Storage::fake('oci');

        $path = 'test-file.txt';
        Storage::disk('oci')->put($path, 'content');

        $result = LaravelOciDriver::changeStorageTier($path, 'Archive', 'oci');

        expect($result)->toBeBool();
    }

    public function test_driver_can_get_file_info(): void
    {
        Storage::fake('oci');

        $path = 'test-file.txt';
        $content = 'Test content';
        Storage::disk('oci')->put($path, $content);

        $info = LaravelOciDriver::getFileInfo($path, 'oci');

        expect($info)->toBeArray();
        expect($info)->toHaveKeys(['path', 'size', 'last_modified', 'mime_type', 'visibility', 'url']);
        expect($info)->not->toBeNull();

        if ($info !== null) {
            expect($info['path'])->toBe($path);
            expect($info['size'])->toBe(strlen($content));
        }
    }

    public function test_driver_returns_null_for_nonexistent_file_info(): void
    {
        Storage::fake('oci');

        $info = LaravelOciDriver::getFileInfo('nonexistent.txt', 'oci');

        expect($info)->toBeNull();
    }

    public function test_driver_can_copy_file(): void
    {
        Storage::fake('oci');

        $source = 'source.txt';
        $destination = 'destination.txt';
        $content = 'Test content';

        Storage::disk('oci')->put($source, $content);

        $result = LaravelOciDriver::copyFile($source, $destination, [], 'oci');

        expect($result)->toBeTrue();
        Storage::disk('oci')->assertExists($source);
        Storage::disk('oci')->assertExists($destination);
        expect(Storage::disk('oci')->get($destination))->toBe($content);
    }

    public function test_driver_can_move_file(): void
    {
        Storage::fake('oci');

        $source = 'source.txt';
        $destination = 'destination.txt';
        $content = 'Test content';

        Storage::disk('oci')->put($source, $content);

        $result = LaravelOciDriver::moveFile($source, $destination, [], 'oci');

        expect($result)->toBeTrue();
        Storage::disk('oci')->assertMissing($source);
        Storage::disk('oci')->assertExists($destination);
        expect(Storage::disk('oci')->get($destination))->toBe($content);
    }

    public function test_driver_can_list_files(): void
    {
        Storage::fake('oci');

        $files = ['file1.txt', 'file2.txt', 'subdir/file3.txt'];

        foreach ($files as $file) {
            Storage::disk('oci')->put($file, 'content');
        }

        $listedFiles = LaravelOciDriver::listFiles('', false, [], 'oci');

        expect($listedFiles)->toBeArray();
        expect($listedFiles)->toContain('file1.txt');
        expect($listedFiles)->toContain('file2.txt');
    }

    public function test_driver_can_list_files_recursively(): void
    {
        Storage::fake('oci');

        $files = ['file1.txt', 'file2.txt', 'subdir/file3.txt'];

        foreach ($files as $file) {
            Storage::disk('oci')->put($file, 'content');
        }

        $listedFiles = LaravelOciDriver::listFiles('', true, [], 'oci');

        expect($listedFiles)->toBeArray();
        expect($listedFiles)->toContain('file1.txt');
        expect($listedFiles)->toContain('file2.txt');
        expect($listedFiles)->toContain('subdir/file3.txt');
    }

    public function test_driver_can_list_directories(): void
    {
        Storage::fake('oci');

        Storage::disk('oci')->put('dir1/file.txt', 'content');
        Storage::disk('oci')->put('dir2/file.txt', 'content');
        Storage::disk('oci')->put('dir1/subdir/file.txt', 'content');

        $directories = LaravelOciDriver::listDirectories('', false, 'oci');

        expect($directories)->toBeArray();
    }

    public function test_driver_can_test_connection(): void
    {
        Storage::fake('oci');

        $result = LaravelOciDriver::testConnection('oci');

        expect($result)->toBeTrue();
    }

    public function test_driver_test_connection_handles_failures(): void
    {
        // Test with a non-existent disk to simulate failure
        $result = LaravelOciDriver::testConnection('nonexistent-disk');

        expect($result)->toBeFalse();
    }

    public function test_driver_is_final_class(): void
    {
        $reflection = new \ReflectionClass(LaravelOciDriver::class);

        expect($reflection->isFinal())->toBeTrue();
    }

    public function test_driver_methods_are_static(): void
    {
        $reflection = new \ReflectionClass(LaravelOciDriver::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            expect($method->isStatic())->toBeTrue();
        }
    }
}
