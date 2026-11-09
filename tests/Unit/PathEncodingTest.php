<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;

it('applies the prefix exactly once', function (string $input, string $expected) {
    $client = FakeOci::make(['url_path_prefix' => 'local'])->client();

    expect($client->getPrefixedPath($input))->toBe($expected);
})->with([
    ['a', 'local/a'],
    ['local/a', 'local/a'],
    ['localfoo', 'local/localfoo'],
    ['local', 'local'],
    ['/a', 'local/a'],
]);

it('encodes special characters', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(new Response(200));

    $fake->adapter()->write('/a b/ü+%.txt', 'x', new Config);

    expect($fake->lastRequest()->getUri()->getPath())->toEndWith('/o/local%2Fa%20b%2F%C3%BC%2B%25.txt');
});

it('strips the prefix', function () {
    $client = FakeOci::make(['url_path_prefix' => 'local'])->client();

    expect($client->removePrefixFromPath('local/a b/ü+%.txt'))->toBe('a b/ü+%.txt');
});
