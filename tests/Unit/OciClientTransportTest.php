<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;

const TRANSPORT_URI = 'https://objectstorage.me-jeddah-1.oraclecloud.com/n/ns/b/bkt/o/a';

it('sends through the injected client', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->client()->send(TRANSPORT_URI, 'GET');

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->lastRequest()->getMethod())->toBe('GET')
        ->and($fake->lastRequest()->getHeaderLine('authorization'))->toStartWith('Signature version="1"');
});

it('does not throw on 4xx', function () {
    $fake = FakeOci::make()->queue(new Response(404));

    expect($fake->client()->send(TRANSPORT_URI, 'GET')->getStatusCode())->toBe(404);
});

it('passes request options', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->client()->send(TRANSPORT_URI, 'GET', options: ['stream' => true]);

    expect($fake->history()[0]['options']['stream'])->toBeTrue();
});
