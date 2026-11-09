# laravel-oci-driver Reliability & Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every Flysystem operation of the OCI adapter correct against the native OCI API, safe for large files and buckets, and covered by faked-HTTP tests, without breaking the public API.

**Architecture:** `OciClient` gets an injectable shared Guzzle client and a once-built `Signer`; small `@internal` helpers in `src/Support/` (encoder, response guard, multipart uploader, work-request poller, HTTP factory) carry the new logic; `OciAdapter` keeps Flysystem semantics and exception mapping. Tests drive everything through a Guzzle `MockHandler` + history middleware.

**Tech Stack:** PHP 8.2–8.5, Laravel 10–13, league/flysystem 3, guzzlehttp/guzzle 7, Pest 3/4, Orchestra Testbench.

**Spec:** `docs/superpowers/specs/2026-11-09-oci-driver-enhancement-design.md` (read it with this plan; section refs below are `§n`).

## Global Constraints

- No breaking changes: disk config keys, class names, public method signatures, Artisan signatures, config-file keys keep working. New parameters are optional and appended last.
- Behaviour changes allowed only those in spec §8.
- `php: ^8.2`; must run without deprecations on PHP 8.5.
- Object names encoded with `rawurlencode` of the full name (slash becomes `%2F`, as today).
- Defaults: `timeout` 30, `connect_timeout` 10, `retry_attempts` 3, `retry_delay` 1000 ms, `upload.multipart_threshold` 104857600, `upload.chunk_size` 33554432 with floor 10485760, `delete_concurrency` 10, `copy_timeout` 300 s, `copy_poll_interval` 1000 ms, `temporary_url.default_expiry` 3600, `temporary_url.max_expiry` 86400, `visibility_mapping` null.
- OCI list `fields` value exactly `name,size,timeCreated,timeModified,md5,storageTier`; page `limit` 1000.
- Multipart part numbers 1..10000.
- Every `FilesystemAdapter` method throws the exception in spec §4.9, with the internal `OciRequestFailed` as `previous`.
- Commits: conventional (`feat:`, `fix:`, `test:`, `docs:`, `ci:`, `chore:`), no attribution lines.
- Run `vendor/bin/pint` on touched PHP files before each commit.

## Review Focus

