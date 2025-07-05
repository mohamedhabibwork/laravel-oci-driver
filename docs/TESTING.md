# Testing Guide

This guide describes testing strategies and examples for the Laravel OCI Driver.

---

## Testing Strategies

### 1. Unit Testing
Test individual components in isolation using mocks and fakes.

### 2. Integration Testing
Test the actual integration with OCI services using test environments.

### 3. Feature Testing
Test complete workflows from HTTP requests to OCI operations.

### 4. Performance Testing
Test upload/download performance and resource usage.

---

## Unit Testing

### Basic File Operations

```php
<?php

namespace Tests\Unit;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OciFileOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('oci');
    }

    public function test_file_upload()
    {
        $content = 'Test file content';
        $path = 'test/file.txt';

        $result = Storage::disk('oci')->put($path, $content);

        $this->assertTrue($result);
        Storage::disk('oci')->assertExists($path);
        $this->assertEquals($content, Storage::disk('oci')->get($path));
    }

    public function test_file_upload_with_metadata()
    {
        $file = UploadedFile::fake()->create('test.pdf', 1024);
        
        $path = Storage::disk('oci')->putFile('documents', $file, [
            'metadata' => [
                'uploaded_by' => 'user123',
                'category' => 'documents'
            ]
        ]);

        Storage::disk('oci')->assertExists($path);
    }

    public function test_file_deletion()
    {
        $path = 'test/file.txt';
        Storage::disk('oci')->put($path, 'content');
        
        $result = Storage::disk('oci')->delete($path);
        
        $this->assertTrue($result);
        Storage::disk('oci')->assertMissing($path);
    }

    public function test_bulk_file_deletion()
    {
        $files = ['file1.txt', 'file2.txt', 'file3.txt'];
        
        foreach ($files as $file) {
            Storage::disk('oci')->put($file, 'content');
        }
        
        $result = Storage::disk('oci')->delete($files);
        
        $this->assertTrue($result);
        foreach ($files as $file) {
            Storage::disk('oci')->assertMissing($file);
        }
    }

    public function test_file_exists_check()
    {
        $path = 'test/exists.txt';
        
        $this->assertFalse(Storage::disk('oci')->exists($path));
        
        Storage::disk('oci')->put($path, 'content');
        
        $this->assertTrue(Storage::disk('oci')->exists($path));
    }

    public function test_file_size()
    {
        $content = 'Hello World!';
        $path = 'test/size.txt';
        
        Storage::disk('oci')->put($path, $content);
        
        $size = Storage::disk('oci')->size($path);
        $this->assertEquals(strlen($content), $size);
    }

    public function test_directory_listing()
    {
        $files = [
            'documents/file1.txt',
            'documents/file2.txt',
            'images/photo.jpg'
        ];
        
        foreach ($files as $file) {
            Storage::disk('oci')->put($file, 'content');
        }
        
        $documentFiles = Storage::disk('oci')->files('documents');
        $this->assertCount(2, $documentFiles);
        
        $allFiles = Storage::disk('oci')->allFiles();
        $this->assertCount(3, $allFiles);
    }
}
```

### Testing Storage Tiers

```php
<?php

namespace Tests\Unit;

use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageTierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('oci');
    }

    public function test_upload_with_standard_tier()
    {
        $path = Storage::disk('oci')->put('standard.txt', 'content', [
            'storage_tier' => StorageTier::STANDARD
        ]);

        Storage::disk('oci')->assertExists($path);
    }

    public function test_upload_with_infrequent_access_tier()
    {
        $path = Storage::disk('oci')->put('infrequent.txt', 'content', [
            'storage_tier' => StorageTier::INFREQUENT_ACCESS
        ]);

        Storage::disk('oci')->assertExists($path);
    }

    public function test_upload_with_archive_tier()
    {
        $path = Storage::disk('oci')->put('archive.txt', 'content', [
            'storage_tier' => StorageTier::ARCHIVE
        ]);

        Storage::disk('oci')->assertExists($path);
    }

    public function test_storage_tier_enum_values()
    {
        $this->assertEquals('Standard', StorageTier::STANDARD->value);
        $this->assertEquals('InfrequentAccess', StorageTier::INFREQUENT_ACCESS->value);
        $this->assertEquals('Archive', StorageTier::ARCHIVE->value);
    }
}
```

