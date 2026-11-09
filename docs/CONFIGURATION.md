# Configuration Guide

This guide lists every configuration key the driver reads. Keys that exist in the published config file but are not read by the code are listed separately as "reserved / currently unused".

---

## How configuration is resolved

Each `oci` disk in `config/filesystems.php` is built from **that disk's own array**. Nothing is read implicitly from other disks.

If the disk sets `'connection' => 'name'`, the values of `laravel-oci-driver.connections.name` are merged underneath the disk values (the disk wins). Use this to share credentials between disks and override only the bucket:

```php
// config/filesystems.php
'disks' => [
    'oci' => [
        'driver' => 'oci',
        'connection' => 'default',            // inherit laravel-oci-driver.connections.default
    ],
    'oci_backups' => [
        'driver' => 'oci',
        'connection' => 'default',
        'bucket' => 'my-backups',             // override
        'url_path_prefix' => 'backups',
        'storage_tier' => 'InfrequentAccess',
    ],
],
```

A connection that exists only under `laravel-oci-driver.connections` and has no matching `filesystems.disks` entry is **not usable**: `Storage::disk()`, `LaravelOciDriver::connection()` and the Artisan commands all need a filesystem disk with `'driver' => 'oci'`. `LaravelOciDriver::connection('x')` resolves the disk named `x`, then falls back to `oci_x`.

### Validation

When a disk is built, these must be non-empty: `namespace`, `region`, `bucket`, `tenancy_id`, `user_id`, `key_fingerprint`, and at least one key source (`private_key`, `private_key_base64` or `key_path`). A missing value throws an `InvalidArgumentException` naming the disk and the missing keys. Check a disk with:

```bash
php artisan oci:config --validate --disk=oci_backups
```

---

## Required keys

| Key | Description | Example |
|---|---|---|
| `driver` | Must be `oci` | `oci` |
| `tenancy_id` | Tenancy OCID | `ocid1.tenancy.oc1..aaaa` |
| `user_id` | User OCID | `ocid1.user.oc1..bbbb` |
| `key_fingerprint` | API key fingerprint | `aa:bb:cc:...` |
| `namespace` | Object Storage namespace | `mytenancyns` |
| `region` | Region identifier | `me-jeddah-1` |
| `bucket` | Bucket name | `my-bucket` |
| key source | One of the three below | see below |

### Key sources

The signer picks the first one that is set, in this order:

1. `private_key` – the PEM contents as a string (for example from a secret manager).
2. `private_key_base64` – the PEM, base64-encoded (convenient for single-line env vars).
3. `key_path` – a local filesystem path to the PEM file. URLs are rejected.

`passphrase` is used when the key is encrypted.

---

## Optional keys

| Key | Default | Description |
|---|---|---|
| `connection` | – | Name under `laravel-oci-driver.connections` to inherit values from |
| `storage_tier` | `Standard` | Default tier for writes: `Standard`, `InfrequentAccess`, `Archive` |
| `url_path_prefix` | `''` | Prefix applied to every object name (see below) |
| `timeout` | `30` | Request timeout, seconds |
| `connect_timeout` | `10` | Connect timeout, seconds |
| `retry_attempts` | `3` | Retries for connection errors, 429 and 5xx |
| `retry_delay` | `1000` | First retry delay in ms; backs off exponentially |
| `delete_concurrency` | `10` | Parallel DELETE requests for `deleteDirectory` / bulk delete |
| `copy_timeout` | `300` | Seconds to wait for an async copy work request |
| `copy_poll_interval` | `1000` | Milliseconds between work request polls |
| `visibility` | `private` | Value reported by `visibility()` when no `visibility_mapping` is set |
| `visibility_mapping` | `null` | Opt-in map of visibility name to storage tier (see README) |
| `upload.multipart_threshold` | `104857600` (100 MiB) | Streams larger than this, or of unknown size, use multipart |
| `upload.chunk_size` | `33554432` (32 MiB) | Multipart part size. Values below 10 MiB (`10485760`) are raised to 10 MiB, the OCI part minimum |
| `upload.enable_checksum` | `true` | Send `Content-MD5` on single-PUT uploads of string bodies |
| `temporary_url.default_expiry` | `3600` | Default PAR lifetime, seconds |
| `temporary_url.max_expiry` | `86400` | Longest PAR lifetime allowed; longer requests are capped |
| `passphrase` | – | Passphrase for an encrypted private key |