1. **Empty content** (`write('a.txt', '')`, zero-length stream) → a single PUT with `Content-Length: 0`, never a multipart upload with zero parts. Test added in Task 6.
2. **Stream not at position 0 or non-seekable** (e.g. a pipe) → upload sends from the current position; unknown size goes multipart; a non-seekable single PUT is not retried. Tests added in Tasks 3 and 6.
3. **Leading slash / unicode / `+` / `%` / spaces in paths** → `/a b/ü+%.txt` with prefix `local` stores `local/a b/ü+%.txt` and lists back as `a b/ü+%.txt`. Test added in Task 5.
4. **Directory with both a `dir/` placeholder and children, across more than one page** → listing pages fully, yields one `DirectoryAttributes` per directory, no duplicates, placeholder of the listed dir skipped. Test added in Task 7.
5. **Temporary URL with an expiry in the past or beyond max** → past expiry throws `InvalidArgumentException` (no PAR created); beyond max is capped. Test added in Task 10.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Support/HttpClientFactory.php` (new) | Build default Guzzle client: timeouts, `http_errors=false`, retry middleware |
| `src/Support/RetryDecider.php` (new) | Decide whether a request/response pair is retried and the delay |
| `src/Support/ObjectNameEncoder.php` (new) | Encode object names for URIs |
| `src/Support/ResponseGuard.php` (new) | Check status; build `OciRequestFailed` |
| `src/Exception/OciRequestFailed.php` (new, `@internal`) | Status, opc-request-id, OCI code/message |
| `src/Support/MultipartUploader.php` (new) | Create / parts / commit / abort |
| `src/Support/WorkRequestPoller.php` (new) | Poll `/workRequests/{id}` |
| `src/Commands/OciParPruneCommand.php` (new) | `oci:par:prune` |
| `src/OciClient.php` | Transport, endpoint methods, prefix rules |
| `src/Signer.php` | Signing only |
| `src/OciAdapter.php` | Flysystem semantics |
| `src/Config/OciConfig.php` | `fromDiskConfig()`, validation |
| `src/LaravelOciDriverServiceProvider.php` | Per-disk build, temp-URL callback, command registration |
| `tests/Support/FakeOci.php` (new) | MockHandler harness |
| `tests/fixtures/test-key.pem`, `tests/fixtures/test-key.pub` (new) | RSA 2048 test pair |

---

### Task 1: Test harness, composer dependencies, injectable HTTP client

**Files:**
- Modify: `composer.json` (require/require-dev per spec §6, description, facade alias FQCN `LaravelOCI\\LaravelOciDriver\\Facades\\LaravelOciDriverFacade`)
- Create: `tests/fixtures/test-key.pem`, `tests/fixtures/test-key.pub` (`openssl genrsa -out tests/fixtures/test-key.pem 2048 && openssl rsa -in tests/fixtures/test-key.pem -pubout -out tests/fixtures/test-key.pub`)
- Modify: `tests/TestCase.php` (`key_path` → `__DIR__.'/fixtures/test-key.pem'`)
- Create: `tests/Support/FakeOci.php`
- Modify: `src/OciClient.php` (constructor, factories, `send`)
- Test: `tests/Unit/OciClientTransportTest.php`

**Interfaces:**
- Produces:
  - `OciClient::createWithConfiguration(array|OciConfig $config, ?\GuzzleHttp\ClientInterface $http = null): self`
  - `OciClient::fromOciConfig(OciConfig $c, ?\GuzzleHttp\ClientInterface $http = null): self`
  - private ctor `__construct(array $config, ClientInterface $http, Signer $signer)`
  - `OciClient::send(string $uri, string $method, array $header = [], \Psr\Http\Message\StreamInterface|string|null $body = null, ?string $contentType = 'application/json', array $options = []): \Psr\Http\Message\ResponseInterface` — `$options` merged into Guzzle request options (e.g. `['stream' => true]`).
  - `FakeOci` (test helper):
    - `FakeOci::make(array $configOverrides = []): FakeOci`
    - `->client(): OciClient`, `->adapter(): OciAdapter`
    - `->queue(\Psr\Http\Message\ResponseInterface|\Throwable ...$r): self`
    - `FakeOci::json(int $status, array $body, array $headers = []): Response`
    - `->requests(): list<RequestInterface>`, `->lastRequest(): RequestInterface`, `->history(): list<array{request, options}>`
  - Base config used by `FakeOci`: namespace `ns`, region `me-jeddah-1`, bucket `bkt`, tenancy `ocid1.tenancy.oc1..t`, user `ocid1.user.oc1..u`, fingerprint `aa:bb`, key `tests/fixtures/test-key.pem`, `url_path_prefix` `''`, `storage_tier` `Standard`, `retry_attempts` 0.

- [ ] **Step 1:** `composer update` in the package; Expected: installs, `vendor/bin/pest` runs the existing suite. Record which existing tests already fail — they are pre-existing, not regressions.
- [ ] **Step 2: Write failing test** `tests/Unit/OciClientTransportTest.php`:
  - `it sends through the injected client` — `FakeOci::make()->queue(new Response(200))`; `client()->send('https://objectstorage.me-jeddah-1.oraclecloud.com/n/ns/b/bkt/o/a', 'GET')`; assert 1 recorded request, method GET, header `authorization` starts with `Signature version="1"`.
  - `it does not throw on 4xx` — queue `new Response(404)`; `send(...)->getStatusCode()` is 404.
  - `it passes request options` — `send(..., options: ['stream' => true])`; history entry `options['stream'] === true`.
- [ ] **Step 3:** Run `vendor/bin/pest tests/Unit/OciClientTransportTest.php` — Expected: FAIL (`createWithConfiguration` has no `$http` parameter).
- [ ] **Step 4: Implement** constructor/factories/`send` per Interfaces. Default `$http` is a Guzzle client with `http_errors=false`, `allow_redirects=false`, `timeout`, `connect_timeout` (Task 3 moves this into `HttpClientFactory` with retries). Build the `Signer` once in the factory. `send` merges auth headers from `$this->signer`.
- [ ] **Step 5:** Run the test file — Expected: PASS. Run whole suite — Expected: no new failures vs Step 1.
- [ ] **Step 6: Commit** `feat: inject shared HTTP client into OciClient and add test harness`

### Task 2: Signer — PHP 8.5, key providers, streamed bodies

**Files:**
- Modify: `src/Signer.php`, `src/KeyProvider/FileKeyProvider.php`, `src/KeyProvider/EnvironmentKeyProvider.php`, `src/OciClient.php` (factory + `send`)
- Test: `tests/Unit/SignerTest.php`

**Interfaces:**
- Consumes: `FakeOci` (Task 1).
- Produces:
  - `Signer::fromConfig(array $config): self` — key source precedence `private_key` → `private_key_base64` → `key_path`; passes `passphrase`. Throws `PrivateKeyFileNotFoundException` when none resolves.
  - `Signer::getHeaders(string $url, string $method = 'GET', ?string $body = null, ?string $contentType = 'application/json', ?string $dateString = null, bool $excludeBodyHeaders = false): array` (new last param).
  - Existing constructor signature unchanged; `config('OCI_*')` fallbacks removed (null → `''`).
  - Private key parsed once and cached on the instance.

- [ ] **Step 1: Write failing tests** in `SignerTest`:
  - `date header uses GMT format` — `getHeaders(url,'GET')` contains a line matching `/^date: \w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/`.
  - `signing string is stable` — `$dateString = 'Mon, 09 Nov 2026 06:00:00 GMT'`, GET `https://objectstorage.me-jeddah-1.oraclecloud.com/n/ns/b/bkt/o/a%20b?x=1`; signing string equals `"date: Mon, 09 Nov 2026 06:00:00 GMT\n(request-target): get /n/ns/b/bkt/o/a%20b?x=1\nhost: objectstorage.me-jeddah-1.oraclecloud.com"`.
  - `post string body signs body headers` — POST body `{"a":1}`; signed header list is `date (request-target) host content-length content-type x-content-sha256`; `x-content-sha256` = `base64_encode(hash('sha256','{"a":1}',true))`.
  - `put with excluded body headers signs only base headers` — PUT, `excludeBodyHeaders: true`; header list `date (request-target) host`.
  - `null body for post hashes empty string` — under an error handler that throws on any notice/deprecation; sha equals `base64_encode(hash('sha256','',true))`.
  - `signature verifies` — `openssl_verify(signingString, base64_decode(sig), file_get_contents('tests/fixtures/test-key.pub'), OPENSSL_ALGO_SHA256) === 1`.
  - `fromConfig reads private_key_base64` — base64 of the fixture PEM; signature verifies.
  - `rejects url key path` — `key_path` = `https://x/key.pem` → `PrivateKeyFileNotFoundException`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Unit/SignerTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.** Date via `gmdate('D, d M Y H:i:s \G\M\T')`. Body hash/length use `$body ?? ''`. `FileKeyProvider` rejects paths matching `#^[a-z][a-z0-9+.-]*://#i`. `OciClient` factory uses `Signer::fromConfig()`; `send()` passes `excludeBodyHeaders: true` when the body is a `StreamInterface` and the method is PUT.