### Testing Key Providers

```php
<?php

namespace Tests\Unit;

use LaravelOCI\LaravelOciDriver\KeyProvider\FileKeyProvider;
use LaravelOCI\LaravelOciDriver\KeyProvider\EnvironmentKeyProvider;
use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use Tests\TestCase;

class KeyProviderTest extends TestCase
{
    private string $testKeyContent = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKB\n-----END PRIVATE KEY-----";

    public function test_file_key_provider()
    {
        $tempKeyFile = tempnam(sys_get_temp_dir(), 'oci_test_key');
        file_put_contents($tempKeyFile, $this->testKeyContent);
        chmod($tempKeyFile, 0600);

        $provider = new FileKeyProvider(
            $tempKeyFile,
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        $this->assertEquals($this->testKeyContent, $provider->getPrivateKey());
        $this->assertEquals('tenancy-id/user-id/fingerprint', $provider->getKeyId());

        unlink($tempKeyFile);
    }

    public function test_file_key_provider_missing_file()
    {
        $provider = new FileKeyProvider(
            '/nonexistent/key.pem',
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        $this->expectException(PrivateKeyFileNotFoundException::class);
        $provider->getPrivateKey();
    }

    public function test_environment_key_provider()
    {
        $provider = new EnvironmentKeyProvider(
            $this->testKeyContent,
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        $this->assertEquals($this->testKeyContent, $provider->getPrivateKey());
        $this->assertEquals('tenancy-id/user-id/fingerprint', $provider->getKeyId());
    }

    public function test_environment_key_provider_from_base64()
    {
        $base64Key = base64_encode($this->testKeyContent);

        $provider = EnvironmentKeyProvider::fromBase64(
            $base64Key,
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        $this->assertEquals($this->testKeyContent, $provider->getPrivateKey());
    }

    public function test_key_validation()
    {
        $provider = new EnvironmentKeyProvider(
            $this->testKeyContent,
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        $this->assertTrue($provider->validateKey());
    }

    public function test_invalid_key_validation()
    {
        $provider = new EnvironmentKeyProvider(
            'invalid-key-content',
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        $this->expectException(\InvalidArgumentException::class);
        $provider->validateKey();
    }
}
```

---

## Integration Testing

### Testing with Real OCI Services

```php
<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OciIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if not in integration test environment
        if (!config('testing.oci.enabled')) {
            $this->markTestSkipped('OCI integration tests disabled');
        }
    }

    public function test_real_file_upload_and_download()
    {
        $content = 'Integration test content - ' . now()->toISOString();
        $path = 'integration-tests/test-' . uniqid() . '.txt';

        // Upload
        $result = Storage::disk('oci')->put($path, $content);
        $this->assertTrue($result);

        // Verify existence
        $this->assertTrue(Storage::disk('oci')->exists($path));

        // Download and verify content
        $downloadedContent = Storage::disk('oci')->get($path);
        $this->assertEquals($content, $downloadedContent);

        // Cleanup
        Storage::disk('oci')->delete($path);
        $this->assertFalse(Storage::disk('oci')->exists($path));
    }

    public function test_large_file_upload()
    {
        $size = 10 * 1024 * 1024; // 10MB
        $content = str_repeat('A', $size);
        $path = 'integration-tests/large-file-' . uniqid() . '.txt';

        $startTime = microtime(true);
        $result = Storage::disk('oci')->put($path, $content);
        $uploadTime = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(60, $uploadTime, 'Upload took too long');

        // Verify file size
        $this->assertEquals($size, Storage::disk('oci')->size($path));

        // Cleanup
        Storage::disk('oci')->delete($path);
    }

    public function test_concurrent_uploads()
    {
        $files = [];
        $promises = [];

        for ($i = 0; $i < 5; $i++) {
            $content = "Concurrent test content $i";
            $path = "integration-tests/concurrent-$i-" . uniqid() . '.txt';
            $files[] = $path;

            // In a real concurrent test, you'd use async operations
            Storage::disk('oci')->put($path, $content);
        }

        // Verify all files exist
        foreach ($files as $file) {
            $this->assertTrue(Storage::disk('oci')->exists($file));
        }

        // Cleanup
        Storage::disk('oci')->delete($files);
    }
}
```

