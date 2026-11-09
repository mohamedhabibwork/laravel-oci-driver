<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\OciClient;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ociDiskConfig(array $overrides = []): array
{
    return array_merge(config('filesystems.disks.oci'), $overrides);
}

function ociClientOf(string $disk): OciClient
{
    $adapter = Storage::disk($disk)->getAdapter();
    expect($adapter)->toBeInstanceOf(OciAdapter::class);

    $property = new ReflectionProperty($adapter, 'client');

    return $property->getValue($adapter);
}

test('two oci disks use their own bucket', function () {
    config()->set('filesystems.disks.a', ociDiskConfig(['bucket' => 'one']));
    config()->set('filesystems.disks.b', ociDiskConfig(['bucket' => 'two']));

    expect(ociClientOf('a')->getBucket())->toBe('one')
        ->and(ociClientOf('b')->getBucket())->toBe('two');
});

test('connection values merge under disk values', function () {
    config()->set('laravel-oci-driver.connections.x', ['region' => 'r1']);
    $base = ociDiskConfig(['connection' => 'x']);
    unset($base['region']);
    config()->set('filesystems.disks.merged', $base);
    config()->set('filesystems.disks.override', ociDiskConfig(['connection' => 'x', 'region' => 'r2']));

    expect(ociClientOf('merged')->getRegion())->toBe('r1')
        ->and(ociClientOf('override')->getRegion())->toBe('r2');
});

test('missing values name the disk', function () {
    config()->set('filesystems.disks.c', ociDiskConfig(['bucket' => '']));

    expect(fn () => Storage::disk('c'))
        ->toThrow(RuntimeException::class, "OCI disk 'c' is missing: bucket");
});

test('assertValid lists every missing key and names the disk from config', function () {
    $config = OciConfig::fromDiskConfig(['driver' => 'oci', 'bucket' => 'b'], 'zed');

    expect(fn () => $config->assertValid('zed'))
        ->toThrow(InvalidArgumentException::class, "OCI disk 'zed' is missing: namespace, region, tenancy_id, user_id, key_fingerprint, private_key|private_key_base64|key_path");
});

test('chunk size has a 10 MiB floor', function () {
    $small = new OciConfig(['upload' => ['chunk_size' => 1]]);
    $default = new OciConfig([]);
    $large = new OciConfig(['upload' => ['chunk_size' => 67108864]]);

    expect($small->getChunkSize())->toBe(10485760)
        ->and($default->getChunkSize())->toBe(33554432)
        ->and($large->getChunkSize())->toBe(67108864);
});

test('a disk with only private_key_base64 builds', function () {
    $pem = file_get_contents(__DIR__.'/../fixtures/test-key.pem');
    $config = ociDiskConfig(['private_key_base64' => base64_encode($pem)]);
    unset($config['key_path'], $config['storage_tier']);
    config()->set('filesystems.disks.b64', $config);

    expect(ociClientOf('b64')->getStorageTier()->value)->toBe('Standard');
});

test('temporary urls reuse the disk client', function () {
    config()->set('filesystems.disks.t', ociDiskConfig());
    $disk = Storage::disk('t');

    $callback = (new ReflectionProperty($disk, 'temporaryUrlCallback'))->getValue($disk);
    $captured = (new ReflectionFunction($callback))->getStaticVariables()['client'] ?? null;

    expect($captured)->toBe(ociClientOf('t'));
});