- [ ] **Step 4:** Run SignerTest + OciClientTransportTest — Expected: PASS.
- [ ] **Step 5: Commit** `fix: make Signer PHP 8.5 safe, wire key providers, support streamed bodies`

### Task 3: Retries and timeouts

**Files:**
- Create: `src/Support/HttpClientFactory.php`, `src/Support/RetryDecider.php`
- Modify: `src/OciClient.php` (default client via factory), `tests/Support/FakeOci.php`
- Test: `tests/Unit/RetryTest.php`

**Interfaces:**
- Produces:
  - `HttpClientFactory::make(array $config, ?callable $handler = null): ClientInterface` — `$handler` lets tests inject a MockHandler while keeping the retry middleware.
  - `RetryDecider::__construct(int $maxAttempts, int $baseDelayMs)`; `__invoke(int $retries, RequestInterface $req, ?ResponseInterface $res, ?\Throwable $e): bool`; `delay(int $retries): int` = `baseDelayMs * 2 ** ($retries - 1)`.
  - Retry when: connection exception, or status 429/500/502/503/504; AND method is GET/HEAD/PUT/DELETE, or POST carrying `opc-retry-token`; AND body is seekable (rewound before retry); AND `$retries < $maxAttempts`.

- [ ] **Step 1: Write failing tests** (`retry_delay` 0):
  - `retries 503 then succeeds` — queue 503, 200 → 2 requests, final 200.
  - `stops after max attempts` — `retry_attempts` 2, queue 503×3 → 3 requests, final 503.
  - `does not retry POST without retry token` — queue 503 → 1 request.
  - `retries POST with opc-retry-token` — queue 503, 200 → 2 requests.
  - `does not retry non-seekable body` — PUT with `new NoSeekStream(Utils::streamFor('x'))` → 1 request.
  - `delay doubles` — base 1000: `delay(1)` = 1000, `delay(3)` = 4000.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Unit/RetryTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** with `GuzzleHttp\Middleware::retry($decider, [$decider, 'delay'])`; `FakeOci` builds its client via `HttpClientFactory::make($config, $mockHandler)` plus history.
- [ ] **Step 4:** Run RetryTest and all Unit tests — Expected: PASS.
- [ ] **Step 5: Commit** `feat: add retry and timeout handling to the OCI HTTP client`

### Task 4: Per-disk configuration and validation

**Files:**
- Modify: `src/Config/OciConfig.php`, `src/LaravelOciDriverServiceProvider.php`, `config/laravel-oci-driver.php` (backup example → static prefix; `upload.chunk_size` default 33554432)
- Test: `tests/Feature/ServiceProviderDisksTest.php`

