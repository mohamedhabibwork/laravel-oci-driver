<?php

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Signer;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;

const SIGNER_URL = 'https://objectstorage.me-jeddah-1.oraclecloud.com/n/ns/b/bkt/o/a%20b?x=1';
const SIGNER_DATE = 'Mon, 09 Nov 2026 06:00:00 GMT';

function signerKeyPath(): string
{
    return dirname(__DIR__).'/fixtures/test-key.pem';
}

function makeSigner(): Signer
{
    return Signer::fromConfig([
        'tenancy_id' => 'ten',
        'user_id' => 'usr',
        'key_fingerprint' => 'fp',
        'key_path' => signerKeyPath(),
    ]);
}

/**
 * @param  string[]  $headers
 * @return array{headers: string[], signature: string}
 */
function parseAuthorization(array $headers): array
{
    foreach ($headers as $line) {
        if (str_starts_with($line, 'Authorization: ')) {
            preg_match('/headers="([^"]+)"/', $line, $h);
            preg_match('/signature="([^"]+)"/', $line, $s);

            return ['headers' => explode(' ', $h[1]), 'signature' => $s[1]];
        }
    }

    throw new RuntimeException('No Authorization header');
}

it('date header uses GMT format', function () {
    $headers = makeSigner()->getHeaders(SIGNER_URL, 'GET');

    expect(collect($headers)->contains(fn ($h) => preg_match('/^date: \w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $h) === 1))->toBeTrue();
});

it('signing string is stable', function () {
    expect(makeSigner()->getSigningString(SIGNER_URL, 'GET', null, 'application/json', SIGNER_DATE))
        ->toBe("date: Mon, 09 Nov 2026 06:00:00 GMT\n(request-target): get /n/ns/b/bkt/o/a%20b?x=1\nhost: objectstorage.me-jeddah-1.oraclecloud.com");
});

it('does not leak signed headers between calls', function () {
    $signer = makeSigner();
    $signer->getHeaders(SIGNER_URL, 'GET', null, 'application/json', SIGNER_DATE);

    $post = parseAuthorization($signer->getHeaders(SIGNER_URL, 'POST', '{}'));

    expect($post['headers'])->toContain('x-content-sha256');
});

it('post string body signs body headers', function () {
    $headers = makeSigner()->getHeaders(SIGNER_URL, 'POST', '{"a":1}');
    $auth = parseAuthorization($headers);

    expect($auth['headers'])->toBe(['date', '(request-target)', 'host', 'content-length', 'content-type', 'x-content-sha256'])
        ->and($headers)->toContain('x-content-sha256: '.base64_encode(hash('sha256', '{"a":1}', true)))
        ->and($headers)->toContain('content-length: 7');
});

it('put with excluded body headers signs only base headers', function () {
    $headers = makeSigner()->getHeaders(SIGNER_URL, 'PUT', null, 'application/json', null, excludeBodyHeaders: true);

    expect(parseAuthorization($headers)['headers'])->toBe(['date', '(request-target)', 'host']);
});

it('null body for post hashes empty string', function () {
    set_error_handler(function (int $no, string $str): never {
        throw new ErrorException($str, 0, $no);
    });

    try {
        $headers = makeSigner()->getHeaders(SIGNER_URL, 'POST', null);
    } finally {
        restore_error_handler();
    }

    expect($headers)->toContain('x-content-sha256: '.base64_encode(hash('sha256', '', true)))
        ->and($headers)->toContain('content-length: 0');
});

it('signature verifies', function () {
    $signer = makeSigner();
    $auth = parseAuthorization($signer->getHeaders(SIGNER_URL, 'GET', null, 'application/json', SIGNER_DATE));
    $signingString = $signer->getSigningString(SIGNER_URL, 'GET', null, 'application/json', SIGNER_DATE);

    expect(openssl_verify($signingString, base64_decode($auth['signature']), file_get_contents(dirname(__DIR__).'/fixtures/test-key.pub'), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('fromConfig reads private_key_base64', function () {
    $signer = Signer::fromConfig([
        'tenancy_id' => 'ten',
        'user_id' => 'usr',
        'key_fingerprint' => 'fp',
        'private_key_base64' => base64_encode(file_get_contents(signerKeyPath())),
    ]);
    $auth = parseAuthorization($signer->getHeaders(SIGNER_URL, 'GET', null, 'application/json', SIGNER_DATE));
    $signingString = $signer->getSigningString(SIGNER_URL, 'GET', null, 'application/json', SIGNER_DATE);

    expect(openssl_verify($signingString, base64_decode($auth['signature']), file_get_contents(dirname(__DIR__).'/fixtures/test-key.pub'), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('fromConfig prefers private_key over key_path', function () {
    $signer = Signer::fromConfig([
        'tenancy_id' => 'ten',
        'user_id' => 'usr',
        'key_fingerprint' => 'fp',
        'private_key' => file_get_contents(signerKeyPath()),
        'key_path' => '/does/not/exist.pem',
    ]);

    expect($signer->getHeaders(SIGNER_URL, 'GET'))->not->toBeEmpty();
});

it('rejects url key path', function () {
    Signer::fromConfig([
        'tenancy_id' => 'ten',
        'user_id' => 'usr',
        'key_fingerprint' => 'fp',
        'key_path' => 'https://x/key.pem',
    ])->getHeaders(SIGNER_URL, 'GET');
})->throws(PrivateKeyFileNotFoundException::class);

it('throws when no key source resolves', function () {
    Signer::fromConfig(['tenancy_id' => 'ten', 'user_id' => 'usr', 'key_fingerprint' => 'fp'])
        ->getHeaders(SIGNER_URL, 'GET');
})->throws(PrivateKeyFileNotFoundException::class);

it('send signs only base headers for a streamed PUT', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->client()->send(SIGNER_URL, 'PUT', [], Utils::streamFor('payload'), 'text/plain');

    $auth = $fake->lastRequest()->getHeaderLine('authorization');
    expect($auth)->toContain('headers="date (request-target) host"')
        ->and((string) $fake->lastRequest()->getBody())->toBe('payload');
});

it('send signs body headers for a streamed POST and keeps the body intact', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->client()->send(SIGNER_URL, 'POST', [], Utils::streamFor('{"a":1}'));

    expect($fake->lastRequest()->getHeaderLine('x-content-sha256'))->toBe(base64_encode(hash('sha256', '{"a":1}', true)))
        ->and((string) $fake->lastRequest()->getBody())->toBe('{"a":1}');
});
