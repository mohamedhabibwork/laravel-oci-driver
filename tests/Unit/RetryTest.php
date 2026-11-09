<?php

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use LaravelOCI\LaravelOciDriver\OciClient;
use LaravelOCI\LaravelOciDriver\Support\RetryDecider;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;

const RETRY_URI = 'https://objectstorage.me-jeddah-1.oraclecloud.com/n/ns/b/bkt/o/a';

/**
 * @param  array<string, mixed>  $overrides
 */
function retryFake(array $overrides = []): FakeOci
{
    return FakeOci::make(array_merge(['retry_attempts' => 3, 'retry_delay' => 0], $overrides));
}

it('retries 503 then succeeds', function () {
    $fake = retryFake()->queue(new Response(503), new Response(200));

    $response = $fake->client()->send(RETRY_URI, 'GET');

    expect($fake->requests())->toHaveCount(2)
        ->and($response->getStatusCode())->toBe(200);
});

it('stops after max attempts', function () {
    $fake = retryFake(['retry_attempts' => 2])->queue(new Response(503), new Response(503), new Response(503));

    $response = $fake->client()->send(RETRY_URI, 'GET');

    expect($fake->requests())->toHaveCount(3)
        ->and($response->getStatusCode())->toBe(503);
});

it('does not retry POST without retry token', function () {
    $fake = retryFake()->queue(new Response(503));

    $fake->client()->send(RETRY_URI, 'POST', body: 'x');

    expect($fake->requests())->toHaveCount(1);
});

it('retries POST with opc-retry-token', function () {
    $fake = retryFake()->queue(new Response(503), new Response(200));

    $fake->client()->send(RETRY_URI, 'POST', ['opc-retry-token' => 'abc'], 'x');

    expect($fake->requests())->toHaveCount(2);
});

it('does not retry non-seekable body', function () {
    $fake = retryFake()->queue(new Response(503));

    $fake->client()->send(RETRY_URI, 'PUT', body: new NoSeekStream(Utils::streamFor('x')));

    expect($fake->requests())->toHaveCount(1);
});

it('delay doubles', function () {
    $decider = new RetryDecider(3, 1000);

    expect($decider->delay(1))->toBe(1000)
        ->and($decider->delay(3))->toBe(4000);
});

it('builds the default client with timeouts and no redirects or http errors', function () {
    $client = OciClient::createWithConfiguration(FakeOci::baseConfig());

    $http = (new ReflectionProperty($client, 'http'))->getValue($client);

    expect($http->getConfig('timeout'))->toBe(30)
        ->and($http->getConfig('connect_timeout'))->toBe(10)
        ->and($http->getConfig('allow_redirects'))->toBeFalse()
        ->and($http->getConfig('http_errors'))->toBeFalse();
});