**Interfaces:**
- Produces:
  - `OciConfig::fromDiskConfig(array $diskConfig, string $diskName = 'oci'): self` — merges `laravel-oci-driver.connections.{$diskConfig['connection']}` underneath when `connection` is set; disk wins.
  - `OciConfig::assertValid(string $diskName): void` — throws `InvalidArgumentException("OCI disk '{name}' is missing: a, b")` for empty required keys (`namespace, region, bucket, tenancy_id, user_id, key_fingerprint`, and one of `private_key|private_key_base64|key_path`).
  - `OciConfig::getChunkSize(): int` = `max(10485760, (int) get('upload.chunk_size', 33554432))`.
  - Service provider: `Storage::extend('oci', ...)` uses `fromDiskConfig($config, $config['name'] ?? 'oci')` then `assertValid`; the temp-URL callback reuses the same `OciAdapter` instance. Remove `provides()` and `bootHealthChecks()`.

- [ ] **Step 1: Write failing tests:**
  - `two oci disks use their own bucket` — disks `a` (bucket `one`) and `b` (bucket `two`); the adapter client of `Storage::disk('a')` has `getBucket()` `one`, disk `b` has `two`.
  - `connection values merge under disk values` — `laravel-oci-driver.connections.x.region = r1`, disk `connection => x` with no `region` → `r1`; disk `region => r2` → `r2`.
  - `missing values name the disk` — disk `c` with `bucket => ''` → message contains `OCI disk 'c' is missing: bucket`.
  - `chunk size has a 10 MiB floor` — `upload.chunk_size` 1 → `getChunkSize()` 10485760.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/ServiceProviderDisksTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4:** Run the file + existing `LaravelOciDriverServiceProviderTest` — Expected: PASS.
- [ ] **Step 5: Commit** `fix: build each oci disk from its own configuration`

### Task 5: Paths, encoding, response guard, read/delete/exists/metadata exceptions

**Files:**
- Create: `src/Support/ObjectNameEncoder.php`, `src/Support/ResponseGuard.php`, `src/Exception/OciRequestFailed.php`
- Modify: `src/OciClient.php` (`applyPathPrefix`, `removePrefixFromPath`, `getPrefix`, new `getObjectUri`), `src/OciAdapter.php` (`fileExists`, `read`, `readStream`, `delete`, `mimeType`, `lastModified`, `fileSize`)
- Test: `tests/Unit/PathEncodingTest.php`, `tests/Unit/ReadTest.php`

**Interfaces:**
- Produces:
  - `ObjectNameEncoder::encode(string $objectName): string` = `rawurlencode($objectName)`.
  - `OciClient::getObjectUri(string $objectName): string` = `getBucketUri().'/o/'.ObjectNameEncoder::encode($objectName)` (name already prefixed).
  - Prefix rule: `ltrim($path, '/')`; return unchanged if `prefix === ''`, `path === prefix`, or `str_starts_with(path, prefix.'/')`; else `prefix.'/'.path`. `getPrefix()` returns the prefix trimmed of `/`.
  - `OciRequestFailed extends \RuntimeException` with `public readonly int $status`, `public readonly ?string $requestId`, `public readonly ?string $ociCode`; `static fromResponse(ResponseInterface $r, string $operation): self` (parses JSON `code`/`message`, header `opc-request-id`).
  - `ResponseGuard::ok(ResponseInterface $r, string $operation, int ...$accepted): ResponseInterface` — throws `OciRequestFailed` unless status is in `$accepted` (default `[200]`).
  - Adapter: `fileExists` HEAD 200→true, 404→false, else `UnableToCheckExistence::forLocation($path, $e)`. `read` non-200 → `UnableToReadFile::fromLocation($path, reason, $e)`. `readStream` uses `send(..., options: ['stream' => true])` and returns `$response->getBody()->detach()`. `delete` 200/204/404 ok, else `UnableToDeleteFile::atLocation`. Metadata → `UnableToRetrieveMetadata::fileSize|lastModified|mimeType($path, reason, $e)`; a missing header is a failure.

- [ ] **Step 1: Write failing tests:**
  - PathEncodingTest: `prefix applied once` (prefix `local`: `a`→`local/a`, `local/a`→`local/a`, `localfoo`→`local/localfoo`, `local`→`local`, `/a`→`local/a`); `encodes special characters` (prefix `local`, `write('/a b/ü+%.txt', 'x', new Config)` → last request path ends `/o/local%2Fa%20b%2F%C3%BC%2B%25.txt`); `strips prefix` (`removePrefixFromPath('local/a b/ü+%.txt')` = `a b/ü+%.txt`).
  - ReadTest: `fileExists returns true, false, and throws` (200 / 404 / 500 → `UnableToCheckExistence` whose previous is `OciRequestFailed` with status 500); `read 404 throws UnableToReadFile`; `readStream returns resource and requests stream option`; `fileSize reads content-length`; `fileSize missing header throws UnableToRetrieveMetadata`; `lastModified parses last-modified`; `delete 404 is success`; `delete 500 throws UnableToDeleteFile`.
