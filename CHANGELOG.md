# Changelog

All notable changes to `laravel-oci-driver` will be documented in this file.

## Unreleased

### Changed

- Listed paths no longer include the disk's `url_path_prefix`.
- `setVisibility()` no longer moves objects between storage tiers unless `visibility_mapping` is set; without a mapping it throws `UnableToSetVisibility`.
- Private keys can no longer be loaded from a URL.
- `OciClient::listObjects()` throws on API errors instead of returning `[]`.
- `upload.chunk_size` default raised from 8 MiB to 32 MiB; values below 10 MiB are raised to 10 MiB.
- Operations that silently "succeeded" (`deleteDirectory`, storage tier change, restore) now either work or throw.

### Added

- Native OCI multipart upload for large or unknown-size streams, with abort on failure and a streamed single PUT for smaller writes.
- Paginated, lazy `listContents()` with prefix stripping and directory entries.
- Async `copy()` with work-request polling (`copy_timeout`, `copy_poll_interval`) and atomic `move()` via `renameObject`.
- `oci:par:prune {disk=oci} {--dry-run}` command to delete expired pre-authenticated requests created by the package.
- Shared Guzzle client with retries (connection errors, 429, 5xx), exponential backoff and request/connect timeouts.
- Multiple `oci` disks, each built from its own config, with optional `connection` inheritance; `oci:config --validate --disk=`.
- Private key sources `private_key`, `private_key_base64` and `key_path`, plus `passphrase`.
- Pooled parallel deletes (`delete_concurrency`), per-write `checksum`, `content_type`, `storage_tier` and `metadata` options.
- Optional `visibility_mapping`; temporary URL `access_type` option and `temporary_url` expiry settings.
- CI matrix for PHP 8.2 to 8.5 and Laravel 10 to 13.
