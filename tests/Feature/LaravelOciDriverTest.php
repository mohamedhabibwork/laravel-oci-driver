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

        $url = \LaravelOCI\LaravelOciDriver\LaravelOciDriver::temporaryUrl($path, $expiresAt);

        expect($url)->toBeString();
        expect($url)->toContain($path);
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

    public function test_driver_applies_url_path_prefix_to_object_paths(): void
    {
        $prefix = 'my-prefix';
        $config = [
            'tenancy_id' => 'test-tenancy',
            'user_id' => 'test-user',
            'key_fingerprint' => '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00',
            'key_path' => '/dev/null',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'url_path_prefix' => $prefix,
            'storage_tier' => 'Standard',
        ];
        $client = \LaravelOCI\LaravelOciDriver\OciClient::createWithConfiguration($config);
        $filePath = 'test-file.txt';
        $expected = $prefix.'/'.$filePath;
        $actual = $client->getPrefixedPath($filePath);
        expect($actual)->toBe($expected);
    }

    public function test_driver_prefix_functionality_comprehensive(): void
    {
        $testCases = [
            ['prefix' => 'app/uploads', 'path' => 'file.txt', 'expected' => 'app/uploads/file.txt'],
            ['prefix' => 'app/uploads', 'path' => 'folder/file.txt', 'expected' => 'app/uploads/folder/file.txt'],
            ['prefix' => '/app/uploads/', 'path' => '/file.txt', 'expected' => 'app/uploads/file.txt'],
            ['prefix' => 'documents', 'path' => 'report.pdf', 'expected' => 'documents/report.pdf'],
            ['prefix' => '', 'path' => 'file.txt', 'expected' => 'file.txt'],
        ];

        foreach ($testCases as $case) {
            $config = [
                'tenancy_id' => 'test-tenancy',
                'user_id' => 'test-user',
                'key_fingerprint' => '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00',
                'key_path' => '/dev/null',
                'namespace' => 'test-namespace',
                'region' => 'us-phoenix-1',
                'bucket' => 'test-bucket',
                'url_path_prefix' => $case['prefix'],
                'storage_tier' => 'Standard',
            ];

            $client = \LaravelOCI\LaravelOciDriver\OciClient::createWithConfiguration($config);
            $actual = $client->getPrefixedPath($case['path']);
            expect($actual)->toBe($case['expected']);
        }
    }

    public function test_driver_prefix_removal_functionality(): void
    {
        $config = [
            'tenancy_id' => 'test-tenancy',
            'user_id' => 'test-user',
            'key_fingerprint' => '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00',
            'key_path' => '/dev/null',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'url_path_prefix' => 'app/uploads',
            'storage_tier' => 'Standard',
        ];

        $client = \LaravelOCI\LaravelOciDriver\OciClient::createWithConfiguration($config);

        // Test removing prefix from paths
        expect($client->removePrefixFromPath('app/uploads/file.txt'))->toBe('file.txt');
        expect($client->removePrefixFromPath('app/uploads/folder/file.txt'))->toBe('folder/file.txt');
        expect($client->removePrefixFromPath('other/path/file.txt'))->toBe('other/path/file.txt');
        expect($client->removePrefixFromPath('file.txt'))->toBe('file.txt');
    }

    public function test_driver_prefix_enabled_detection(): void
    {
        // Test with prefix enabled
        $configWithPrefix = [
            'tenancy_id' => 'test-tenancy',
            'user_id' => 'test-user',
            'key_fingerprint' => '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00',
            'key_path' => '/dev/null',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'url_path_prefix' => 'my-prefix',
            'storage_tier' => 'Standard',
        ];

        $clientWithPrefix = \LaravelOCI\LaravelOciDriver\OciClient::createWithConfiguration($configWithPrefix);
        expect($clientWithPrefix->isPrefixEnabled())->toBeTrue();
        expect($clientWithPrefix->getPrefix())->toBe('my-prefix');

        // Test without prefix
        $configWithoutPrefix = [
            'tenancy_id' => 'test-tenancy',
            'user_id' => 'test-user',
            'key_fingerprint' => '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00',
            'key_path' => '/dev/null',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'storage_tier' => 'Standard',
        ];

        $clientWithoutPrefix = \LaravelOCI\LaravelOciDriver\OciClient::createWithConfiguration($configWithoutPrefix);
        expect($clientWithoutPrefix->isPrefixEnabled())->toBeFalse();
        expect($clientWithoutPrefix->getPrefix())->toBe('');
    }

    public function test_driver_with_oci_config_and_prefix(): void
    {
        $configArray = [
            'tenancy_id' => 'test-tenancy',
            'user_id' => 'test-user',
            'key_fingerprint' => '00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00',
            'key_path' => '/dev/null',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'url_path_prefix' => 'config-prefix',
            'storage_tier' => 'Standard',
        ];

        $ociConfig = new \LaravelOCI\LaravelOciDriver\Config\OciConfig($configArray);
        $client = \LaravelOCI\LaravelOciDriver\OciClient::fromOciConfig($ociConfig);

        expect($client->isPrefixEnabled())->toBeTrue();
        expect($client->getPrefix())->toBe('config-prefix');
        expect($client->getPrefixedPath('test.txt'))->toBe('config-prefix/test.txt');
    }
}
