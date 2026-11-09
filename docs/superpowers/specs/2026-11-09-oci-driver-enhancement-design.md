# laravel-oci-driver: reliability and features enhancement

- **Date:** 2026-11-09
- **Branch:** `feat/reliability-and-features` → PR into `main`, then tag `v1.1.0` (tag only after approval)
- **Status:** design approved in conversation; this spec awaits review

## 1. Goal and constraints

Make every Flysystem operation of the OCI Object Storage adapter work correctly against the
native OCI API, safe for large files and large buckets, and covered by tests. Add the missing
production features (multipart upload, pagination, async copy, PAR options).

Constraints agreed with the maintainer:

- **No breaking changes.** Disk config keys, class names, public method signatures, Artisan
  command signatures and config-file keys stay. The only intentional behaviour changes are
  listed in §8 and go in the CHANGELOG.
- **Tests use faked HTTP** (Guzzle `MockHandler`) inside the package and run in CI. Real-bucket
  checks are manual, from a consuming app.
- **Scope:** reliability + new features. No restructuring of package layout beyond what a fix
  needs.

### Success criteria

1. `spatie/laravel-backup` run → list → clean → monitor works on an `oci` disk whose backup name
   contains spaces, with a backup zip larger than the multipart threshold, with flat memory use.
2. Two disks using the `oci` driver with different buckets read and write their own bucket.
3. `copy`, `move`, `deleteDirectory`, `directoryExists`, `updateStorageTier`, `restore`,
   `temporaryUrl` each succeed against the documented OCI API (verified by request-shape tests).
4. Each `FilesystemAdapter` method throws the exception type its Flysystem v3 contract names.
5. CI green on PHP 8.2–8.5 × Laravel 10–13 (valid combinations), Pint and PHPStan included.

## 2. Problems being fixed (from the audit at b98c3f5)

| Area | Problem | Consequence |
|---|---|---|
| Service provider | `OciConfig::fromDisk($config['driver'])` reads `disks.oci` for every disk | Multiple disks impossible; temp-URL callback can target another bucket |
| `deleteDirectory` | Calls S3-compat `?delete` on native endpoint; error swallowed | Reports success, deletes nothing |
| `copy` | Requires 200; CopyObject is async (202 + work request); wrong body fields | Always throws |
| `move` | copy+delete fallback on any error | Would delete source before async copy finishes |
| Tier / restore | Wrong endpoints and payloads; errors swallowed | Never succeed |
| Visibility | `private` → Archive tier | Object unreadable until restored once tier calls work |
| `visibility()` | Reads key `storage-tier`, client returns `storage_tier` | Always `public` |
| Metadata | Sent as `x-amz-meta-*` | Silently dropped (native API uses `opc-meta-*`) |
| `writeStream` | `stream_get_contents` whole body; ignores Config; leaks finfo | Memory blow-up on large backups |
| `listContents` | limit 1000, no paging, no `fields`, prefixed names, files for dirs | Truncated listings; null size/mtime; wrong paths |
| Prefix | `str_starts_with($path, $prefix)` without `/`; re-applied in client | `localfoo` treated as prefixed |
| Errors | `listObjects` returns `[]`, PAR returns `''`, wrong exception classes | Failures look like success |
| HTTP | New Guzzle client + new Signer per request; no timeout/retry | Slow, key re-read every call, hangs possible |
| Signer | `DATE_RFC7231` (deprecated 8.5); null body into `strlen`; key may be a URL | Deprecations; SSRF-ish risk |
| Key providers | Never wired into the client | Env/base64 keys unusable |
| Composer | Missing runtime deps; PHP constraint excludes 8.5; facade alias not FQCN | Fragile installs |
| Tests/CI | No adapter/Signer/HTTP tests; client not injectable; missing key fixture; bad matrix | No safety net |

## 3. Architecture

Same classes, clearer responsibilities:

- **`OciClient`** owns transport: one shared `GuzzleHttp\ClientInterface` per disk, one `Signer`,
  prefix rules, and thin typed methods per OCI endpoint. It never applies the prefix to a path
  that the adapter already prefixed (see §4.3).
