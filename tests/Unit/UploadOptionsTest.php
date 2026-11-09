<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;

/**
 * @return resource
 */
function multipartSource()
{
    $handle = fopen('php://temp', 'w+') ?: throw new RuntimeException('temp stream unavailable');
    fwrite($handle, '0123456789ab');
    rewind($handle);

    return $handle;
}

it('exempts a single upload PUT from the total timeout but not other requests', function () {
    $fake = FakeOci::make(['timeout' => 30])->queue(new Response(200), new Response(200));
    $adapter = $fake->adapter();

    $adapter->write('a.txt', 'x', new Config);
    $adapter->read('a.txt');

    [$put, $get] = $fake->history();
    expect($put['options']['timeout'])->toBe(0)
        ->and($put['options']['connect_timeout'])->toBe(10)
        ->and($get['options']['timeout'])->toBe(30);
});

it('exempts multipart part PUTs from the total timeout', function () {
    $fake = FakeOci::make(['upload' => ['multipart_threshold' => 5]])->queue(
        FakeOci::json(200, ['uploadId' => 'u1']),
        new Response(200, ['etag' => 'e1']),
        new Response(200),
    );
    $fake->adapter()->writeStream('big.bin', multipartSource(), new Config);

    [$create, $part, $commit] = $fake->history();
    expect($part['options']['timeout'])->toBe(0)
        ->and($create['options']['timeout'])->toBe(30)
        ->and($commit['options']['timeout'])->toBe(30);
});

it('sends multipart metadata keys in opc-meta format', function () {
    $fake = FakeOci::make(['upload' => ['multipart_threshold' => 5]])->queue(
        FakeOci::json(200, ['uploadId' => 'u1']),
        new Response(200, ['etag' => 'e1']),
        new Response(200),
    );
    $fake->adapter()->writeStream('big.bin', multipartSource(), new Config(['metadata' => ['department' => 'finance']]));

    expect(json_decode((string) $fake->requests()[0]->getBody(), true)['metadata'])
        ->toBe(['opc-meta-department' => 'finance']);
});

it('does not double prefix copy metadata keys', function () {
    $fake = FakeOci::make()->queue(
        new Response(202, ['opc-work-request-id' => 'wr']),
        FakeOci::json(200, ['status' => 'COMPLETED']),
    );

    $fake->adapter()->copy('a', 'b', new Config(['metadata' => ['k' => 'v', 'opc-meta-x' => 'y']]));

    expect(json_decode((string) $fake->requests()[0]->getBody(), true)['destinationObjectMetadata'])
        ->toBe(['opc-meta-k' => 'v', 'opc-meta-x' => 'y']);
});

it('encodes listing query spaces as %20', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(200, ['objects' => []]));

    iterator_to_array($fake->adapter()->listContents('my dir', false));

    $query = $fake->lastRequest()->getUri()->getQuery();
    expect($query)->toContain('prefix=my%20dir%2F')
        ->and(str_contains($query, '+'))->toBeFalse();
});

it('encodes listObjects query spaces as %20', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(200, ['objects' => []]));

    $fake->client()->listObjects(['prefix' => 'my dir']);

    $query = $fake->lastRequest()->getUri()->getQuery();
    expect($query)->toContain('prefix=my%20dir')
        ->and(str_contains($query, '+'))->toBeFalse();
});

it('createDirectory sends exactly one Content-Length header value', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->createDirectory('d', new Config);

    expect($fake->lastRequest()->getHeader('Content-Length'))->toBe(['0']);
});

it('clamps copy_poll_interval to at least 100 ms', function () {
    $fake = FakeOci::make(['copy_poll_interval' => 0])->queue(
        new Response(202, ['opc-work-request-id' => 'wr']),
        FakeOci::json(200, ['status' => 'IN_PROGRESS']),
        FakeOci::json(200, ['status' => 'COMPLETED']),
    );
    $slept = [];
    $adapter = new OciAdapter($fake->client(), function (int $us) use (&$slept): void {
        $slept[] = $us;
    });

    $adapter->copy('a', 'b', new Config);

    expect($slept)->toBe([100000]);
});

it('clamps a negative copy_timeout to zero', function () {
    $fake = FakeOci::make(['copy_timeout' => -5])->queue(
        new Response(202, ['opc-work-request-id' => 'wr']),
        FakeOci::json(200, ['status' => 'IN_PROGRESS']),
    );

    $e = FakeOci::caught(fn () => $fake->adapter()->copy('a', 'b', new Config));

    expect($e)->toBeInstanceOf(UnableToCopyFile::class)
        ->and($e?->getPrevious()?->getMessage())->toContain('timed out')
        ->and($fake->requests())->toHaveCount(2);
});
