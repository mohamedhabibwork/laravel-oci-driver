<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToWriteFile;

it('prefixObjectName always applies the prefix', function (string $prefix, string $path, string $expected) {
    $client = FakeOci::make(['url_path_prefix' => $prefix])->client();

    expect($client->prefixObjectName($path))->toBe($expected);
})->with([
    'plain' => ['backups', 'a', 'backups/a'],
    'looks prefixed' => ['backups', 'backups/a', 'backups/backups/a'],
    'leading slash' => ['backups', '/a', 'backups/a'],
    'root' => ['backups', '', 'backups'],
    'no prefix' => ['', '/a/b', 'a/b'],
    'no prefix root' => ['', '', ''],
]);

it('write stores a prefix-looking path under the prefix again', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'backups'])->queue(new Response(200));

    $fake->adapter()->write('backups/a', 'x', new Config);

    expect($fake->lastRequest()->getUri()->getPath())->toEndWith('/o/'.rawurlencode('backups/backups/a'));
});

it('deleteDirectory of a prefix-named directory lists the doubled prefix', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'backups'])->queue(FakeOci::json(200, ['objects' => []]));

    $fake->adapter()->deleteDirectory('backups');

    parse_str($fake->lastRequest()->getUri()->getQuery(), $q);
    expect($q['prefix'])->toBe('backups/backups/');
});

it('deleteDirectory deletes full names without re-prefixing them', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'backups'])->queue(
        FakeOci::json(200, ['objects' => [['name' => 'backups/backups/a']]]),
        new Response(204),
    );

    $fake->adapter()->deleteDirectory('backups');

    expect($fake->lastRequest()->getUri()->getPath())->toEndWith('/o/'.rawurlencode('backups/backups/a'));
});

it('deleteDirectory of the disk root is allowed on a prefixed disk', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'backups'])->queue(FakeOci::json(200, ['objects' => []]));

    $fake->adapter()->deleteDirectory('');

    parse_str($fake->lastRequest()->getUri()->getQuery(), $q);
    expect($q['prefix'])->toBe('backups/');
});

it('deleteDirectory refuses the bucket root on an unprefixed disk', function (string $path) {
    $fake = FakeOci::make();

    $e = FakeOci::caught(fn () => $fake->adapter()->deleteDirectory($path));

    expect($e)->toBeInstanceOf(UnableToDeleteDirectory::class)
        ->and($e?->getMessage())->toContain('refusing to delete the bucket root')
        ->and($fake->requests())->toBe([]);
})->with(['', '/']);

it('move, copy, restore and tier change prefix unconditionally', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'backups'])->queue(
        new Response(200),
        FakeOci::json(200, []),
        new Response(200),
    );
    $adapter = $fake->adapter();

    $adapter->move('backups/a', 'backups/b', new Config);
    $adapter->restore('backups/a', 24);
    $adapter->updateStorageTier('backups/a', 'Archive');

    [$move, $restore, $tier] = $fake->requests();
    expect(json_decode((string) $move->getBody(), true))->toBe(['sourceName' => 'backups/backups/a', 'newName' => 'backups/backups/b'])
        ->and(json_decode((string) $restore->getBody(), true)['objectName'])->toBe('backups/backups/a')
        ->and(json_decode((string) $tier->getBody(), true)['objectName'])->toBe('backups/backups/a');
});

it('copy and getUrl prefix unconditionally', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'backups'])->queue(
        new Response(202, ['opc-work-request-id' => 'wr']),
        FakeOci::json(200, ['status' => 'COMPLETED']),
    );

    $fake->adapter()->copy('backups/a', 'backups/b', new Config);
    $body = json_decode((string) $fake->requests()[0]->getBody(), true);

    expect($body['sourceObjectName'])->toBe('backups/backups/a')
        ->and($body['destinationObjectName'])->toBe('backups/backups/b')
        ->and($fake->adapter()->getUrl('backups/a'))->toEndWith('/o/'.rawurlencode('backups/backups/a'));
});

it('updateStorageTier rejects an unknown tier without a request', function () {
    $fake = FakeOci::make();

    $e = FakeOci::caught(fn () => $fake->adapter()->updateStorageTier('a', 'archive'));

    expect($e)->toBeInstanceOf(UnableToWriteFile::class)
        ->and($e?->getMessage())->toContain("unknown storage tier 'archive'")
        ->and($fake->requests())->toBe([]);
});

it('updateStorageTier accepts exact tier strings', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    expect($fake->adapter()->updateStorageTier('a', 'Archive'))->toBeTrue()
        ->and(json_decode((string) $fake->lastRequest()->getBody(), true)['storageTier'])->toBe('Archive');
});
