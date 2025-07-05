# Laravel OCI Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://phpstan.org/)

---

## 🛠️ How to Install

```bash
composer require mohamedhabibwork/laravel-oci-driver
```

---

## 🚦 How to Use (Simple Example)

1. **Configure your `.env` and `config/filesystems.php`** (see below for details)
2. **Store and retrieve a file:**

```php
use Illuminate\Support\Facades\Storage;

// Store a file
Storage::disk('oci')->put('example.txt', 'Hello Oracle Cloud!');

// Retrieve a file
$content = Storage::disk('oci')->get('example.txt');
echo $content; // Outputs: Hello Oracle Cloud!
```

---

## 📋 Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Authentication](#authentication)
- [Usage](#usage)
- [API Reference](#api-reference)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Support](#support)

## ✨ Features

- Full Laravel Filesystem integration for Oracle Cloud Infrastructure (OCI) Object Storage
- Read, write, delete, copy, move, and check existence of files
- Directory operations (list, create, delete)
- Temporary URLs for secure, time-limited file access
- Multiple authentication methods (file-based, environment-based)
- Storage tier support (Standard, Infrequent Access, Archive)
- Bulk operations (delete, copy)
- Advanced configuration options (timeouts, SSL, chunk size, etc.)
- Comprehensive logging and error handling
- CLI commands for setup, configuration, and validation
- Tested with PHP 8.2+ and Laravel 11+

## 📋 Requirements

| Component    | Version | Notes                                   |
| ------------ | ------- | --------------------------------------- |
| **PHP**      | 8.2+    | Required for modern language features   |
| **Laravel**  | 11.0+   | Filesystem abstraction compatibility    |
| **OpenSSL**  | Latest  | Required for API key generation and SSL |
| **cURL**     | Latest  | HTTP client operations                  |
| **ext-json** | *       | JSON parsing for API responses          |

### Optional Extensions

- **ext-mbstring** - Enhanced string handling
- **ext-fileinfo** - Improved MIME type detection
- **ext-intl** - Internationalization support

## 🚀 Installation

```bash
composer require mohamedhabibwork/laravel-oci-driver
```

Optionally, publish the configuration:

```bash
php artisan vendor:publish --tag="oci-config"
```

Run the setup command:

```bash
php artisan oci:setup --generate-keys
```

## ⚙️ Configuration

Add to your `config/filesystems.php`:

```php
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
    'prefix' => env('OCI_PREFIX', ''),
    'url' => env('OCI_URL'),
    'visibility' => 'private',
    'storage_tier' => 'Standard',
    'options' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry_max' => 3,
        'retry_delay' => 1000,
        'verify_ssl' => true,
        'chunk_size' => 8388608, // 8MB
    ],
],
```

Add to your `.env` file:

```env
OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:gg:hh:ii:jj:kk:ll:mm:nn:oo:pp
OCI_PRIVATE_KEY_PATH=.oci/oci_api_key.pem
OCI_PASSPHRASE=your_passphrase_if_encrypted
OCI_REGION=us-phoenix-1
OCI_NAMESPACE=your_namespace
OCI_BUCKET=your_bucket_name
OCI_PREFIX=app/files/
OCI_URL=https://objectstorage.us-phoenix-1.oraclecloud.com
OCI_STORAGE_TIER=Standard
```

## 🔐 Authentication

- File-based: store your private key in a file and reference it in the config
- Environment-based: store your private key in an environment variable

## 📖 Usage

### Basic File Operations

```php
use Illuminate\Support\Facades\Storage;

$oci = Storage::disk('oci');

// File upload
$oci->put('documents/report.pdf', $pdfContent);

// File download
$content = $oci->get('documents/report.pdf');

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

### Temporary URLs

```php
// Generate temporary URL (1 hour expiration)
$url = $oci->temporaryUrl('private-document.pdf', now()->addHour());
```

### Bulk Operations

```php
// Bulk delete
$oci->delete([
    'file1.txt',
    'file2.txt',
    'documents/file3.pdf'
]);
```

### Multiple Connections

```php
$staging = Storage::disk('oci-staging');
$production = Storage::disk('oci-prod');
```

### Storage Tiers

```php
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

$oci->put('backup/data.json', $data, [
    'storage_tier' => StorageTier::ARCHIVE
]);
```

## 📚 API Reference

See the [API_REFERENCE.md](docs/API_REFERENCE.md) for full method signatures and options.

## 🧪 Testing

```bash
composer test
```

## 🔧 Troubleshooting

- Use `php artisan oci:config --validate` to check your configuration
- Ensure your OCI credentials and key files are correct
- Check file permissions for private keys
- See [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) for more help

## 🤝 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📞 Support

- [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
- [GitHub Discussions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)
- Email: [security@mohamedhabib.work](mailto:security@mohamedhabib.work)

## 📄 License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.

## 👥 Credits

- **[Mohamed Habib](https://github.com/mohamedhabibwork)** - Creator & Lead Developer
- [All Contributors](../../contributors)

---

<div align="center">

**Made with ❤️ for the Laravel community**

[⭐ Star us on GitHub](https://github.com/mohamedhabibwork/laravel-oci-driver) | [📚 Documentation](https://github.com/mohamedhabibwork/laravel-oci-driver/blob/main/README.md) | [🐛 Report Bug](https://github.com/mohamedhabibwork/laravel-oci-driver/issues) | [💡 Request Feature](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)

</div>
