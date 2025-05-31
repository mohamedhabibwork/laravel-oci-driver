# Laravel OCI Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://phpstan.org/)

> **Enterprise-grade Oracle Cloud Infrastructure Object Storage driver for Laravel**

A comprehensive, production-ready Laravel filesystem driver that provides seamless integration with Oracle Cloud Infrastructure (OCI) Object Storage. Built with modern PHP practices, extensive testing, and enterprise-level features for mission-critical applications.

## 🚀 Quick Start

```bash
# Install the package
composer require mohamedhabibwork/laravel-oci-driver

# Run the interactive setup
php artisan oci:setup --generate-keys

# Configure your environment
php artisan oci:config

# Test your connection
php artisan oci:config --validate
```

## 📋 Table of Contents

-   [Features](#-features)
-   [Requirements](#-requirements)
-   [Installation](#-installation)
-   [Configuration](#️-configuration)
-   [Authentication](#-authentication)
-   [Usage](#-usage)
-   [API Reference](#-api-reference)
-   [Storage Tiers](#-storage-tiers)
-   [Performance](#-performance)
-   [Security](#-security)
-   [Testing](#-testing)
-   [Troubleshooting](#-troubleshooting)
-   [Best Practices](#-best-practices)
-   [Contributing](#-contributing)
-   [Support](#-support)

## ✨ Features

### Core Functionality

-   **🔄 Full Laravel Integration** - Native filesystem abstraction support
-   **📁 Complete File Operations** - Read, write, delete, copy, move, exists checks
-   **🔗 Temporary URLs** - Secure, time-limited file access with customizable expiration
-   **📊 Storage Tiers** - Standard, Infrequent Access, and Archive tier support
-   **⚡ Bulk Operations** - Efficient mass operations for large datasets
-   **🔐 Multiple Authentication** - File-based, environment, and custom key providers

### Enterprise Features

-   **🛡️ Enhanced Security** - SSL verification, credential masking, secure key management
-   **🔄 Retry Logic** - Configurable retry mechanisms with exponential backoff
-   **📈 Performance Monitoring** - Built-in metrics and profiling capabilities
-   **🏗️ Connection Pooling** - Efficient resource management for high-traffic applications
-   **📝 Comprehensive Logging** - Detailed operation logging with configurable levels
-   **🧪 Full Test Coverage** - Unit, integration, and performance tests

### Developer Experience

-   **🎯 Type Safety** - PHP 8.2+ with strict typing and comprehensive enums
-   **🛠️ CLI Commands** - Interactive setup, configuration, and validation tools
-   **📚 Rich Documentation** - Comprehensive guides, examples, and API reference
-   **🔧 Easy Configuration** - Automated setup with smart defaults
-   **🐛 Error Handling** - Detailed exception messages with resolution hints

## 📋 Requirements

| Component    | Version | Notes                                   |
| ------------ | ------- | --------------------------------------- |
| **PHP**      | 8.2+    | Required for modern language features   |
| **Laravel**  | 11.0+   | Filesystem abstraction compatibility    |
| **OpenSSL**  | Latest  | Required for API key generation and SSL |
| **cURL**     | Latest  | HTTP client operations                  |
| **ext-json** | \*      | JSON parsing for API responses          |

### Optional Extensions

-   **ext-mbstring** - Enhanced string handling
-   **ext-fileinfo** - Improved MIME type detection
-   **ext-intl** - Internationalization support

## 🚀 Installation

### 1. Install via Composer

```bash
composer require mohamedhabibwork/laravel-oci-driver
```

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="oci-config"
```

### 3. Run Setup Command

```bash
# Interactive setup with key generation
php artisan oci:setup --generate-keys

# Or automated setup
php artisan oci:setup --auto-config --generate-keys --force
```

## ⚙️ Configuration

### Quick Setup Workflow

The package provides an intelligent setup process that follows Oracle's official configuration standards:

```bash
# 1. Generate OCI configuration structure
php artisan oci:setup --generate-keys

# 2. Configure Laravel filesystem
php artisan oci:config

# 3. Validate connection
php artisan oci:config --validate

# 4. Test with sample operations
php artisan oci:test
```

### OCI Setup Command Reference

The `oci:setup` command creates Oracle-compatible configuration following official OCI documentation:

#### What It Creates

```
.oci/
├── config              # OCI configuration file
├── connection_name.pem # Private key files
└── connection_name_public.pem # Public key files (when generated)
```

#### Command Options

| Option            | Description                   | Example                     |
| ----------------- | ----------------------------- | --------------------------- |
| `--path`          | Custom .oci directory path    | `--path=/app/.oci`          |
| `--connection`    | Specific connections to setup | `--connection=prod,staging` |
| `--force`         | Overwrite existing files      | `--force`                   |
| `--generate-keys` | Generate new API signing keys | `--generate-keys`           |
| `--auto-config`   | Skip interactive prompts      | `--auto-config`             |
| `--profile`       | Custom profile name           | `--profile=PRODUCTION`      |

#### Setup Scenarios

**Development Environment:**

```bash
# Quick development setup
php artisan oci:setup --generate-keys --auto-config
```

**Production Environment:**

```bash
# Secure production setup
php artisan oci:setup --path=/secure/path/.oci --force
# Then manually place your production keys
```

**CI/CD Pipeline:**

```bash
# Automated deployment setup
php artisan oci:setup --auto-config --force --connection=production
```

### Laravel Filesystem Configuration

Add to your `config/filesystems.php`:

```php
'disks' => [
    'oci' => [
        'driver' => 'oci',
        'key_provider' => 'file', // 'file' or 'environment'
        'tenancy_ocid' => env('OCI_TENANCY_OCID'),
        'user_ocid' => env('OCI_USER_OCID'),
        'fingerprint' => env('OCI_FINGERPRINT'),
        'private_key_path' => env('OCI_PRIVATE_KEY_PATH'),
        'passphrase' => env('OCI_PASSPHRASE'),
        'region' => env('OCI_REGION', 'us-phoenix-1'),
        'namespace' => env('OCI_NAMESPACE'),
        'bucket' => env('OCI_BUCKET'),

        // Optional configuration
        'prefix' => env('OCI_PREFIX', ''),
        'url' => env('OCI_URL'),
        'visibility' => 'private',
        'storage_tier' => 'Standard',

        // Advanced options
        'options' => [
            'timeout' => 30,
            'connect_timeout' => 10,
            'retry_max' => 3,
            'retry_delay' => 1000,
            'verify_ssl' => true,
            'chunk_size' => 8388608, // 8MB
        ],
    ],

    // Multiple connections
    'oci-staging' => [
        'driver' => 'oci',
        'connection' => 'staging',
        // ... staging-specific config
    ],

    'oci-prod' => [
        'driver' => 'oci',
        'connection' => 'production',
        // ... production-specific config
    ],
],
```

### Environment Variables

Add to your `.env` file:

```env
# Primary OCI Configuration
OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:gg:hh:ii:jj:kk:ll:mm:nn:oo:pp
OCI_PRIVATE_KEY_PATH=.oci/oci_api_key.pem
OCI_PASSPHRASE=your_passphrase_if_encrypted
OCI_REGION=us-phoenix-1
OCI_NAMESPACE=your_namespace
OCI_BUCKET=your_bucket_name

# Optional Configuration
OCI_PREFIX=app/files/
OCI_URL=https://objectstorage.us-phoenix-1.oraclecloud.com
OCI_STORAGE_TIER=Standard

# Multiple Environment Support
OCI_STAGING_TENANCY_OCID=ocid1.tenancy.oc1..staging
OCI_PROD_TENANCY_OCID=ocid1.tenancy.oc1..production
```

## 🔐 Authentication

### Getting OCI Credentials

#### Step 1: Access OCI Console

1. Navigate to [Oracle Cloud Console](https://cloud.oracle.com/)
2. Sign in with your Oracle Cloud account

#### Step 2: Collect Required Information

**Tenancy OCID:**

```
Profile Menu → Governance & Administration → Tenancy Details
Copy: ocid1.tenancy.oc1..aaaaaaaexample
```

**User OCID:**

```
Identity & Security → Identity → Users → [Your Username]
Copy: ocid1.user.oc1..aaaaaaaexample
```

**Object Storage Details:**

```
Storage → Object Storage → Buckets
Note: Namespace, Region, Bucket Name
```

#### Step 3: Create API Key Pair

**Option A: Generate in OCI Console (Recommended)**

```
User Details → API Keys → Add API Key → Generate API Key Pair
- Download private key file
- Copy fingerprint from configuration preview
```

**Option B: Use Package Command**

```bash
php artisan oci:setup --generate-keys
# Then upload the generated public key to OCI Console
```

### Authentication Methods

#### File-Based Authentication (Recommended)

```php
// Configuration
'key_provider' => 'file',
'private_key_path' => '.oci/oci_api_key.pem',

// Usage
Storage::disk('oci')->put('file.txt', 'content');
```

#### Environment-Based Authentication

```php
// Configuration
'key_provider' => 'environment',
'private_key' => env('OCI_PRIVATE_KEY'), // Full key content

// Environment variable
OCI_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC..."
```

#### Custom Key Provider

```php
use LaravelOCI\LaravelOciDriver\Contracts\KeyProviderContract;

class CustomKeyProvider implements KeyProviderContract
{
    public function getPrivateKey(string $connection = 'default'): string
    {
        // Your custom key retrieval logic
        return $this->retrieveFromVault($connection);
    }
}

// Register in AppServiceProvider
app()->bind(KeyProviderContract::class, CustomKeyProvider::class);
```

## 📖 Usage

### Basic File Operations

```php
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Enums\Visibility;

// Get OCI disk instance
$oci = Storage::disk('oci');

// File upload
$oci->put('documents/report.pdf', $pdfContent);
$oci->putFileAs('uploads', $uploadedFile, 'custom-name.jpg');

// File download
$content = $oci->get('documents/report.pdf');
$stream = $oci->readStream('large-file.zip');

// File information
$exists = $oci->exists('documents/report.pdf');
$size = $oci->size('documents/report.pdf');
$lastModified = $oci->lastModified('documents/report.pdf');
$mimeType = $oci->mimeType('documents/report.pdf');

// File operations
$oci->copy('source.txt', 'destination.txt');
$oci->move('old-location.txt', 'new-location.txt');
$oci->delete('unwanted-file.txt');

// Directory operations
$files = $oci->files('documents/');
$allFiles = $oci->allFiles('documents/');
$directories = $oci->directories('uploads/');
$oci->makeDirectory('new-folder');
$oci->deleteDirectory('old-folder');
```

### Advanced Operations

#### Temporary URLs

```php
// Generate temporary URL (1 hour expiration)
$url = $oci->temporaryUrl('private-document.pdf', now()->addHour());

// Custom expiration
$url = $oci->temporaryUrl('file.jpg', now()->addDays(7));

// With custom headers
$url = $oci->temporaryUrl('download.zip', now()->addHour(), [
    'Content-Disposition' => 'attachment; filename="download.zip"'
]);
```

#### Bulk Operations

```php
// Bulk delete
$oci->delete([
    'file1.txt',
    'file2.txt',
    'documents/file3.pdf'
]);

// Bulk copy with progress tracking
$files = ['source1.txt', 'source2.txt', 'source3.txt'];
$destinations = ['dest1.txt', 'dest2.txt', 'dest3.txt'];

foreach (array_combine($files, $destinations) as $source => $dest) {
    $oci->copy($source, $dest);
    echo "Copied {$source} to {$dest}\n";
}
```

### Working with Multiple Connections

```php
// Use specific connection
$staging = Storage::disk('oci-staging');
$production = Storage::disk('oci-prod');

// Cross-connection operations
$content = $staging->get('test-file.txt');
$production->put('production-file.txt', $content);

// Connection-specific configuration
$config = config('filesystems.disks.oci-prod');
```

### Using Enums for Type Safety

```php
use LaravelOCI\LaravelOciDriver\Enums\{
    StorageTier,
    Visibility,
    HttpMethod,
    ContentType
};

// Storage tier selection
$oci->put('archive-data.json', $data, [
    'storage_tier' => StorageTier::ARCHIVE,
    'visibility' => Visibility::PRIVATE
]);

// Content type specification
$oci->put('image.jpg', $imageData, [
    'ContentType' => ContentType::IMAGE_JPEG->value
]);
```

## 📚 API Reference

### Storage Methods

#### File Operations

```php
// Write operations
put(string $path, string|resource $contents, array $options = []): bool
putFile(string $path, File|UploadedFile $file, array $options = []): string|false
putFileAs(string $path, File|UploadedFile $file, string $name, array $options = []): string|false
writeStream(string $path, resource $resource, array $options = []): bool

// Read operations
get(string $path): string
readStream(string $path): resource|null
download(string $path, string $name = null, array $headers = []): StreamedResponse

// Information methods
exists(string $path): bool
missing(string $path): bool
size(string $path): int
lastModified(string $path): int
mimeType(string $path): string|false
```

#### Directory Operations

```php
files(string $directory = null, bool $recursive = false): array
allFiles(string $directory = null): array
directories(string $directory = null, bool $recursive = false): array
allDirectories(string $directory = null): array
makeDirectory(string $path): bool
deleteDirectory(string $directory): bool
```

#### URL Generation

```php
url(string $path): string
temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
```

### OCI Driver Specific Methods

```php
use LaravelOCI\LaravelOciDriver\Adapters\OciAdapter;

$adapter = Storage::disk('oci')->getAdapter();

// Storage tier operations
setStorageTier(string $path, StorageTier $tier): bool
getStorageTier(string $path): StorageTier
restoreObject(string $path, int $hours = 24): bool

// Advanced operations
getObjectMetadata(string $path): array
setObjectMetadata(string $path, array $metadata): bool
copyWithMetadata(string $from, string $to, array $metadata = []): bool

// Bulk operations
bulkDelete(array $paths): array
bulkCopy(array $operations): array
```

## 🗄️ Storage Tiers

Oracle Cloud Infrastructure offers different storage tiers for cost optimization:

### Storage Tier Comparison

| Tier                  | Use Case                 | Retrieval        | Cost                              |
| --------------------- | ------------------------ | ---------------- | --------------------------------- |
| **Standard**          | Frequently accessed data | Immediate        | Higher storage, no retrieval      |
| **Infrequent Access** | Monthly access           | Immediate        | Lower storage, retrieval cost     |
| **Archive**           | Long-term storage        | Restore required | Lowest storage, highest retrieval |

### Usage Examples

```php
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

// Upload to specific tier
$oci->put('backup/data.json', $data, [
    'storage_tier' => StorageTier::ARCHIVE
]);

// Change storage tier
$adapter = $oci->getAdapter();
$adapter->setStorageTier('backup/data.json', StorageTier::INFREQUENT_ACCESS);

// Check current tier
$currentTier = $adapter->getStorageTier('backup/data.json');

// Restore archived object
$adapter->restoreObject('backup/data.json', 24); // 24 hours
```

### Cost Optimization Strategy

```php
class StorageTierManager
{
    public function optimizeStorageCosts(): void
    {
        $oci = Storage::disk('oci');
        $adapter = $oci->getAdapter();

        // Move old files to infrequent access
        $oldFiles = $this->getFilesOlderThan(30); // 30 days
        foreach ($oldFiles as $file) {
            $adapter->setStorageTier($file, StorageTier::INFREQUENT_ACCESS);
        }

        // Archive very old files
        $veryOldFiles = $this->getFilesOlderThan(365); // 1 year
        foreach ($veryOldFiles as $file) {
            $adapter->setStorageTier($file, StorageTier::ARCHIVE);
        }
    }
}
```

## ⚡ Performance

### Optimization Techniques

#### 1. Connection Pooling

```php
// config/laravel-oci-driver.php
'options' => [
    'connection_pool_size' => 10,
    'connection_timeout' => 30,
    'read_timeout' => 120,
],
```

#### 2. Chunked Uploads

```php
// Large file upload with chunking
$oci->put('large-file.zip', $content, [
    'chunk_size' => 16777216, // 16MB chunks
    'parallel_uploads' => 4,
]);
```

#### 3. Streaming for Large Files

```php
// Memory-efficient streaming
$stream = fopen('large-file.mov', 'r');
$oci->writeStream('uploads/large-file.mov', $stream);
fclose($stream);

// Streaming download
$response = $oci->download('large-file.mov');
return $response; // Streams to browser
```

#### 4. Caching Strategy

```php
// Cache file metadata
$metadata = Cache::remember("oci_metadata_{$path}", 3600, function () use ($path) {
    return $oci->getAdapter()->getObjectMetadata($path);
});

// Cache directory listings
$files = Cache::remember("oci_files_{$directory}", 300, function () use ($directory) {
    return $oci->files($directory);
});
```

### Performance Monitoring

```php
use LaravelOCI\LaravelOciDriver\Monitoring\PerformanceMonitor;

// Enable performance monitoring
$monitor = new PerformanceMonitor();
$monitor->startOperation('file_upload');

$oci->put('performance-test.txt', $content);

$metrics = $monitor->endOperation('file_upload');
// Returns: ['duration' => 1.234, 'memory_peak' => 12345, 'operation' => 'file_upload']
```

## 🛡️ Security

### Security Features

#### 1. SSL/TLS Encryption

```php
// Enforce SSL verification
'options' => [
    'verify_ssl' => true,
    'ssl_cert_path' => '/path/to/cert.pem',
],
```

#### 2. Credential Security

```php
// Secure key storage
'private_key_path' => storage_path('keys/oci_private.pem'),
'passphrase' => env('OCI_PASSPHRASE'),

// Environment-based credentials
'key_provider' => 'environment',
```

#### 3. Access Control

```php
// File visibility control
$oci->put('sensitive.txt', $content, [
    'visibility' => Visibility::PRIVATE
]);

// Temporary URL with restrictions
$url = $oci->temporaryUrl('document.pdf', now()->addHour(), [
    'ResponseContentDisposition' => 'attachment',
    'ResponseContentType' => 'application/pdf',
]);
```

### Security Best Practices

#### Credential Management

```bash
# Secure file permissions
chmod 600 .oci/oci_api_key.pem
chmod 700 .oci/

# Environment separation
cp .env.example .env.production
# Use different credentials per environment
```

#### Key Rotation

```php
// Implement key rotation
class KeyRotationService
{
    public function rotateApiKey(string $connection = 'default'): void
    {
        // 1. Generate new key pair
        $keyPair = $this->generateKeyPair();

        // 2. Upload public key to OCI
        $this->uploadPublicKeyToOci($keyPair['public']);

        // 3. Update configuration
        $this->updatePrivateKey($connection, $keyPair['private']);

        // 4. Test new configuration
        $this->validateConnection($connection);

        // 5. Remove old key from OCI (after grace period)
        $this->scheduleOldKeyRemoval();
    }
}
```

## 🧪 Testing

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test suite
vendor/bin/pest tests/Unit/
vendor/bin/pest tests/Feature/

# Run with filters
vendor/bin/pest --filter=OciAdapterTest
vendor/bin/pest --group=integration
```

### Test Configuration

Create `phpunit.xml` for testing:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="./vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">./tests/Feature</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="OCI_TENANCY_OCID" value="ocid1.tenancy.oc1..test"/>
        <env name="OCI_USER_OCID" value="ocid1.user.oc1..test"/>
        <env name="OCI_FINGERPRINT" value="aa:bb:cc:dd:ee:ff:gg:hh:ii:jj:kk:ll:mm:nn:oo:pp"/>
        <env name="OCI_REGION" value="us-phoenix-1"/>
        <env name="OCI_NAMESPACE" value="test-namespace"/>
        <env name="OCI_BUCKET" value="test-bucket"/>
    </php>
</phpunit>
```

### Writing Tests

```php
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

class CustomFeatureTest extends TestCase
{
    /** @test */
    public function it_can_upload_and_download_files(): void
    {
        $content = 'Test file content';
        $path = 'test-files/upload-test.txt';

        // Upload
        $result = $this->oci->put($path, $content);
        expect($result)->toBeTrue();

        // Verify
        expect($this->oci->exists($path))->toBeTrue();
        expect($this->oci->get($path))->toBe($content);

        // Cleanup
        $this->oci->delete($path);
    }

    /** @test */
    public function it_handles_large_files(): void
    {
        $this->markTestSkipped('Skipping in CI environment');

        $largePath = 'test-files/large-file.bin';
        $largeContent = str_repeat('A', 10 * 1024 * 1024); // 10MB

        $startTime = microtime(true);
        $this->oci->put($largePath, $largeContent);
        $uploadTime = microtime(true) - $startTime;

        expect($uploadTime)->toBeLessThan(30); // Max 30 seconds
        expect($this->oci->size($largePath))->toBe(strlen($largeContent));

        $this->oci->delete($largePath);
    }
}
```

## 🔧 Troubleshooting

### Common Issues

#### 1. Authentication Errors

**Problem:** `Invalid API key or signature`

```bash
# Validate configuration
php artisan oci:config --validate

# Check fingerprint matches
php artisan oci:fingerprint
```

**Solution:**

-   Verify fingerprint matches OCI Console
-   Ensure private key format is correct
-   Check file permissions (600 for key files)

#### 2. Connection Timeouts

**Problem:** `Connection timeout after 30 seconds`

```php
// Increase timeout in configuration
'options' => [
    'timeout' => 120,
    'connect_timeout' => 30,
],
```

#### 3. Large File Upload Failures

**Problem:** `Request entity too large`

```php
// Enable chunked uploads
'options' => [
    'chunk_size' => 8388608, // 8MB
    'use_chunked_upload' => true,
],
```

#### 4. SSL Certificate Issues

**Problem:** `SSL certificate verification failed`

```php
// Temporary workaround (not recommended for production)
'options' => [
    'verify_ssl' => false,
],

// Better solution: provide certificate bundle
'options' => [
    'verify_ssl' => true,
    'ssl_cert_path' => '/path/to/cacert.pem',
],
```

### Debug Mode

Enable debug logging:

```php
// config/laravel-oci-driver.php
'debug' => env('OCI_DEBUG', false),
'log_level' => 'debug',
'log_channel' => 'oci',
```

```php
// config/logging.php
'channels' => [
    'oci' => [
        'driver' => 'single',
        'path' => storage_path('logs/oci.log'),
        'level' => 'debug',
    ],
],
```

### Health Check Command

```bash
# Comprehensive system check
php artisan oci:health

# Check specific connection
php artisan oci:health --connection=production

# Output example:
# ✅ Configuration valid
# ✅ Authentication successful
# ✅ Bucket accessible
# ✅ Write permissions confirmed
# ⚠️  High latency detected (>2s)
```

## 💡 Best Practices

### 1. Configuration Management

```php
// Use environment-specific configuration
// config/laravel-oci-driver.php
return [
    'connections' => [
        'default' => [
            'tenancy_ocid' => env('OCI_TENANCY_OCID'),
            'region' => env('OCI_REGION', 'us-phoenix-1'),
            // ...
        ],
        'staging' => [
            'tenancy_ocid' => env('OCI_STAGING_TENANCY_OCID'),
            'region' => env('OCI_STAGING_REGION', 'us-ashburn-1'),
            // ...
        ],
    ],
];
```

### 2. Error Handling

```php
use LaravelOCI\LaravelOciDriver\Exceptions\OciException;

try {
    $oci->put('important-file.txt', $content);
} catch (OciException $e) {
    // Log the error with context
    Log::error('OCI operation failed', [
        'operation' => 'put',
        'path' => 'important-file.txt',
        'error' => $e->getMessage(),
        'oci_error_code' => $e->getOciErrorCode(),
    ]);

    // Handle gracefully
    return back()->withError('File upload failed. Please try again.');
}
```

### 3. Performance Optimization

```php
// Implement lazy loading for directory listings
class OciFileManager
{
    public function getFiles(string $directory): LazyCollection
    {
        return LazyCollection::make(function () use ($directory) {
            $files = Storage::disk('oci')->files($directory);
            foreach ($files as $file) {
                yield $file;
            }
        });
    }

    // Use generators for memory efficiency
    public function processLargeDirectory(string $directory): void
    {
        $this->getFiles($directory)
            ->chunk(100)
            ->each(function ($chunk) {
                // Process files in batches
                $this->processBatch($chunk->toArray());
            });
    }
}
```

### 4. Security Practices

```php
// Implement secure file access
class SecureFileController
{
    public function download(Request $request, string $path)
    {
        // Validate user access
        $this->authorize('download', $path);

        // Sanitize path
        $path = $this->sanitizePath($path);

        // Check file exists
        if (!Storage::disk('oci')->exists($path)) {
            abort(404);
        }

        // Generate secure temporary URL
        $url = Storage::disk('oci')->temporaryUrl(
            $path,
            now()->addMinutes(5),
            ['ResponseContentDisposition' => 'attachment']
        );

        return redirect($url);
    }

    private function sanitizePath(string $path): string
    {
        return preg_replace('/[^a-zA-Z0-9\/_\-\.]/', '', $path);
    }
}
```

## 🤝 Contributing

We welcome contributions from the community! Here's how you can help:

### Development Setup

```bash
# 1. Fork and clone the repository
git clone https://github.com/your-username/laravel-oci-driver.git
cd laravel-oci-driver

# 2. Install dependencies
composer install

# 3. Set up testing environment
cp .env.example .env.testing
# Configure test OCI credentials

# 4. Run tests to ensure everything works
composer test
```

### Contribution Guidelines

1. **Code Standards**

    - Follow PSR-12 coding standards
    - Use PHP 8.2+ features appropriately
    - Maintain strict typing
    - Write comprehensive tests

2. **Testing Requirements**

    - Unit tests for all new functionality
    - Feature tests for integration scenarios
    - Maintain minimum 90% code coverage
    - Include performance tests for critical paths

3. **Documentation**

    - Update README.md for new features
    - Add PHPDoc comments for all public methods
    - Include usage examples
    - Update CHANGELOG.md

4. **Pull Request Process**

    ```bash
    # 1. Create feature branch
    git checkout -b feature/amazing-feature

    # 2. Make changes and commit
    git commit -m "feat: add amazing feature"

    # 3. Run quality checks
    composer test
    composer analyse
    composer format

    # 4. Push and create PR
    git push origin feature/amazing-feature
    ```

### Code Quality Tools

```bash
# Static analysis
composer analyse

# Code formatting
composer format

# Full quality check
composer quality
```

## 📞 Support

### Getting Help

1. **Documentation**: Check this README and inline documentation
2. **Issues**: [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
3. **Discussions**: [GitHub Discussions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)
4. **Email**: For security issues, contact [security@mohamedhabib.work](mailto:security@mohamedhabib.work)

### Issue Reporting

When reporting issues, please include:

```markdown
**Bug Description**
Brief description of the issue

**Environment**

-   PHP Version: 8.2.x
-   Laravel Version: 11.x
-   Package Version: x.x.x
-   OCI Region: us-phoenix-1
-   Operating System: Ubuntu 22.04

**Steps to Reproduce**

1. Configure driver with...
2. Call method...
3. Error occurs...

**Expected Behavior**
What should happen

**Actual Behavior**
What actually happens

**Error Messages**
```

Any error messages or stack traces

```

**Configuration**
Relevant configuration (remove sensitive data)
```

### Commercial Support

For enterprise support, custom development, or consulting services, contact:

-   **Email**: [business@mohamedhabib.work](mailto:business@mohamedhabib.work)
-   **Website**: [https://mohamedhabib.work](https://mohamedhabib.work)

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## 👥 Credits

### Core Team

-   **[Mohamed Habib](https://github.com/mohamedhabibwork)** - Creator & Lead Developer

### Contributors

-   [All Contributors](../../contributors) - Thank you for your contributions!

### Acknowledgments

-   **Oracle Cloud Infrastructure** - For providing robust object storage services
-   **Laravel Community** - For the excellent filesystem abstraction layer
-   **PHP Community** - For continuous language improvements
-   **Open Source Community** - For inspiration and best practices

## 🚀 Roadmap

### Upcoming Features

-   **Multi-part Upload Optimization** - Enhanced large file handling
-   **Object Lifecycle Management** - Automated tier transitions
-   **Advanced Monitoring** - Detailed metrics and alerting
-   **CDN Integration** - Direct CloudFront/CloudFlare integration
-   **Backup & Sync Tools** - Automated backup solutions

### Version Compatibility

| Package Version | Laravel Version | PHP Version | Status         |
| --------------- | --------------- | ----------- | -------------- |
| 3.x             | 11.x            | 8.2+        | ✅ Active      |
| 2.x             | 10.x            | 8.1+        | 🔄 Maintenance |
| 1.x             | 9.x             | 8.0+        | ❌ End of Life |

---

<div align="center">

**Made with ❤️ for the Laravel community**

[⭐ Star us on GitHub](https://github.com/mohamedhabibwork/laravel-oci-driver) | [📚 Documentation](https://github.com/mohamedhabibwork/laravel-oci-driver/blob/main/README.md) | [🐛 Report Bug](https://github.com/mohamedhabibwork/laravel-oci-driver/issues) | [💡 Request Feature](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)

</div>