Retries only apply to idempotent requests and to the POST endpoints that carry an `opc-retry-token` (multipart create/commit, copyObject). A request whose body stream is not seekable is never retried.

### Global setting

| Key | Default | Description |
|---|---|---|
| `laravel-oci-driver.global.throw_exceptions` | `true` | When `false`, a failed `temporaryUrl()` is logged and returns `''` instead of throwing |

### Per-write options

Pass these in the `Config` / options array of `put`, `putFile`, `writeStream` and similar:

| Option | Description |
|---|---|
| `storage_tier` | Tier for this object |
| `content_type` | Content type; otherwise detected from the extension, then `application/octet-stream` |
| `metadata` | Array stored as `opc-meta-*` headers |
| `checksum` | `false` skips `Content-MD5` for this write |

### `url_path_prefix`

All object names are stored as `prefix/path`. Leading and trailing slashes are normalised and an empty value disables the prefix. Paths returned by listings do **not** include the prefix. A path is treated as already prefixed only if it equals the prefix or starts with `prefix/` (so `localfoo` is not mistaken for being under `local`). Use a static value: expressions such as `date('Y/m')` are frozen by `config:cache`.

---

## Environment variables used by the published config

The `default` connection in `config/laravel-oci-driver.php` reads: `OCI_TENANCY_ID`, `OCI_USER_ID`, `OCI_KEY_FINGERPRINT`, `OCI_KEY_PATH`, `OCI_NAMESPACE`, `OCI_REGION`, `OCI_BUCKET`, `OCI_STORAGE_TIER`, `OCI_TIMEOUT`, `OCI_CONNECT_TIMEOUT`, `OCI_RETRY_ATTEMPTS`, `OCI_RETRY_DELAY`, `OCI_TEMP_URL_EXPIRY`, `OCI_TEMP_URL_MAX_EXPIRY`, `OCI_UPLOAD_CHUNK_SIZE`, `OCI_MULTIPART_THRESHOLD`, `OCI_ENABLE_CHECKSUM`, `OCI_URL_PATH_PREFIX`. The other bundled connections (`production`, `tenant`, `development`, `backup`) are examples that use prefixed variables such as `OCI_PROD_*`.

`OCI_THROW_EXCEPTIONS` feeds `global.throw_exceptions`.

---

## Reserved / currently unused

These keys appear in the config file for forward compatibility but nothing reads them yet, so changing them has no effect on I/O:

- `cache.enabled`, `cache.ttl`, `cache.prefix`
- `logging.enabled`, `logging.level`, `logging.channel` (only echoed by `oci:config` / `oci:connection` summaries)
- `global.log_errors`, `global.enable_gzip`, `global.connection_pool_size`, `global.verify_ssl`, `global.user_agent`
- `content_type.*` (use the per-write `content_type` option instead)
- `connection_type`

---

## Example

```php
'oci' => [
    'driver' => 'oci',
    'tenancy_id' => env('OCI_TENANCY_ID'),
    'user_id' => env('OCI_USER_ID'),
    'key_fingerprint' => env('OCI_KEY_FINGERPRINT'),
    'private_key_base64' => env('OCI_PRIVATE_KEY_BASE64'),
    'namespace' => env('OCI_NAMESPACE'),
    'region' => env('OCI_REGION'),
    'bucket' => env('OCI_BUCKET'),
    'url_path_prefix' => 'uploads',
    'upload' => [
        'multipart_threshold' => 100 * 1024 * 1024,
        'chunk_size' => 32 * 1024 * 1024,   // minimum 10 MiB
    ],
    'delete_concurrency' => 10,
],
```

See the README for temporary URLs, visibility and the Artisan commands, and [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common errors.