### Testing Configuration

```php
// config/testing.php
return [
    'oci' => [
        'enabled' => env('OCI_INTEGRATION_TESTS', false),
        'test_bucket' => env('OCI_TEST_BUCKET', 'test-bucket'),
        'test_prefix' => 'automated-tests/',
    ],
];
```

---

## Feature Testing

### Testing HTTP Endpoints

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('oci');
    }

    public function test_file_upload_endpoint()
    {
        $file = UploadedFile::fake()->create('test.pdf', 1024);

        $response = $this->postJson('/api/upload', [
            'file' => $file,
            'category' => 'documents'
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'path',
                     'url'
                 ]);

        Storage::disk('oci')->assertExists('documents/' . $file->hashName());
    }

    public function test_file_upload_validation()
    {
        $response = $this->postJson('/api/upload', [
            'category' => 'documents'
            // Missing file
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['file']);
    }

    public function test_file_download_endpoint()
    {
        $content = 'Test file content';
        $path = 'documents/test.txt';
        Storage::disk('oci')->put($path, $content);

        $response = $this->get("/api/download/{$path}");

        $response->assertStatus(200)
                 ->assertHeader('content-disposition', 'attachment; filename=test.txt');
    }

    public function test_file_download_not_found()
    {
        $response = $this->get('/api/download/nonexistent/file.txt');

        $response->assertStatus(404);
    }

    public function test_authenticated_file_access()
    {
        $user = \App\Models\User::factory()->create();
        $file = UploadedFile::fake()->create('private.pdf');

        // Upload as authenticated user
        $response = $this->actingAs($user)
                         ->postJson('/api/upload', [
                             'file' => $file,
                             'category' => 'documents'
                         ]);

        $response->assertStatus(200);
        $path = $response->json('path');

        // Try to access without authentication
        $response = $this->get("/api/download/{$path}");
        $response->assertStatus(401);

        // Access with authentication
        $response = $this->actingAs($user)
                         ->get("/api/download/{$path}");
        $response->assertStatus(200);
    }
}
```

---

## Testing Artisan Commands

### Testing OCI Commands

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OciCommandsTest extends TestCase
{
    public function test_oci_status_command()
    {
        Storage::fake('oci');

        $this->artisan('oci:status')
             ->expectsOutput('Laravel OCI Driver Status Check')
             ->assertExitCode(0);
    }

    public function test_oci_config_validate_command()
    {
        $this->artisan('oci:config', ['--validate' => true])
             ->assertExitCode(0);
    }

    public function test_oci_setup_command()
    {
        $this->artisan('oci:setup', ['--auto-config' => true])
             ->assertExitCode(0);
    }

    public function test_oci_connection_list_command()
    {
        $this->artisan('oci:connection', ['action' => 'list'])
             ->expectsOutput('📋 Available OCI Connections:')
             ->assertExitCode(0);
    }

    public function test_oci_connection_test_command()
    {
        Storage::fake('oci');

        $this->artisan('oci:connection', [
                'action' => 'test',
                'connection' => 'default'
             ])
             ->assertExitCode(0);
    }
}
```

### Testing Custom Commands

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use Tests\TestCase;

class CustomOciCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('oci');
    }

    public function test_migrate_old_files_command()
    {
        // Create test files with different ages
        $oldFile = 'old-documents/old-file.txt';
        $newFile = 'documents/new-file.txt';
        
        Storage::disk('oci')->put($oldFile, 'old content');
        Storage::disk('oci')->put($newFile, 'new content');

        $this->artisan('oci:migrate-old-files', ['--days' => 30])
             ->expectsOutput('Migrating files older than 30 days to archive tier...')
             ->assertExitCode(0);
    }

    public function test_cleanup_temp_files_command()
    {
        // Create temporary files
        Storage::disk('oci')->put('temp/file1.tmp', 'temp');
        Storage::disk('oci')->put('temp/file2.tmp', 'temp');
        Storage::disk('oci')->put('documents/keep.txt', 'keep');

        $this->artisan('app:cleanup-temp-files')
             ->expectsOutput('Cleaning up temporary files...')
             ->assertExitCode(0);

        // Verify temp files are deleted but documents remain
        Storage::disk('oci')->assertMissing('temp/file1.tmp');
        Storage::disk('oci')->assertMissing('temp/file2.tmp');
        Storage::disk('oci')->assertExists('documents/keep.txt');
    }
}
```

---

## Performance Testing

### Benchmarking File Operations

```php
<?php

namespace Tests\Performance;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OciPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        if (!config('testing.performance.enabled')) {
            $this->markTestSkipped('Performance tests disabled');
        }
    }

    public function test_upload_performance()
    {
        $sizes = [
            '1KB' => 1024,
            '1MB' => 1024 * 1024,
            '10MB' => 10 * 1024 * 1024,
        ];

        foreach ($sizes as $label => $size) {
            $content = str_repeat('A', $size);
            $path = "performance-test/{$label}-" . uniqid() . '.txt';

            $startTime = microtime(true);
            Storage::disk('oci')->put($path, $content);
            $uploadTime = microtime(true) - $startTime;

            $this->addToAssertionCount(1);
            
            echo "\n{$label} upload time: " . round($uploadTime, 3) . "s";
            
            // Cleanup
            Storage::disk('oci')->delete($path);
        }
    }

    public function test_download_performance()
    {
        $content = str_repeat('A', 5 * 1024 * 1024); // 5MB
        $path = 'performance-test/download-test.txt';
        
        Storage::disk('oci')->put($path, $content);

        $iterations = 5;
        $totalTime = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $downloaded = Storage::disk('oci')->get($path);
            $downloadTime = microtime(true) - $startTime;
            
            $totalTime += $downloadTime;
            $this->assertEquals(strlen($content), strlen($downloaded));
        }

        $averageTime = $totalTime / $iterations;
        echo "\nAverage download time (5MB): " . round($averageTime, 3) . "s";

        Storage::disk('oci')->delete($path);
    }

    public function test_concurrent_operations()
    {
        $fileCount = 10;
        $content = str_repeat('A', 1024); // 1KB each
        $files = [];

        $startTime = microtime(true);

        // Simulate concurrent uploads
        for ($i = 0; $i < $fileCount; $i++) {
            $path = "performance-test/concurrent-{$i}.txt";
            Storage::disk('oci')->put($path, $content);
            $files[] = $path;
        }

        $uploadTime = microtime(true) - $startTime;

        // Verify all files exist
        foreach ($files as $file) {
            $this->assertTrue(Storage::disk('oci')->exists($file));
        }

        echo "\n{$fileCount} concurrent uploads time: " . round($uploadTime, 3) . "s";

        // Cleanup
        Storage::disk('oci')->delete($files);
    }
}
```

### Memory Usage Testing

```php
<?php

namespace Tests\Performance;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemoryUsageTest extends TestCase
{
    public function test_memory_usage_large_file()
    {
        $initialMemory = memory_get_usage(true);
        
        // Create a large file (50MB)
        $size = 50 * 1024 * 1024;
        $tempFile = tempnam(sys_get_temp_dir(), 'oci_memory_test');
        $handle = fopen($tempFile, 'w');
        
        for ($i = 0; $i < $size / 1024; $i++) {
            fwrite($handle, str_repeat('A', 1024));
        }
        fclose($handle);

        $beforeUpload = memory_get_usage(true);
        
        // Upload using stream
        $stream = fopen($tempFile, 'r');
        Storage::disk('oci')->writeStream('memory-test/large-file.txt', $stream);
        fclose($stream);
        
        $afterUpload = memory_get_usage(true);
        
        $memoryIncrease = $afterUpload - $beforeUpload;
        
        echo "\nMemory increase during 50MB upload: " . round($memoryIncrease / 1024 / 1024, 2) . "MB";
        
        // Memory increase should be minimal when streaming
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 'Memory usage too high');
        