- **`OciAdapter`** owns Flysystem semantics: path prefixing, attribute mapping, exception mapping.
- **`Signer`** owns request signing only; created once per `OciClient`.
- **New internal helpers** (in `src/Support/`, `@internal`, not public API):
  - `MultipartUploader` – create / upload parts / commit / abort.
  - `WorkRequestPoller` – polls `GET /workRequests/{id}`.
  - `ObjectNameEncoder` – one place for object-path encoding (`rawurlencode`, keep `/` encoded as
    today to match existing objects).
  - `ResponseGuard` – turns a PSR-7 response into either success or an `OciRequestFailed`
    internal exception carrying status, `opc-request-id`, and OCI error `code`/`message`.

### 3.1 HTTP client and retries

- `OciClient::createWithConfiguration(array|OciConfig $config, ?ClientInterface $http = null)` and
  `fromOciConfig(OciConfig $c, ?ClientInterface $http = null)`: new optional last parameter.
- Default client: Guzzle with `http_errors => false`, `timeout` (default 30),
  `connect_timeout` (default 10), `allow_redirects => false`, and a retry middleware for
  connection errors, 429 and 5xx, `retry_attempts` (default 3) with exponential backoff starting at
  `retry_delay` ms (default 1000). A request whose body stream is not seekable is never retried
  (it would resend an empty or partial body). Retries only for idempotent requests (GET/HEAD/PUT/DELETE) and
  for POST endpoints OCI documents as safe to retry with an `opc-retry-token` (multipart create and
  commit, copyObject — the client sends a UUID retry token on those).
- Because the request body stream is re-read on retry, multipart part uploads use seekable
  per-part buffers (§4.1).
- `send()` keeps its signature and return type; it now goes through the shared client and accepts
  a `StreamInterface|string|null` body (string still works).

### 3.2 Signer

- Constructed once per client; private key loaded once via a `KeyProviderInterface`.
- Key source precedence: `private_key` (PEM string) → `private_key_base64` → `key_path` (file).
  `FileKeyProvider` / `EnvironmentKeyProvider` are used for these. URL-based keys are removed.
- Optional `passphrase` (documented in README, currently unimplemented) is passed to
  `openssl_pkey_get_private`.
- Date header uses `gmdate('D, d M Y H:i:s \G\M\T')`.
- Body headers (`content-length`, `content-type`, `x-content-sha256`) are signed for POST/PUT with a
  string body. For `PutObject` and `UploadPart` with a stream body, body headers are **excluded**
  from the signing string (allowed by OCI for these two operations), so the body is never hashed in
  memory.
- Null body is treated as `''`.
- Remove nonsense `config('OCI_*')` fallbacks in the constructor; config comes from `OciConfig`.

### 3.3 Configuration

- The service provider builds each disk from **that disk's own `$config` array**. If the disk sets
  `'connection' => 'name'`, values from `laravel-oci-driver.connections.name` are merged underneath
  the disk values (disk wins).
- Validation at disk build: required keys `namespace`, `region`, `bucket`, `tenancy_id`,
  `user_id`, `key_fingerprint`, and one key source must be non-empty strings. Failure throws
  `InvalidArgumentException` naming the disk and the missing keys.
- Existing keys reused: `timeout`, `connect_timeout`, `retry_attempts`, `retry_delay`,
  `temporary_url.default_expiry`, `temporary_url.max_expiry`, `upload.chunk_size`,
  `upload.multipart_threshold`, `upload.enable_checksum`, `url_path_prefix`, `storage_tier`.
- New keys (all optional, safe defaults):
  - `delete_concurrency` (int, default 10)
  - `copy_timeout` (seconds, default 300) and `copy_poll_interval` (ms, default 1000)
  - `visibility_mapping` (array|null, default null → visibility does not touch tiers)
- `upload.chunk_size` default becomes 32 MiB; values below 10 MiB are raised to 10 MiB (OCI part
  minimum, except the last part). `upload.multipart_threshold` default stays 100 MiB.
- The bundled `backup` connection example replaces `date('Y/m')` (frozen by `config:cache`) with a
  static prefix and a comment.

## 4. Operations

