<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Exception\SigningValidationFailedException;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

// A non-empty but unparsable private_key passes config validation and only fails when the first request is signed.
it('maps an unparsable private key to UnableToWriteFile on write', function () {
    $fake = FakeOci::make(['private_key' => 'not a pem']);

    $e = FakeOci::caught(fn () => $fake->adapter()->write('a.txt', 'x', new Config));

    expect($e)->toBeInstanceOf(UnableToWriteFile::class)
        ->and($e?->getPrevious())->toBeInstanceOf(SigningValidationFailedException::class)
        ->and($fake->requests())->toBe([]);
});

it('maps an unparsable private key to UnableToReadFile on read', function () {
    $fake = FakeOci::make(['private_key' => 'not a pem']);

    $e = FakeOci::caught(fn () => $fake->adapter()->read('a.txt'));

    expect($e)->toBeInstanceOf(UnableToReadFile::class)
        ->and($e?->getPrevious())->toBeInstanceOf(SigningValidationFailedException::class);
});

it('maps a missing key file to the flysystem exception', function () {
    $fake = FakeOci::make(['key_path' => '/nonexistent/oci-key.pem']);

    $e = FakeOci::caught(fn () => $fake->adapter()->read('a.txt'));

    expect($e)->toBeInstanceOf(UnableToReadFile::class)
        ->and($e?->getPrevious())->toBeInstanceOf(PrivateKeyFileNotFoundException::class);
});

it('maps invalid utf-8 metadata on copy to UnableToCopyFile', function () {
    $fake = FakeOci::make();

    $e = FakeOci::caught(fn () => $fake->adapter()->copy('a', 'b', new Config(['metadata' => ['k' => "\xB1\x31"]])));

    expect($e)->toBeInstanceOf(UnableToCopyFile::class)
        ->and($e?->getPrevious())->toBeInstanceOf(JsonException::class)
        ->and($fake->requests())->toBe([]);
});

it('maps a guzzle bad header value on write to UnableToWriteFile', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->write('a.txt', 'x', new Config(['metadata' => ['k' => "bad\nvalue"]]));
})->throws(UnableToWriteFile::class);

it('renameObject returns false on a json encoding failure', function () {
    $fake = FakeOci::make();

    expect($fake->client()->renameObject("\xB1\x31", 'b'))->toBeFalse();
});
