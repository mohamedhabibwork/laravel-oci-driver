<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

function visibilityMapping(): array
{
    return ['visibility_mapping' => ['public' => 'Standard', 'private' => 'InfrequentAccess']];
}

it('update tier sends action body', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    expect($fake->adapter()->updateStorageTier('a', StorageTier::INFREQUENT_ACCESS))->toBeTrue();

    $request = $fake->lastRequest();
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toEndWith('/b/bkt/actions/updateObjectStorageTier')
        ->and((string) $request->getBody())->toBe('{"objectName":"a","storageTier":"InfrequentAccess"}');
});

it('update tier failure throws from adapter and returns false from client', function () {
    $fake = FakeOci::make()->queue(new Response(404), new Response(500));

    expect(fn () => $fake->adapter()->updateStorageTier('a', 'Archive'))
        ->toThrow(UnableToWriteFile::class);
    expect($fake->client()->updateObjectStorageTier('a', StorageTier::ARCHIVE))->toBeFalse();
});

it('restore sends one request per object with hours', function () {
    $fake = FakeOci::make()->queue(new Response(200), new Response(200));

    expect($fake->client()->restoreObjects(['a', 'dir/b'], 48))->toBeTrue();

    $requests = $fake->requests();
    expect($requests)->toHaveCount(2)
        ->and((string) $requests[0]->getUri())->toEndWith('/b/bkt/actions/restoreObjects')
        ->and((string) $requests[0]->getBody())->toBe('{"objectName":"a","hours":48}')
        ->and((string) $requests[1]->getBody())->toBe('{"objectName":"dir/b","hours":48}');
});

it('restore stops at first failure in client and throws from adapter', function () {
    $fake = FakeOci::make()->queue(new Response(409), new Response(409));

    expect($fake->client()->restoreObjects(['a', 'b']))->toBeFalse()
        ->and($fake->requests())->toHaveCount(1);
    expect(fn () => $fake->adapter()->restore('a', 24))->toThrow(UnableToWriteFile::class);
});

it('restore from adapter returns true on success', function () {
    $fake = FakeOci::make()->queue(new Response(202));

    expect($fake->adapter()->restore('a', 24))->toBeTrue();
});

it('setVisibility throws by default', function () {
    $fake = FakeOci::make();

    expect(fn () => $fake->adapter()->setVisibility('a', 'public'))
        ->toThrow(UnableToSetVisibility::class, 'no per-object visibility');
    expect($fake->requests())->toBeEmpty();
});

it('setVisibility maps tier when mapping set', function () {
    $fake = FakeOci::make(visibilityMapping())->queue(new Response(200));

    $fake->adapter()->setVisibility('a', 'private');

    expect((string) $fake->lastRequest()->getBody())
        ->toBe('{"objectName":"a","storageTier":"InfrequentAccess"}');
});

it('setVisibility rejects unmapped visibility and failed update', function () {
    $fake = FakeOci::make(visibilityMapping())->queue(new Response(500));

    expect(fn () => $fake->adapter()->setVisibility('a', 'weird'))->toThrow(UnableToSetVisibility::class);
    expect(fn () => $fake->adapter()->setVisibility('a', 'public'))->toThrow(UnableToSetVisibility::class);
});

it('visibility returns configured default', function () {
    $fake = FakeOci::make(['visibility' => 'public'])->queue(new Response(200));
    $default = FakeOci::make()->queue(new Response(200));

    expect($fake->adapter()->visibility('a')->visibility())->toBe('public')
        ->and($default->adapter()->visibility('a')->visibility())->toBe('private')
        ->and($default->lastRequest()->getMethod())->toBe('HEAD');
});

it('visibility throws when object missing', function () {
    $fake = FakeOci::make()->queue(new Response(404));

    expect(fn () => $fake->adapter()->visibility('a'))->toThrow(UnableToRetrieveMetadata::class);
});

it('visibility reverse maps tier when mapping set', function () {
    $fake = FakeOci::make(visibilityMapping())->queue(
        new Response(200, ['storage-tier' => 'InfrequentAccess']),
        new Response(200, ['storage-tier' => 'Standard']),
        new Response(200, ['storage-tier' => 'Archive']),
    );

    expect($fake->adapter()->visibility('a')->visibility())->toBe('private')
        ->and($fake->adapter()->visibility('a')->visibility())->toBe('public')
        ->and($fake->adapter()->visibility('a')->visibility())->toBe('private');
});

it('getObjectInfo reads storage tier and opc-meta metadata', function () {
    $fake = FakeOci::make()->queue(new Response(200, [
        'storage-tier' => 'Archive',
        'opc-meta-color' => 'red',
        'Content-Length' => '3',
    ]));

    $info = $fake->client()->getObjectInfo('a');

    expect($info['storage_tier'])->toBe('Archive')
        ->and($info['size'])->toBe(3)
        ->and($info['metadata'])->toBe(['opc-meta-color' => 'red']);
});

it('setVisibility rejects unknown mapped tier without a request', function () {
    $fake = FakeOci::make(['visibility_mapping' => ['public' => 'Archve']]);

    expect(fn () => $fake->adapter()->setVisibility('a', 'public'))
        ->toThrow(UnableToSetVisibility::class, "unknown storage tier 'Archve'");
    expect($fake->requests())->toBeEmpty();
});

it('setVisibility wraps update failure with previous', function () {
    $fake = FakeOci::make(visibilityMapping())->queue(new Response(500));

    try {
        $fake->adapter()->setVisibility('a', 'public');
        $this->fail('expected exception');
    } catch (UnableToSetVisibility $e) {
        expect($e->getPrevious())->not->toBeNull();
    }
});
