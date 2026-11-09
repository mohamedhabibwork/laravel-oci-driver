<?php

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use LaravelOCI\LaravelOciDriver\Support\MultipartUploader;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;
use League\Flysystem\UnableToWriteFile;

/**
 * @return resource
 */
function tempResource(string $contents)
{
    $h = fopen('php://temp', 'w+');
    assert($h !== false);
    fwrite($h, $contents);
    rewind($h);

    return $h;
}

it('writes small string with single put', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->write('a.txt', 'hello', new Config(['metadata' => ['owner' => 'x']]));

    $r = $fake->lastRequest();
    expect($fake->requests())->toHaveCount(1)
        ->and($r->getMethod())->toBe('PUT')
        ->and($r->getHeaderLine('content-type'))->toBe('text/plain')
        ->and($r->getHeaderLine('storage-tier'))->toBe('Standard')
        ->and($r->getHeaderLine('opc-meta-owner'))->toBe('x')
        ->and($r->getHeaderLine('content-md5'))->toBe(base64_encode(md5('hello', true)))
        ->and((string) $r->getBody())->toBe('hello');
});

it('writeStream small resource streams single put', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->writeStream('a.txt', tempResource('streamed body'), new Config);

    $r = $fake->lastRequest();
    expect($r->getMethod())->toBe('PUT')
        ->and((string) $r->getBody())->toBe('streamed body')
        ->and($r->hasHeader('content-md5'))->toBeFalse();
});

it('empty content uses single put', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->write('e.txt', '', new Config);

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->lastRequest()->getMethod())->toBe('PUT')
        ->and($fake->lastRequest()->getHeaderLine('content-length'))->toBe('0');
});

it('stream not at start uploads remaining bytes', function () {
    $fake = FakeOci::make()->queue(new Response(200));
    $h = tempResource('abcdef');
    fseek($h, 2);

    $fake->adapter()->writeStream('a.txt', $h, new Config);

    expect((string) $fake->lastRequest()->getBody())->toBe('cdef');
});

it('large stream uses multipart', function () {
    $fake = FakeOci::make(['upload' => ['multipart_threshold' => 5]])->queue(
        FakeOci::json(200, ['uploadId' => 'u1']),
        new Response(200, ['etag' => 'e1']),
        new Response(200),
    );

    $fake->adapter()->writeStream('big.bin', tempResource('0123456789ab'), new Config);

    [$create, $part, $commit] = $fake->requests();
    expect($create->getMethod())->toBe('POST')
        ->and((string) $create->getUri())->toEndWith('/n/ns/b/bkt/u')
        ->and($create->getHeaderLine('opc-retry-token'))->not->toBe('')
        ->and(json_decode((string) $create->getBody(), true)['object'])->toBe('big.bin')
        ->and($part->getMethod())->toBe('PUT')
        ->and((string) $part->getUri())->toContain('/u/big.bin?uploadId=u1&uploadPartNum=1')
        ->and((string) $part->getBody())->toBe('0123456789ab')
        ->and($commit->getMethod())->toBe('POST')
        ->and($commit->getHeaderLine('opc-retry-token'))->not->toBe('')
        ->and((string) $commit->getBody())->toBe('{"partsToCommit":[{"partNum":1,"etag":"e1"}]}');
});

it('uploader splits parts', function () {
    $fake = FakeOci::make()->queue(
        FakeOci::json(200, ['uploadId' => 'u1']),
        new Response(200, ['etag' => 'e1']),
        new Response(200, ['etag' => 'e2']),
        new Response(200, ['etag' => 'e3']),
        new Response(200),
    );

    (new MultipartUploader($fake->client(), 4))->upload('o.bin', Utils::streamFor('0123456789'), []);

    $requests = $fake->requests();
    expect($requests)->toHaveCount(5)
        ->and((string) $requests[1]->getBody())->toBe('0123')
        ->and((string) $requests[2]->getBody())->toBe('4567')
        ->and((string) $requests[3]->getBody())->toBe('89')
        ->and((string) $requests[2]->getUri())->toContain('uploadPartNum=2')
        ->and((string) $requests[3]->getUri())->toContain('uploadPartNum=3')
        ->and((string) $requests[4]->getBody())->toBe(
            '{"partsToCommit":[{"partNum":1,"etag":"e1"},{"partNum":2,"etag":"e2"},{"partNum":3,"etag":"e3"}]}'
        );
});

it('part failure aborts upload', function () {
    $fake = FakeOci::make(['upload' => ['multipart_threshold' => 5]])->queue(
        FakeOci::json(200, ['uploadId' => 'u1']),
        FakeOci::json(500, ['code' => 'InternalServerError', 'message' => 'boom']),
        new Response(204),
    );

    expect(fn () => $fake->adapter()->writeStream('big.bin', tempResource('0123456789ab'), new Config))
        ->toThrow(UnableToWriteFile::class);

    $last = $fake->lastRequest();
    expect($last->getMethod())->toBe('DELETE')
        ->and((string) $last->getUri())->toContain('uploadId=u1');
});

it('unknown size stream goes multipart', function () {
    $fake = FakeOci::make()->queue(
        FakeOci::json(200, ['uploadId' => 'u1']),
        new Response(200, ['etag' => 'e1']),
        new Response(200),
    );
    $pipe = popen('printf abc', 'r');
    assert($pipe !== false);

    $fake->adapter()->writeStream('p.bin', $pipe, new Config);

    $first = $fake->requests()[0];
    expect($first->getMethod())->toBe('POST')
        ->and((string) $first->getUri())->toEndWith('/u');
});

it('single put failure maps to UnableToWriteFile', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(500, ['code' => 'X', 'message' => 'm']));

    expect(fn () => $fake->adapter()->write('a.txt', 'x', new Config))->toThrow(UnableToWriteFile::class);
});

it('per write checksum false disables content md5', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->write('a.txt', 'hello', new Config(['checksum' => false]));

    expect($fake->lastRequest()->hasHeader('content-md5'))->toBeFalse();
});

it('global enable_checksum false disables content md5', function () {
    $fake = FakeOci::make(['upload' => ['enable_checksum' => false]])->queue(new Response(200));

    $fake->adapter()->write('a.txt', 'hello', new Config);

    expect($fake->lastRequest()->hasHeader('content-md5'))->toBeFalse();
});