- [ ] **Step 2:** Run both files — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces; every adapter URI goes through `getObjectUri(applyPathPrefix($path))`.
- [ ] **Step 4:** Run both files + all tests — Expected: PASS.
- [ ] **Step 5: Commit** `fix: correct prefix handling and map read/delete/metadata errors to Flysystem exceptions`

### Task 6: Write — streamed single PUT and native multipart

**Files:**
- Create: `src/Support/MultipartUploader.php`
- Modify: `src/OciAdapter.php` (`write`, `writeStream`; replace finfo detection with `League\MimeTypeDetection\ExtensionMimeTypeDetector`)
- Test: `tests/Unit/WriteTest.php`

**Interfaces:**
- Consumes: `send`, `getObjectUri`, `ResponseGuard` (Tasks 1, 5), `OciConfig::getChunkSize()` (Task 4).
- Produces:
  - `MultipartUploader::__construct(OciClient $client, int $partSize)`
  - `MultipartUploader::upload(string $objectName, StreamInterface $source, array $headers): void`:
    1. POST `{bucketUri}/u` JSON `{object, contentType, storageTier, metadata}` with `opc-retry-token`; read `uploadId`.
    2. Loop: copy up to `$partSize` bytes into a fresh `php://temp` stream; PUT `{bucketUri}/u/{encoded}?uploadId={id}&uploadPartNum={n}`; record response `etag`. More than 10000 parts → `\LengthException`.
    3. POST `{bucketUri}/u/{encoded}?uploadId={id}` JSON `{partsToCommit:[{partNum, etag}]}` with `opc-retry-token`.
    4. On any throwable after step 1: DELETE `{bucketUri}/u/{encoded}?uploadId={id}` (ignore its failure), rethrow.
  - Adapter size rule: string → `strlen`; resource → `fstat()['size'] - ftell()` when both are available, else unknown. Size ≤ `upload.multipart_threshold` → single PUT of `Utils::streamFor($contents)`; larger or unknown → multipart with `getChunkSize()`.
  - Headers (both paths): `storage-tier` from `$config->get('storage_tier')` else client default; `Content-Type` from `$config->get('content_type')` else extension detector else `application/octet-stream`; `opc-meta-{k}` per `$config->get('metadata', [])`; `Content-MD5` only for a string body when `upload.enable_checksum` is true.
  - Failure → `UnableToWriteFile::atLocation($path, reason, $e)`.

- [ ] **Step 1: Write failing tests:**
  - `writes small string with single put` — `write('a.txt','hello', new Config(['metadata'=>['owner'=>'x']]))`: one PUT, headers `content-type: text/plain`, `storage-tier: Standard`, `opc-meta-owner: x`, `content-md5` = `base64_encode(md5('hello', true))`.
  - `writeStream small resource streams single put` — body equals the file contents; no `content-md5` header.
  - `empty content uses single put` — `write('e.txt','')` → exactly 1 request, PUT, `content-length: 0`.
  - `stream not at start uploads remaining bytes` — resource containing `abcdef`, `fseek($h, 2)` → body `cdef`.
  - `large stream uses multipart` — config `upload.multipart_threshold` 5; 12-byte resource → requests: POST `/u` (response `{"uploadId":"u1"}`), PUT `/u/...uploadPartNum=1` with 12-byte body (response header `etag: e1`), POST commit with body exactly `{"partsToCommit":[{"partNum":1,"etag":"e1"}]}`.
  - `uploader splits parts` — `new MultipartUploader($client, 4)` with a 10-byte stream → part PUTs numbered 1,2,3 with bodies of 4,4,2 bytes; commit lists 3 parts in order.
  - `part failure aborts upload` — part PUT returns 500 → a DELETE with `uploadId=u1` is sent and `UnableToWriteFile` is thrown.
  - `unknown size stream goes multipart` — `popen('printf abc', 'r')` resource → first request is POST `/u`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Unit/WriteTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4:** Run the file + all tests — Expected: PASS.
- [ ] **Step 5: Commit** `feat: stream uploads and add native multipart upload`

### Task 7: Listing, directories, bulk delete

**Files:**
- Modify: `src/OciClient.php` (`listObjects`, `listPrefixedObjects`, `bulkDelete`, new `listObjectsPage`), `src/OciAdapter.php` (`listContents`, `directoryExists`, `createDirectory`, `deleteDirectory`)
- Test: `tests/Unit/ListTest.php`, `tests/Unit/DirectoryTest.php`

