# Laravel OCI Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)

A Laravel filesystem driver for Oracle Cloud Infrastructure (OCI) Object Storage. This package integrates seamlessly with Laravel's storage system, allowing you to use OCI Object Storage just like any other Laravel storage driver.

## Features

- Full integration with Laravel's filesystem abstraction
- Support for all standard file operations (read, write, delete, etc.)
- Support for temporary URLs
- Configurable storage tier
- Proper authentication using OCI API keys
- PHP 8.2+ and Laravel 10+ support

## Installation

You can install the package via composer:

```bash
composer require mohamedhabibwork/laravel-oci-driver
```

## Configuration

After installation, publish the configuration file:

```bash
php artisan vendor:publish --tag="laravel-oci-driver-config"
```

Then, add the following to your `config/filesystems.php` file in the `disks` array:

```php
'oci' => [
    'driver' => 'oci',
    'namespace' => env('OCI_NAMESPACE'),
    'region' => env('OCI_REGION'),
    'bucket' => env('OCI_BUCKET'),
    'tenancy_id' => env('OCI_TENANCY_ID'),
    'user_id' => env('OCI_USER_ID'),
    'storage_tier' => env('OCI_STORAGE_TIER', 'Standard'),
    'key_fingerprint' => env('OCI_KEY_FINGERPRINT'),
    'key_path' => env('OCI_KEY_PATH'),
],
```

Add the following environment variables to your `.env` file:

```
OCI_NAMESPACE=your-namespace
OCI_REGION=your-region
OCI_BUCKET=your-bucket-name
OCI_TENANCY_ID=your-tenancy-id
OCI_USER_ID=your-user-id
OCI_STORAGE_TIER=Standard
OCI_KEY_FINGERPRINT=your-key-fingerprint
OCI_KEY_PATH=/path/to/your/oci/private-key.pem
```

## Usage

Once configured, you can use the OCI driver just like any other Laravel storage driver:

```php
// Store a file
Storage::disk('oci')->put('file.txt', 'Contents');

// Get a file
$contents = Storage::disk('oci')->get('file.txt');

// Check if a file exists
$exists = Storage::disk('oci')->exists('file.txt');

// Delete a file
Storage::disk('oci')->delete('file.txt');

// Generate a temporary URL (valid for 1 hour)
$url = Storage::disk('oci')->temporaryUrl('file.txt', now()->addHour());
```

You can also use the `Storage` facade with the default disk if you've set OCI as your default in `config/filesystems.php`:

```php
// In config/filesystems.php
'default' => env('FILESYSTEM_DISK', 'oci'),

// Then in your code
Storage::put('file.txt', 'Contents');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mohamed Habib](https://github.com/mohamedhabibwork)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