### 4.1 Write (`write`, `writeStream`)

- One internal path. Size is known for strings; for streams use `fstat`/`Content-Length` when
  available, else treat as unknown.
- Size ≤ `multipart_threshold`: single `PUT /o/{name}` with the stream body (not buffered).
  `Content-MD5` only when `upload.enable_checksum` is true **and** the body is a string.
- Size > threshold or unknown: native multipart:
  1. `POST /u` `{object, contentType, storageTier, metadata}` → `uploadId`
  2. read `chunk_size` bytes into a seekable temp stream, `PUT /u/{name}?uploadId=&uploadPartNum=n`,
     record `ETag`; repeat (parts numbered 1..10000; error if exceeded)
  3. `POST /u/{name}?uploadId=` `{partsToCommit:[{partNum, etag}]}`
  4. on any failure: `DELETE /u/{name}?uploadId=` (best effort), then throw `UnableToWriteFile`
- Config honoured on both paths: `storage_tier`, `content_type` (else extension-based detection,
  else `application/octet-stream`), `metadata` → `opc-meta-{key}` headers / `metadata` JSON field.
- Peak memory ≈ one part.

### 4.2 Read

- `read`: GET, 404 → `UnableToReadFile`.
- `readStream`: GET with `stream => true`; returns the detached PHP resource.
- `fileSize`, `lastModified`, `mimeType`: HEAD; missing header → `UnableToRetrieveMetadata`.

### 4.3 Paths and prefix

- Adapter is the only place that adds the prefix: `prefix/ + path`.
- "Already prefixed" check is `path === prefix || str_starts_with(path, prefix.'/')`.
  `getPrefixedPath()` keeps working for external callers with this corrected rule.
- Client endpoint methods called by the adapter receive full object names and do not re-prefix.
  Public client methods that callers may use directly (`listObjects`, `listPrefixedObjects`,
  `bulkDelete`, `renameObject`, `getObjectInfo`, etc.) keep their current "apply prefix" behaviour
  via the corrected idempotent rule, so external callers are unaffected.
- Listings strip the prefix with `removePrefixFromPath()`.

### 4.4 List (`listContents`)

- Generator. Loop `GET /o?prefix=&delimiter=&start=&limit=1000&fields=name,size,timeCreated,timeModified,md5,storageTier`
  until `nextStartWith` is absent.
- Files → `FileAttributes` (size, `lastModified` from `timeModified`, extra: etag, md5,
  storage_tier). Prefixes (non-deep) and `dir/` placeholder objects → `DirectoryAttributes`.
  The listed directory's own placeholder is skipped.
- Errors → `UnableToListContents`.
- `OciClient::listObjects()` keeps its array return for BC but throws instead of returning `[]`
  on failure. Any adapter-level caller uses the generator.

### 4.5 Existence and directories

- `fileExists`: HEAD; 200 → true, 404 → false, else `UnableToCheckExistence`.
- `directoryExists`: list with `prefix=dir/`, `limit=1`; non-empty → true.
- `createDirectory`: PUT empty object `dir/` (trailing slash).
- `deleteDirectory`: iterate the listing (deep) and delete each object, using a Guzzle `Pool` with
  `delete_concurrency`. 404 on an individual delete counts as success. Any other failure →
  `UnableToDeleteDirectory` listing the failed keys (first 20).
- `delete`: DELETE; 200/204/404 → success; else `UnableToDeleteFile`.
- `OciClient::bulkDelete(array $paths): array` keeps its signature; it now does pooled native
  DELETEs and returns `['deleted' => [...], 'errors' => [...]]` as today.

### 4.6 Copy and move

- `copy`: `POST /actions/copyObject` with `sourceObjectName`, `destinationRegion`,
  `destinationNamespace`, `destinationBucket`, `destinationObjectName`, optional
  `destinationObjectMetadata` and `destinationObjectStorageTier` from Config. Expect 202 and
  `opc-work-request-id`; poll `GET https://objectstorage.{region}.oraclecloud.com/workRequests/{id}`
  every `copy_poll_interval` ms until `COMPLETED` (success), `FAILED`/`CANCELED` or `copy_timeout`
  (→ `UnableToCopyFile`).