**Interfaces:**
- Produces:
  - `OciClient::listObjectsPage(string $fullPrefix, ?string $delimiter, ?string $start, int $limit = 1000): array{objects: list<array<string,mixed>>, prefixes: list<string>, nextStartWith: ?string}` — GET `{bucketUri}/o?prefix=&delimiter=&start=&limit=&fields=name,size,timeCreated,timeModified,md5,storageTier` (omit empty params); throws `OciRequestFailed`.
  - `OciClient::listObjects(array $options = []): array` — BC wrapper over one page; throws on error (spec §8.4).
  - `OciAdapter::listContents(string $path, bool $deep): iterable` — generator over pages (delimiter `/` when not deep); files → `FileAttributes(path, size, null, lastModified, null, ['etag'=>..,'md5'=>..,'storage_tier'=>..])`; prefixes and names ending `/` → `DirectoryAttributes(rtrim(path,'/'))`; skip the listed directory's own placeholder; yield each directory once; errors → `UnableToListContents::atLocation($path, $deep, $e)`.
  - `directoryExists` → `listObjectsPage(prefixed.'/', null, null, 1)`, true when objects or prefixes non-empty; errors → `UnableToCheckExistence`.
  - `createDirectory` → PUT empty body to `prefixed.'/'`; error → `UnableToCreateDirectory::atLocation`.
  - `OciClient::bulkDelete(array $paths): array{deleted: list<string>, errors: list<array{path:string,error:string}>}` — Guzzle `Pool` of DELETEs at `delete_concurrency`; 404 counts as deleted.
  - `deleteDirectory` → deep listing in batches of 1000 names → `bulkDelete`; any errors → `UnableToDeleteDirectory::atLocation($path, 'failed to delete: '.implode(', ', first 20 paths))`.

- [ ] **Step 1: Write failing tests:**
  - ListTest: `pages until nextStartWith is absent` (page 1 returns `nextStartWith: "b"`, page 2 none → 2 requests; second query has `start=b`); `requests fields` (query `fields` equals the exact constant, `limit=1000`); `strips prefix and maps attributes` (prefix `local`, object `{name:"local/x/a.txt", size:5, timeModified:"2026-11-09T06:00:00Z"}` → `FileAttributes` path `x/a.txt`, size 5, lastModified 1794204000); `non-deep yields DirectoryAttributes for prefixes` (`prefixes: ["local/x/"]` → `DirectoryAttributes('x')`); `placeholder plus children across two pages yields one directory and skips own placeholder` (listing `d` deep: page 1 `[d/, d/x/, d/x/1]` + `nextStartWith`, page 2 `[d/x/2]` → yields dir `d/x` once, files `d/x/1`, `d/x/2`, no `d`); `api error throws UnableToListContents`.
  - DirectoryTest: `directoryExists true for prefix with objects`; `directoryExists false when empty`; `createDirectory puts trailing slash object` (path ends `%2F`, body empty); `deleteDirectory deletes every listed object` (3 objects → 3 DELETEs); `deleteDirectory partial failure throws naming failed key`; `bulkDelete treats 404 as deleted`.
- [ ] **Step 2:** Run both files — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4:** Run both files + all tests — Expected: PASS.
- [ ] **Step 5: Commit** `feat: paginate listings and fix directory operations`

### Task 8: Copy (async work request) and move (rename)

**Files:**
- Create: `src/Support/WorkRequestPoller.php`
- Modify: `src/OciAdapter.php` (`copy`, `move`), `src/OciClient.php` (`renameObject`)
- Test: `tests/Unit/CopyMoveTest.php`

**Interfaces:**
- Produces:
  - `WorkRequestPoller::__construct(OciClient $client, int $timeoutSeconds, int $intervalMs, ?\Closure $sleep = null)` (`$sleep` defaults to `usleep`; tests pass a no-op).
  - `WorkRequestPoller::wait(string $workRequestId): void` — GET `https://{host}/workRequests/{id}`; `status` `COMPLETED` returns; `FAILED` / `CANCELED` → `\RuntimeException("work request {id} {status}")`; elapsed > timeout → `\RuntimeException("work request {id} timed out")`.
  - Adapter `copy` → POST `{bucketUri}/actions/copyObject` JSON `{sourceObjectName, destinationRegion, destinationNamespace, destinationBucket, destinationObjectName}` plus `destinationObjectMetadata` (Config `metadata`) and `destinationObjectStorageTier` (Config `storage_tier`) only when given; header `opc-retry-token`; expect 202 and header `opc-work-request-id`; `wait()`; failures → `UnableToCopyFile::fromLocationTo($src, $dst, $e)`.
  - `OciClient::renameObject(string $source, string $destination): bool` — POST `{bucketUri}/actions/renameObject` `{sourceName, newName}`; 200 → true, error → false (BC). Adapter `move` uses `ResponseGuard` on the same request and throws `UnableToMoveFile::fromLocationTo`; no copy+delete fallback.

