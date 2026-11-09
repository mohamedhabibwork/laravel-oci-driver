<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;

function acceptedCopy(): Response
{
    return new Response(202, ['opc-work-request-id' => 'wr1']);
}

it('copy polls until completed', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local', 'copy_timeout' => 300])->queue(
        acceptedCopy(),
        FakeOci::json(200, ['status' => 'IN_PROGRESS']),
        FakeOci::json(200, ['status' => 'COMPLETED']),
    );

    $fake->adapter()->copy('a', 'b', new Config);

    $requests = $fake->requests();
    $body = json_decode((string) $requests[0]->getBody(), true);
    expect($requests)->toHaveCount(3)
        ->and($requests[0]->getMethod())->toBe('POST')
        ->and((string) $requests[0]->getUri())->toEndWith('/n/ns/b/bkt/actions/copyObject')
        ->and($requests[0]->getHeaderLine('opc-retry-token'))->not->toBe('')
        ->and($body)->toBe([
            'sourceObjectName' => 'local/a',
            'destinationRegion' => 'me-jeddah-1',
            'destinationNamespace' => 'ns',
            'destinationBucket' => 'bkt',
            'destinationObjectName' => 'local/b',
        ])
        ->and($requests[1]->getMethod())->toBe('GET')
        ->and((string) $requests[1]->getUri())->toBe('https://objectstorage.me-jeddah-1.oraclecloud.com/workRequests/wr1');
});

it('copy failed work request throws UnableToCopyFile', function () {
    $fake = FakeOci::make()->queue(acceptedCopy(), FakeOci::json(200, ['status' => 'FAILED']));

    $fake->adapter()->copy('a', 'b', new Config);
})->throws(UnableToCopyFile::class);

it('copy canceled work request throws UnableToCopyFile', function () {
    $fake = FakeOci::make()->queue(acceptedCopy(), FakeOci::json(200, ['status' => 'CANCELED']));

    $fake->adapter()->copy('a', 'b', new Config);
})->throws(UnableToCopyFile::class);

it('copy rejects a non-202 response', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(404, ['code' => 'ObjectNotFound', 'message' => 'x']));

    $fake->adapter()->copy('a', 'b', new Config);
})->throws(UnableToCopyFile::class);

it('copy times out', function () {
    $fake = FakeOci::make(['copy_timeout' => 0])->queue(
        acceptedCopy(),
        FakeOci::json(200, ['status' => 'IN_PROGRESS']),
    );

    try {
        $fake->adapter()->copy('a', 'b', new Config);
        $this->fail('expected exception');
    } catch (UnableToCopyFile $e) {
        expect($e->getPrevious()->getMessage())->toContain('timed out')
            ->and($fake->requests())->toHaveCount(2);
    }
});

it('copy sends metadata and tier when given', function () {
    $fake = FakeOci::make()->queue(acceptedCopy(), FakeOci::json(200, ['status' => 'COMPLETED']));

    $fake->adapter()->copy('a', 'b', new Config(['metadata' => ['k' => 'v'], 'storage_tier' => 'InfrequentAccess']));

    $body = json_decode((string) $fake->requests()[0]->getBody(), true);
    expect($body['destinationObjectMetadata'])->toBe(['k' => 'v'])
        ->and($body['destinationObjectStorageTier'])->toBe('InfrequentAccess');
});

it('move renames', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(new Response(200));

    $fake->adapter()->move('a', 'b', new Config);

    $r = $fake->lastRequest();
    expect($fake->requests())->toHaveCount(1)
        ->and($r->getMethod())->toBe('POST')
        ->and((string) $r->getUri())->toEndWith('/actions/renameObject')
        ->and((string) $r->getBody())->toBe('{"sourceName":"local/a","newName":"local/b"}');
});

it('move failure throws UnableToMoveFile and sends no delete', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(404, ['code' => 'ObjectNotFound', 'message' => 'x']));

    try {
        $fake->adapter()->move('a', 'b', new Config);
        $this->fail('expected exception');
    } catch (UnableToMoveFile) {
        expect($fake->requests())->toHaveCount(1);
    }
});

it('renameObject keeps its boolean contract', function () {
    $ok = FakeOci::make()->queue(new Response(200));
    $bad = FakeOci::make()->queue(FakeOci::json(404, ['code' => 'x', 'message' => 'y']));

    expect($ok->client()->renameObject('a', 'b'))->toBeTrue()
        ->and($bad->client()->renameObject('a', 'b'))->toBeFalse();
});
