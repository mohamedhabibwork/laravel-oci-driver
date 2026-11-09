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
- `OciClient::getObjectInfo()` now throws `OciRequestFailed` on any status other than 200 and 404 (it used to treat them as "not found").
- `OciClient::restoreObjects([])` no longer validates `$hours`.
- `deleteDirectory()` refuses to delete the bucket root on a disk without `url_path_prefix` (`UnableToDeleteDirectory`, no request is sent); deleting the root of a prefixed disk is still allowed.
- Upload PUTs (single PUT and every multipart part) are exempt from the total request `timeout`; `connect_timeout` still applies.
- The adapter always applies `url_path_prefix`: a path that happens to start with the prefix text is no longer treated as already prefixed (with prefix `backups`, `write('backups/a')` stores `backups/backups/a`). `OciClient::getPrefixedPath()` and the other public client methods keep the idempotent rule.
- User metadata keys are sent as `opc-meta-*` in multipart-create and copy requests (keys already starting with `opc-meta-` are kept).
- `updateStorageTier()` with an unknown tier string now throws `UnableToWriteFile` instead of silently using `Standard`.
- Signer/key errors, JSON encoding errors and invalid request values raised inside adapter operations are mapped to the operation's Flysystem exception; `OciClient::renameObject()` returns `false` on JSON encoding errors.
- Listing requests encode spaces as `%20`, `etag` is requested on listings, and `copy_poll_interval` is clamped to at least 100 ms (`copy_timeout` to at least 0).

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
- CI matrix for PHP 8.2 to 8.5 and Laravel 11 to 13 (Laravel 10 stays allowed by the constraints but is not tested in CI).