- [ ] **Step 1: Write failing tests:** `copy polls until completed` (202 + `opc-work-request-id: wr1`, then `{"status":"IN_PROGRESS"}`, `{"status":"COMPLETED"}` → 3 requests; copy body has exactly the five base keys); `copy failed work request throws UnableToCopyFile`; `copy times out` (`copy_timeout` 0 → throws after the first poll); `copy sends metadata and tier when given`; `move renames` (prefix `local`, one POST, body `{"sourceName":"local/a","newName":"local/b"}`); `move failure throws UnableToMoveFile and sends no delete`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Unit/CopyMoveTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4:** Run the file + all tests — Expected: PASS.
- [ ] **Step 5: Commit** `fix: implement async copy and atomic move`

### Task 9: Visibility, storage tier, restore

**Files:**
- Modify: `src/OciAdapter.php` (`setVisibility`, `visibility`, `updateStorageTier`, `restore`), `src/OciClient.php` (`updateObjectStorageTier`, `restoreObjects`, `getObjectInfo`)
- Test: `tests/Unit/TierRestoreVisibilityTest.php`

**Interfaces:**
- Produces:
  - `OciClient::updateObjectStorageTier(string $path, StorageTier $tier): bool` — POST `{bucketUri}/actions/updateObjectStorageTier` `{objectName, storageTier}`; false + log on error.
  - `OciClient::restoreObjects(array $paths, int $hours = 24): bool` — one POST `{bucketUri}/actions/restoreObjects` `{objectName, hours}` per path; false on any error.
  - Adapter `updateStorageTier(string $path, string|StorageTier $tier): bool` → true, or throws `UnableToWriteFile`. `restore(string $path, int $hours = 24): bool` → true, or throws `UnableToWriteFile`.
  - `setVisibility`: `visibility_mapping` null → `UnableToSetVisibility::atLocation($path, 'OCI Object Storage has no per-object visibility; control access at bucket level or set visibility_mapping')`; otherwise map visibility → tier and update.
  - `visibility($path)`: mapping null → HEAD existence check (404 → `UnableToRetrieveMetadata::visibility`), then `FileAttributes($path, null, $config['visibility'] ?? 'private')`; mapping set → reverse-map the `storage-tier` response header.

- [ ] **Step 1: Write failing tests:** `update tier sends action body` (`{"objectName":"a","storageTier":"InfrequentAccess"}`); `update tier failure throws from adapter and returns false from client`; `restore sends one request per object with hours`; `setVisibility throws by default`; `setVisibility maps tier when mapping set`; `visibility returns configured default`; `visibility reverse maps tier when mapping set`.
- [ ] **Step 2:** Run — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run the file + all tests — Expected: PASS.
- [ ] **Step 5: Commit** `fix: correct storage tier, restore and visibility behaviour`

### Task 10: URLs, temporary URLs, PAR prune command

**Files:**
- Modify: `src/OciAdapter.php` (`getUrl`, `createTemporaryUrl`), `src/OciClient.php` (`createTemporaryUrl`), `src/LaravelOciDriverServiceProvider.php` (callback passes `$options`; register command)
- Create: `src/Commands/OciParPruneCommand.php`
- Test: `tests/Unit/TemporaryUrlTest.php`, `tests/Feature/ParPruneCommandTest.php`

**Interfaces:**
- Produces:
  - `OciClient::createTemporaryUrl(string $path, ?Carbon $expiresAt = null, array $options = []): string` — POST `{bucketUri}/p/` `{name: 'laravel-oci-'.uuid7, objectName, accessType: $options['access_type'] ?? 'ObjectRead', timeExpires: ISO-8601 UTC}`; returns `https://{host}{accessUri}`. Expiry: null → now + `temporary_url.default_expiry`; later than now + `temporary_url.max_expiry` → capped; ≤ now → `InvalidArgumentException` before any request. API failure → `OciRequestFailed` (the provider callback applies `throw_exceptions`).
  - `OciAdapter::getUrl(string $path): string` = `https://{getHost()}/n/{ns}/b/{bucket}/o/{encode(prefixed)}`.
  - Command `oci:par:prune {disk=oci} {--dry-run}`: GET `{bucketUri}/p/?limit=1000`, paging via `page` query from the `opc-next-page` header; DELETE `{bucketUri}/p/{id}` where `name` starts with `laravel-oci-` and `timeExpires` < now; prints `Pruned N expired PARs` (or `Would prune N` with `--dry-run`, deleting nothing).

- [ ] **Step 1: Write failing tests:** `default expiry applied` (frozen time; `timeExpires` = now + 3600 s); `expiry capped at max` (now + 2 days → now + 86400 s); `past expiry throws without request` (0 requests recorded); `access type option used`; `api failure throws when throw_exceptions true and returns empty string when false` (through `Storage::disk('oci')->temporaryUrl()`); `getUrl encodes path`; prune: `deletes only expired laravel-oci PARs across pages` (2 pages; mix of expired/unexpired and foreign names → only expired `laravel-oci-*` deleted); `dry run deletes nothing`.
- [ ] **Step 2:** Run both files — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run both files + all tests — Expected: PASS.
- [ ] **Step 5: Commit** `feat: temporary URL options and PAR pruning command`

