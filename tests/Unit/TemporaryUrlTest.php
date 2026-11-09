<?php

use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\LaravelOciDriverServiceProvider;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Filesystem;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-09 06:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function parResponse(): Response
{
    return FakeOci::json(200, ['accessUri' => '/p/abc/n/ns/b/bkt/o/a.txt', 'id' => 'par1']);
}

/**
 * Test seam: wraps the FakeOci adapter in a FilesystemAdapter and applies the provider's own
 * temporary-URL callback, so Storage::disk('oci')->temporaryUrl() is exercised end to end.
 */
function fakeOciDisk(FakeOci $fake): FilesystemAdapter
{
    $adapter = $fake->adapter();
    $disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, []);
    $provider = app()->getProvider(LaravelOciDriverServiceProvider::class);
    assert($provider instanceof LaravelOciDriverServiceProvider);
    (new ReflectionMethod($provider, 'configureTemporaryUrls'))->invoke($provider, $disk, $fake->client());
    Storage::set('oci', $disk);

    return $disk;
}

/**
 * @return array<string, mixed>
 */
function parBody(FakeOci $fake): array
{
    return json_decode((string) $fake->lastRequest()->getBody(), true);
}

it('applies the default expiry', function () {
    $fake = FakeOci::make()->queue(parResponse());

    $url = $fake->client()->createTemporaryUrl('a.txt');

    $body = parBody($fake);
    expect($body['timeExpires'])->toBe('2026-11-09T07:00:00Z')
        ->and($body['objectName'])->toBe('a.txt')
        ->and($body['accessType'])->toBe('ObjectRead')
        ->and($body['name'])->toStartWith('laravel-oci-')
        ->and($fake->lastRequest()->getMethod())->toBe('POST')
        ->and((string) $fake->lastRequest()->getUri())->toEndWith('/n/ns/b/bkt/p/')
        ->and($url)->toBe('https://objectstorage.me-jeddah-1.oraclecloud.com/p/abc/n/ns/b/bkt/o/a.txt');
});

it('caps the expiry at the maximum', function () {
    $fake = FakeOci::make()->queue(parResponse());

    $fake->client()->createTemporaryUrl('a.txt', Carbon::now()->addDays(2));

    expect(parBody($fake)['timeExpires'])->toBe('2026-11-10T06:00:00Z');
});

it('rejects a past expiry without sending a request', function () {
    $fake = FakeOci::make();

    expect(fn () => $fake->client()->createTemporaryUrl('a.txt', Carbon::now()->subMinute()))
        ->toThrow(InvalidArgumentException::class)
        ->and($fake->requests())->toHaveCount(0);
});

it('uses the access type option', function () {
    $fake = FakeOci::make()->queue(parResponse());

    $fake->client()->createTemporaryUrl('a.txt', null, ['access_type' => 'ObjectReadWrite']);

    expect(parBody($fake)['accessType'])->toBe('ObjectReadWrite');
});

it('applies the path prefix to the object name', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(parResponse());

    $fake->client()->createTemporaryUrl('a.txt');

    expect(parBody($fake)['objectName'])->toBe('local/a.txt');
});

it('passes options through the storage temporaryUrl macro', function () {
    $fake = FakeOci::make()->queue(parResponse());
    fakeOciDisk($fake);

    $url = Storage::disk('oci')->temporaryUrl('a.txt', Carbon::now()->addMinutes(10), ['access_type' => 'AnyObjectRead']);

    expect($url)->toEndWith('/p/abc/n/ns/b/bkt/o/a.txt')
        ->and(parBody($fake)['accessType'])->toBe('AnyObjectRead')
        ->and(parBody($fake)['timeExpires'])->toBe('2026-11-09T06:10:00Z');
});

it('throws on api failure when throw_exceptions is true', function () {
    config()->set('laravel-oci-driver.global.throw_exceptions', true);
    $fake = FakeOci::make()->queue(FakeOci::json(500, ['code' => 'InternalServerError', 'message' => 'boom']));
    fakeOciDisk($fake);

    expect(fn () => Storage::disk('oci')->temporaryUrl('a.txt', Carbon::now()->addHour()))
        ->toThrow(OciRequestFailed::class);
});

it('returns an empty string on api failure when throw_exceptions is false', function () {
    config()->set('laravel-oci-driver.global.throw_exceptions', false);
    $fake = FakeOci::make()->queue(FakeOci::json(500, ['code' => 'InternalServerError', 'message' => 'boom']));
    fakeOciDisk($fake);

    expect(Storage::disk('oci')->temporaryUrl('a.txt', Carbon::now()->addHour()))->toBe('');
});

it('encodes the path in getUrl', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local']);

    expect($fake->adapter()->getUrl('dir/my file.txt'))
        ->toBe('https://objectstorage.me-jeddah-1.oraclecloud.com/n/ns/b/bkt/o/local%2Fdir%2Fmy%20file.txt');
});
