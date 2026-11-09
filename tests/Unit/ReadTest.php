<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;

it('fileExists returns true, false, and throws', function () {
    $fake = FakeOci::make()->queue(new Response(200), new Response(404), new Response(500));
    $adapter = $fake->adapter();

    expect($adapter->fileExists('a'))->toBeTrue()
        ->and($adapter->fileExists('a'))->toBeFalse();

    try {
        $adapter->fileExists('a');
        $this->fail('expected exception');
    } catch (UnableToCheckExistence $e) {
        expect($e->getPrevious())->toBeInstanceOf(OciRequestFailed::class)
            ->and($e->getPrevious()->status)->toBe(500);
    }
});

it('read 404 throws UnableToReadFile', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(404, ['code' => 'ObjectNotFound', 'message' => 'nope']));

    expect(fn () => $fake->adapter()->read('a'))->toThrow(UnableToReadFile::class);
});

it('read returns contents', function () {
    $fake = FakeOci::make()->queue(new Response(200, [], 'hello'));

    expect($fake->adapter()->read('a'))->toBe('hello');
});

it('readStream returns resource and requests stream option', function () {
    $fake = FakeOci::make()->queue(new Response(200, [], 'hello'));

    $stream = $fake->adapter()->readStream('a');

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe('hello')
        ->and($fake->history()[0]['options']['stream'] ?? null)->toBeTrue();
});

it('fileSize reads content-length', function () {
    $fake = FakeOci::make()->queue(new Response(200, ['Content-Length' => '42']));

    expect($fake->adapter()->fileSize('a')->fileSize())->toBe(42);
});

it('fileSize missing header throws UnableToRetrieveMetadata', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    expect(fn () => $fake->adapter()->fileSize('a'))->toThrow(UnableToRetrieveMetadata::class);
});

it('lastModified parses last-modified', function () {
    $fake = FakeOci::make()->queue(new Response(200, ['last-modified' => 'Wed, 21 Oct 2015 07:28:00 GMT']));

    expect($fake->adapter()->lastModified('a')->lastModified())->toBe(1445412480);
});

it('mimeType reads content-type', function () {
    $fake = FakeOci::make()->queue(new Response(200, ['Content-Type' => 'text/plain']));

    expect($fake->adapter()->mimeType('a')->mimeType())->toBe('text/plain');
});

it('delete 404 is success', function () {
    $fake = FakeOci::make()->queue(new Response(404));

    $fake->adapter()->delete('a');

    expect($fake->requests())->toHaveCount(1);
});

it('delete 500 throws UnableToDeleteFile', function () {
    $fake = FakeOci::make()->queue(new Response(500));

    expect(fn () => $fake->adapter()->delete('a'))->toThrow(UnableToDeleteFile::class);
});

it('delete directory probe failure keeps the previous exception', function () {
    $previous = new ConnectException('down', new Request('GET', 'x'));
    $fake = FakeOci::make()->queue($previous);

    try {
        $fake->adapter()->delete('dir/');
        $this->fail('expected exception');
    } catch (UnableToDeleteFile $e) {
        expect($e->getPrevious())->toBe($previous);
    }
});