### Task 11: Helper and command cleanup

**Files:**
- Modify: `src/LaravelOciDriver.php`, `src/Commands/OciConnectionCommand.php`, `src/Commands/OciConfigCommand.php`
- Test: `tests/Feature/LaravelOciDriverTest.php` (extend), `tests/Feature/OciCommandsTest.php`

**Interfaces:**
- Produces:
  - `LaravelOciDriver::copyFile/moveFile/listFiles` keep signatures; pass `$options` where `Storage` accepts them, otherwise the PHPDoc states the argument is ignored.
  - `LaravelOciDriver::connection(string $name)` resolves `filesystems.disks.{$name}` when its driver is `oci`, falling back to the old `oci_{$name}` lookup.
  - `oci:config --validate` accepts `--disk=` (default `oci`).
  - `oci:connection test {name}` uses the resolution above.

- [ ] **Step 1: Write failing tests:** `connection resolves plain oci disk name`; `config validate uses given disk` (disk `bad` with empty bucket → non-zero exit, output contains `bad`); `connection test reports missing disk clearly`.
- [ ] **Step 2:** Run — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run feature tests + all — Expected: PASS.
- [ ] **Step 5: Commit** `fix: make helpers and commands work with any oci disk`

### Task 12: CI, static analysis, docs

**Files:**
- Modify: `.github/workflows/ci.yml`, `.github/workflows/release.yml`, `phpstan-baseline.neon`, `README.md`, `CHANGELOG.md`, `docs/CONFIGURATION.md`, `docs/TROUBLESHOOTING.md`

- [ ] **Step 1:** CI matrix: php `[8.2, 8.3, 8.4, 8.5]` × laravel `[10.*, 11.*, 12.*, 13.*]` paired with testbench `8.*`, `9.*`, `10.*`, `11.*`; `stability: [prefer-lowest, prefer-stable]`; exclude Laravel 10 with PHP 8.4/8.5 and any pair below a Laravel version's PHP minimum. Remove `--no-suggest` in `release.yml`.
- [ ] **Step 2:** `vendor/bin/phpstan analyse --generate-baseline`, then `vendor/bin/phpstan analyse` — Expected: exit 0; baseline not larger than before.
- [ ] **Step 3:** README: config reference including new keys (spec §3.3), multiple disks, multipart, temporary URLs + `oci:par:prune`, visibility on OCI, key sources (`private_key`, `private_key_base64`, `key_path`, `passphrase`), troubleshooting (spaces/`+` in names, prefix rule); remove claims for the unimplemented event system and health checks; point badges at existing workflows. Mirror config keys in `docs/CONFIGURATION.md`; add the spaces/prefix entries to `docs/TROUBLESHOOTING.md`.
- [ ] **Step 4:** CHANGELOG "Unreleased": "Changed" = spec §8 items 1–6; "Added" = multipart upload, paginated listing, async copy, PAR prune command, retries/timeouts, multiple disks, key sources.
- [ ] **Step 5:** Try `tests/Integration/FlysystemContractTest.php` extending `League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase` (dev dep `league/flysystem-adapter-test-utilities`) against `FakeOci`. If it needs a stateful server the MockHandler can't reasonably provide, do not commit it and note "contract suite skipped: needs stateful server" in the PR description (spec §7).
- [ ] **Step 6:** `vendor/bin/pint` then `vendor/bin/pest` — Expected: all green.
- [ ] **Step 7: Commit** `docs: update README, changelog and CI matrix`

### Task 13: Manual verification and PR

- [ ] **Step 1:** Push the branch (no PR yet). In EnjoyDrivingBackend, temporarily set the requirement to `dev-feat/reliability-and-features` and `composer update mohamedhabibwork/laravel-oci-driver` (do not commit). Run `BACKUP_DISKS=oci php artisan backup:run --only-db --disable-notifications`, `backup:list` (Expected: Healthy ✅, ≥1 backup), `backup:clean` (Expected: no errors).
- [ ] **Step 2:** Generate a 150 MB file in the scratchpad; in tinker `Storage::disk('oci')->writeStream('claude-mp-test.bin', fopen($file, 'r'))`; Expected: `fileSize` = 157286400 and `memory_get_peak_usage(true)` < 64 MB; then delete the object.
- [ ] **Step 3:** Restore EnjoyDrivingBackend: `git checkout composer.json` and `composer update mohamedhabibwork/laravel-oci-driver`; Expected: `git status` clean.
- [ ] **Step 4:** Open the PR to `main` with spec §2 summary, §8 behaviour changes, and test evidence. Ask the maintainer before tagging `v1.1.0`.