        // Cleanup
        unlink($tempFile);
        Storage::disk('oci')->delete('memory-test/large-file.txt');
    }
}
```

---

## CI/CD Testing

### GitHub Actions Configuration

```yaml
# .github/workflows/tests.yml
name: Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  test:
    runs-on: ubuntu-latest
    
    strategy:
      matrix:
        php: [8.2, 8.3]
        laravel: [10.x, 11.x]

    steps:
    - uses: actions/checkout@v3

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php }}
        extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv, imagick, openssl

    - name: Cache Composer packages
      id: composer-cache
      uses: actions/cache@v3
      with:
        path: vendor
        key: ${{ runner.os }}-php-${{ hashFiles('**/composer.lock') }}
        restore-keys: |
          ${{ runner.os }}-php-

    - name: Install dependencies
      run: composer install --prefer-dist --no-progress

    - name: Copy environment file
      run: cp .env.testing .env

    - name: Generate application key
      run: php artisan key:generate

    - name: Run unit tests
      run: php artisan test --testsuite=Unit

    - name: Run feature tests
      run: php artisan test --testsuite=Feature

  integration-tests:
    runs-on: ubuntu-latest
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: 8.2
        extensions: openssl

    - name: Install dependencies
      run: composer install --prefer-dist --no-progress

    - name: Run integration tests
      env:
        OCI_INTEGRATION_TESTS: true
        OCI_TENANCY_OCID: ${{ secrets.OCI_TENANCY_OCID }}
        OCI_USER_OCID: ${{ secrets.OCI_USER_OCID }}
        OCI_FINGERPRINT: ${{ secrets.OCI_FINGERPRINT }}
        OCI_PRIVATE_KEY_CONTENT: ${{ secrets.OCI_PRIVATE_KEY_CONTENT }}
        OCI_REGION: ${{ secrets.OCI_REGION }}
        OCI_NAMESPACE: ${{ secrets.OCI_NAMESPACE }}
        OCI_TEST_BUCKET: ${{ secrets.OCI_TEST_BUCKET }}
      run: php artisan test --testsuite=Integration
```

### Test Environment Configuration

```php
// .env.testing
APP_ENV=testing
APP_KEY=base64:test-key-here
APP_DEBUG=true

# OCI Test Configuration
OCI_INTEGRATION_TESTS=false
OCI_TENANCY_OCID=ocid1.tenancy.oc1..test
OCI_USER_OCID=ocid1.user.oc1..test
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99
OCI_PRIVATE_KEY_PATH=tests/fixtures/test_key.pem
OCI_REGION=us-phoenix-1
OCI_NAMESPACE=test-namespace
OCI_BUCKET=test-bucket

# Performance Testing
PERFORMANCE_TESTS=false
```

---

## Testing Best Practices

### 1. Use Storage Fakes for Unit Tests
```php
Storage::fake('oci'); // Always use for unit tests
```

### 2. Clean Up After Tests
```php
protected function tearDown(): void
{
    Storage::disk('oci')->delete(Storage::disk('oci')->allFiles());
    parent::tearDown();
}
```

### 3. Test Error Conditions
```php
public function test_handles_network_errors()
{
    // Mock network failure
    $this->expectException(NetworkException::class);
    // Test code that should handle network errors
}
```

### 4. Use Realistic Test Data
```php
$file = UploadedFile::fake()->create('document.pdf', 2048, 'application/pdf');
```

### 5. Test Performance Boundaries
```php
public function test_large_file_upload_timeout()
{
    $this->expectException(TimeoutException::class);
    // Test with very large file
}
```

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Usage Examples](EXAMPLES.md)
- [API Reference](API_REFERENCE.md)
- [Performance Guide](PERFORMANCE.md) 