- `move`: `POST /actions/renameObject` `{sourceName, newName}` (synchronous, same bucket). Failure →
  `UnableToMoveFile`. No copy+delete fallback.

### 4.7 Visibility, storage tier, restore

- OCI has no per-object ACL. Default: `setVisibility` throws `UnableToSetVisibility` explaining
  that visibility is controlled at bucket level; `visibility()` returns the configured disk
  `visibility` (default `private`).
- Opt-in `visibility_mapping` (e.g. `['public' => 'Standard', 'private' => 'InfrequentAccess']`)
  restores tier-mapping behaviour for those who want it.
- `updateStorageTier()` / `OciClient::updateObjectStorageTier()`: `POST /actions/updateObjectStorageTier`
  `{objectName, storageTier}`. Both keep the `bool` return type for BC. The adapter method
  (`OciAdapter::updateStorageTier`) returns `true` on success and throws `UnableToWriteFile` on
  failure; the client method (`OciClient::updateObjectStorageTier`) returns `false` on a 4xx/5xx
  after logging, matching its current contract.
- `restore()` / `restoreObjects()`: `POST /actions/restoreObjects` `{objectName, hours}` per object.

### 4.8 URLs

- `getUrl`: `https://objectstorage.{region}.oraclecloud.com/n/{ns}/b/{bucket}/o/{encoded name}`
  using `getHost()`.
- `createTemporaryUrl($path, $expiresAt, $options = [])`: `POST /p/`
  `{name, objectName, accessType, timeExpires}`; `accessType` from `$options['access_type']`
  (default `ObjectRead`); expiry defaults to `temporary_url.default_expiry` and is capped at
  `temporary_url.max_expiry`. Failure throws when `throw_exceptions` is true, else logs and returns
  `''` (current behaviour).
- New command `oci:par:prune {disk=oci} {--dry-run}`: lists PARs (`GET /p/`, paginated) whose name
  starts with the driver's PAR name prefix and `timeExpires` is past, and deletes them.

### 4.9 Exception mapping

| Method | Exception |
|---|---|
| fileExists / directoryExists | `UnableToCheckExistence` |
| read / readStream | `UnableToReadFile` |
| write / writeStream | `UnableToWriteFile` |
| delete | `UnableToDeleteFile` |
| deleteDirectory | `UnableToDeleteDirectory` |
| createDirectory | `UnableToCreateDirectory` |
| mimeType / lastModified / fileSize / visibility | `UnableToRetrieveMetadata` |
| setVisibility | `UnableToSetVisibility` |
| listContents | `UnableToListContents` |
| copy | `UnableToCopyFile` |
| move | `UnableToMoveFile` |

Each wraps the internal `OciRequestFailed` (status, `opc-request-id`, OCI code/message) as
`previous`. Signer/key errors raised during a call are wrapped the same way.

## 5. Other cleanup

- `LaravelOciDriver` static helpers: pass `$options` through to the underlying `Storage` call where
  Laravel supports it (`copyFile`, `moveFile`, `listFiles`), otherwise document that it is ignored.
- `LaravelOciDriver::connection()` and `oci:connection test` work with any disk whose driver is
  `oci` (no longer only `oci_{name}` names that nothing registers).
- `oci:config --validate {disk?}` accepts a disk argument.
- Service provider: remove unused `provides()` and empty `bootHealthChecks`; actually call config
  validation (§3.3).
- Fix facade alias to the FQCN in `composer.json`.

## 6. Composer, CI, docs

- `require`: `php: ^8.2`, `ext-openssl`, `ext-fileinfo`, `guzzlehttp/guzzle: ^7.8`,
  `league/flysystem: ^3.25`, `illuminate/contracts|filesystem|support: ^10.0||^11.0||^12.0||^13.0`,
  `nesbot/carbon: ^2.72||^3.0`, `ramsey/uuid: ^4.7`, `spatie/laravel-package-tools: ^1.16`.
