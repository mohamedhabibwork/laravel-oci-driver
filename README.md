# Laravel OCI Driver

Laravel Flysystem (v3) driver for Oracle Cloud Infrastructure (OCI) Object Storage.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![CI](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions/workflows/ci.yml)
[![Code Style](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/code-style.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions/workflows/code-style.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://phpstan.org/)

Requires PHP 8.2+ (8.3+ with Laravel 13) and Laravel 11, 12 or 13 (tested in CI). Laravel 10 is allowed by the package constraints but is not tested in CI.

## Installation

```bash
composer require mohamedhabibwork/laravel-oci-driver
php artisan vendor:publish --provider="LaravelOCI\LaravelOciDriver\LaravelOciDriverServiceProvider" --tag="laravel-oci-driver-config"
```

The publish tag is optional: it is only needed if you want the `laravel-oci-driver.connections` config file.

## Quick start

Add a disk to `config/filesystems.php`:

```php
'oci' => [
    'driver' => 'oci',
    'tenancy_id' => env('OCI_TENANCY_ID'),
    'user_id' => env('OCI_USER_ID'),
    'key_fingerprint' => env('OCI_KEY_FINGERPRINT'),
    'key_path' => env('OCI_KEY_PATH'),
    'namespace' => env('OCI_NAMESPACE'),
    'region' => env('OCI_REGION'),
    'bucket' => env('OCI_BUCKET'),
],
```

```php
use Illuminate\Support\Facades\Storage;

Storage::disk('oci')->put('documents/hello.txt', 'Hello, Oracle Cloud!');
$content = Storage::disk('oci')->get('documents/hello.txt');
$files = Storage::disk('oci')->files('documents');
```

## Configuration

Each disk is built from **its own array**. Required keys: `tenancy_id`, `user_id`, `key_fingerprint`, `namespace`, `region`, `bucket` and one key source. A missing value throws an `InvalidArgumentException` that names the disk.

### Private key sources

Checked in this order; the first one that is set is used:

| Key | Meaning |
|---|---|
| `private_key` | PEM contents as a string |
| `private_key_base64` | PEM contents, base64-encoded |
| `key_path` | Local path to the PEM file (URLs are rejected) |

Use `passphrase` for an encrypted key.

### Options

| Key | Default | Description |
|---|---|---|
| `storage_tier` | `Standard` | `Standard`, `InfrequentAccess` or `Archive` |
| `url_path_prefix` | `''` | Prefix for every object name |
| `timeout` / `connect_timeout` | `30` / `10` | Seconds; `timeout` is the total request time and does not apply to upload PUTs |
| `retry_attempts` / `retry_delay` | `3` / `1000` | Retries on connection errors, 429, 5xx; delay in ms, exponential |
| `upload.multipart_threshold` | `104857600` | Bytes; larger (or unknown-size) streams use multipart |
| `upload.chunk_size` | `33554432` | Part size in bytes; minimum 10 MiB (`10485760`) |
| `upload.enable_checksum` | `true` | `Content-MD5` on single-PUT string uploads |
| `delete_concurrency` | `10` | Parallel deletes in `deleteDirectory` |
| `copy_timeout` / `copy_poll_interval` | `300` / `1000` | Seconds / ms for async copy |
| `temporary_url.default_expiry` / `max_expiry` | `3600` / `86400` | Seconds |
| `visibility` | `private` | What `visibility()` reports when no mapping is set |
| `visibility_mapping` | `null` | Opt-in visibility to tier map |

`laravel-oci-driver.global.throw_exceptions` (default `true`) controls whether a failed `temporaryUrl()` throws or returns `''`.

The `cache.*`, `logging.*`, `content_type.*` and most other `global.*` keys in the published config file are reserved and currently unused. The full reference is in [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Multiple disks

Define as many `oci` disks as you need, each with its own bucket and credentials. To share credentials, set `'connection' => 'name'` on a disk: values from `laravel-oci-driver.connections.name` are merged underneath the disk values and the disk wins.

```php
'oci' => ['driver' => 'oci', 'connection' => 'default'],
'oci_backups' => [
    'driver' => 'oci',
    'connection' => 'default',
    'bucket' => 'backups',
    'url_path_prefix' => 'nightly',
    'storage_tier' => 'InfrequentAccess',
],
```

A connection that exists only in `laravel-oci-driver.connections` is not usable on its own: a `filesystems.disks` entry with `'driver' => 'oci'` is always required. `LaravelOciDriver::connection('backups')` returns the disk named `backups` (or `oci_backups`).

## Uploads and multipart

Writes up to `upload.multipart_threshold` are a single streamed `PUT` (not buffered in memory). Larger writes, and streams whose size cannot be determined, use OCI native multipart upload: one part of `upload.chunk_size` bytes is held in memory at a time, and the upload is aborted if a part fails.

Per-write options:

```php
Storage::disk('oci')->put('reports/q1.pdf', $contents, [
    'content_type' => 'application/pdf',
    'storage_tier' => 'InfrequentAccess',
    'metadata' => ['department' => 'finance'],   // stored as opc-meta-*
    'checksum' => false,                         // skip Content-MD5 for this write
]);
```

## Listing, directories, copy and move

- Listing is paginated and lazy; returned paths do not include `url_path_prefix`.
- `makeDirectory()` writes an empty `dir/` object; `deleteDirectory()` deletes every object under the directory with `delete_concurrency` parallel requests and throws `UnableToDeleteDirectory` listing the keys that failed. On a disk without `url_path_prefix`, deleting the root (`''` or `'/'`) is refused to protect the bucket.
- `copy()` is asynchronous on OCI; the driver polls the work request until it completes, fails or `copy_timeout` elapses. `move()` uses OCI `renameObject` (same bucket, atomic).

## Temporary URLs

`temporaryUrl()` creates a pre-authenticated request (PAR):

```php
$url = Storage::disk('oci')->temporaryUrl('invoices/1.pdf', now()->addHour());

// upload URL
$url = Storage::disk('oci')->temporaryUrl('inbox/file.bin', now()->addMinutes(10), [
    'access_type' => 'ObjectWrite',   // default: ObjectRead
]);
```

Expiry defaults to `temporary_url.default_expiry` and is capped at `temporary_url.max_expiry`. PARs are not deleted when they expire. Remove expired ones that this package created:

```bash
php artisan oci:par:prune oci --dry-run   # report only
php artisan oci:par:prune oci
```

Schedule it, for example `Schedule::command('oci:par:prune oci')->daily();`.

## Visibility

OCI has no per-object ACL. By default `setVisibility()` throws `UnableToSetVisibility`, and `visibility()` reports the disk's `visibility` value (default `private`). If you want Laravel's visibility API to move objects between storage tiers, opt in:

```php
'visibility_mapping' => [
    'public' => 'Standard',
    'private' => 'InfrequentAccess',
],
```

Tier changes and restores from Archive are available on the adapter:

```php
$adapter = Storage::disk('oci')->getAdapter();
$adapter->updateStorageTier('old/report.pdf', \LaravelOCI\LaravelOciDriver\Enums\StorageTier::ARCHIVE);
$adapter->restore('old/report.pdf', hours: 24);
```

## Artisan commands

| Command | Purpose |
|---|---|
| `oci:setup` | Create the `.oci` folder, generate/copy key files and write config |
| `oci:config [--validate] [--disk=oci]` | Interactive configuration, or validate a disk |
| `oci:connection {list\|test\|switch\|summary} {name?}` | Inspect configured connections |
| `oci:status [--bucket=]` | Test connectivity and basic file operations on the `oci` disk |
| `oci:par:prune {disk=oci} [--dry-run]` | Delete expired pre-authenticated requests created by this package |

## Errors

Failures are mapped to the matching Flysystem exception (`UnableToReadFile`, `UnableToWriteFile`, `UnableToDeleteFile`, `UnableToDeleteDirectory`, `UnableToCopyFile`, `UnableToMoveFile`, `UnableToListContents`, ...). The previous exception is an `OciRequestFailed` carrying the HTTP status, `opc-request-id` and OCI error code.

## Troubleshooting

- `php artisan oci:config --validate --disk=<disk>` shows which required value is missing.
- Names with spaces, `+` or `%` are percent-encoded as a single path segment; do not pre-encode them.
- The adapter always applies `url_path_prefix`: with prefix `local`, `write('local/a')` stores `local/local/a`. (`OciClient::getPrefixedPath()` keeps its idempotent rule for direct client callers.)
- See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).

## Upgrading

Behaviour changes to be aware of:

1. Listed paths no longer include the disk's `url_path_prefix`.
2. `setVisibility()` no longer moves objects between storage tiers unless `visibility_mapping` is set; without it, it throws `UnableToSetVisibility`.
3. Private keys can no longer be loaded from a URL.
4. `OciClient::listObjects()` throws on API errors instead of returning `[]`.
5. `upload.chunk_size` default rose from 8 MiB to 32 MiB; the minimum is 10 MiB.
6. `deleteDirectory()`, storage tier changes and restores used to report success silently; they now either work or throw.

See [CHANGELOG.md](CHANGELOG.md).

## Documentation

[Configuration](docs/CONFIGURATION.md) · [Authentication](docs/AUTHENTICATION.md) · [Examples](docs/EXAMPLES.md) · [Testing](docs/TESTING.md) · [Troubleshooting](docs/TROUBLESHOOTING.md) · [API reference](docs/API_REFERENCE.md)

Some of the other guides under `docs/` pre-date the transport and configuration rewrite and may describe options that no longer exist; `docs/CONFIGURATION.md` and this README are authoritative.

## Testing

```bash
composer test
composer analyse
```

## Contributing, support and license

See [CONTRIBUTING.md](CONTRIBUTING.md). Issues: [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues). Security: [security@mohamedhabib.work](mailto:security@mohamedhabib.work). Licensed under the MIT License, see [LICENSE.md](LICENSE.md).

Created by [Mohamed Habib](https://github.com/mohamedhabibwork).