- `require-dev`: `orchestra/testbench: ^8.0||^9.0||^10.0||^11.0` to pair with Laravel 10–13.
- Description: "Laravel Flysystem driver for Oracle Cloud Infrastructure (OCI) Object Storage".
- CI matrix: PHP 8.2–8.5 × Laravel 10–13 with matching testbench, excluding unsupported pairs
  (L10: PHP ≤8.3; L11/12: 8.2–8.5; L13: per its PHP minimum). `prefer-lowest` + `prefer-stable`.
  PHPStan with the baseline regenerated after fixes (should shrink). Pint unchanged. Remove
  `--no-suggest`, fix README badges.
- README rewrite: config reference (incl. new keys), multiple disks, multipart, temporary URLs and
  pruning, visibility on OCI, key sources, troubleshooting (spaces in names, prefix rules).
- CHANGELOG "Unreleased" with §8.

## 7. Testing

- `tests/Support/FakeOci.php`: builds an `OciClient` with a `MockHandler` + history middleware, a
  generated RSA test key in `tests/fixtures/` (fixes missing `test-key.pem`), and helpers to queue
  OCI JSON responses and assert recorded requests.
- Test files (Pest), each with happy path + failure path:
  - `SignerTest` — golden signing string for GET/PUT(string)/PUT(stream, body headers excluded)/POST;
    signature verifies with the public key; date format; null body.
  - `WriteTest` — single PUT headers/metadata/tier/content type; multipart create/parts/commit with
    correct part numbers and ETags; abort on part failure; chunk-size floor.
  - `ReadTest` — read, readStream streams, metadata methods, 404 mapping.
  - `ListTest` — pagination over `nextStartWith`, `fields` param, prefix stripping, `DirectoryAttributes`,
    placeholder skipping, error → exception.
  - `DirectoryTest` — directoryExists, createDirectory, deleteDirectory partial failure.
  - `CopyMoveTest` — copy with poll COMPLETED / FAILED / timeout; move via renameObject.
  - `TierRestoreVisibilityTest` — request bodies; visibility default throws; mapping opt-in.
  - `TemporaryUrlTest` — expiry default/cap, access type, throw_exceptions on/off; prune command
    deletes only expired, prefixed PARs and honours `--dry-run`.
  - `PathEncodingTest` — spaces, `+`, `%`, unicode, nested paths; prefix applied once; `localfoo`.
  - `ServiceProviderTest` — two `oci` disks with different buckets get different clients;
    missing key error names the disk.
  - `RetryTest` — 503 then 200 retried; POST without retry token not retried.
- Flysystem's `FilesystemAdapterTestCase` will be tried against the fake; if it needs a stateful
  server the fake can't reasonably provide, it is skipped and the per-method tests above stand.
- Manual (not CI), from EnjoyDrivingBackend: `backup:run` → `backup:list` → `backup:clean`, plus a
  ~150 MB upload to exercise multipart, then delete it.

## 8. Behaviour changes (CHANGELOG)

1. Listed paths no longer include the disk's `url_path_prefix`.
2. `setVisibility` no longer moves objects between storage tiers unless `visibility_mapping` is set.
3. Private keys can no longer be loaded from a URL.
4. `OciClient::listObjects()` throws on API errors instead of returning `[]`.
5. `upload.chunk_size` default 8 MiB → 32 MiB, minimum 10 MiB.
6. Operations that silently "succeeded" (deleteDirectory, tier change, restore) now either work or
   throw.

## 9. Delivery

Single PR `feat/reliability-and-features` → `main`, layered commits:

1. Foundation: injectable HTTP client, retries, Signer once, PHP 8.5, composer deps, per-disk config.
2. Correctness: paths/prefix, exceptions, read/delete/exists/metadata, visibility.
3. Features: multipart, paginated list, directories, copy/move, tier/restore, PAR + prune.
4. Tests, CI matrix, README, CHANGELOG.

Each fix lands with its tests in the same commit where practical. After merge, propose tag
`v1.1.0` (requires maintainer approval).

## 10. Out of scope

- S3-compatibility API support, server-side encryption keys (SSE-C), object versioning,
  lifecycle policies, bucket management.
- Caching of HEAD/metadata responses (config keys exist but stay unused; documented as such).
- Concurrent multipart part uploads (sequential is enough for backups; can follow later).
