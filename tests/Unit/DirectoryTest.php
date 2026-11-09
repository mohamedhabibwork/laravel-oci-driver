<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Config;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;

it('directoryExists true for prefix with objects', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(
        FakeOci::json(200, ['objects' => [['name' => 'local/d/a']]]),
    );

    expect($fake->adapter()->directoryExists('d'))->toBeTrue();

    parse_str($fake->lastRequest()->getUri()->getQuery(), $q);
    expect($q['prefix'])->toBe('local/d/')->and($q['limit'])->toBe('1');
});

it('directoryExists false when empty', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(200, ['objects' => []]));

    expect($fake->adapter()->directoryExists('d'))->toBeFalse();
});

it('directoryExists wraps errors', function () {
    $fake = FakeOci::make()->queue(new Response(500));

    $fake->adapter()->directoryExists('d');
})->throws(UnableToCheckExistence::class);

it('createDirectory puts trailing slash object', function () {
    $fake = FakeOci::make()->queue(new Response(200));

    $fake->adapter()->createDirectory('d', new Config);

    $r = $fake->lastRequest();
    expect($r->getMethod())->toBe('PUT')
        ->and($r->getUri()->getPath())->toEndWith('%2F')
        ->and((string) $r->getBody())->toBe('');
});

it('createDirectory wraps errors', function () {
    $fake = FakeOci::make()->queue(new Response(403));

    $fake->adapter()->createDirectory('d', new Config);
})->throws(UnableToCreateDirectory::class);

it('deleteDirectory deletes every listed object', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(
        FakeOci::json(200, ['objects' => [['name' => 'local/d/'], ['name' => 'local/d/a'], ['name' => 'local/d/b']]]),
        new Response(204), new Response(204), new Response(204),
    );

    $fake->adapter()->deleteDirectory('d');

    $deletes = array_values(array_filter($fake->requests(), fn ($r) => $r->getMethod() === 'DELETE'));
    expect($deletes)->toHaveCount(3)
        ->and($deletes[1]->getUri()->getPath())->toEndWith(rawurlencode('local/d/a'));
});

it('deleteDirectory partial failure throws naming failed key', function () {
    $fake = FakeOci::make()->queue(
        FakeOci::json(200, ['objects' => [['name' => 'd/a'], ['name' => 'd/b']]]),
        new Response(204), new Response(403),
    );

    try {
        $fake->adapter()->deleteDirectory('d');
        $this->fail('expected exception');
    } catch (UnableToDeleteDirectory $e) {
        expect($e->getMessage())->toContain('d/b');
    }
});

it('deleteDirectory on empty listing does nothing', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(200, ['objects' => []]));

    $fake->adapter()->deleteDirectory('d');

    expect($fake->requests())->toHaveCount(1);
});

it('bulkDelete treats 404 as deleted', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(new Response(404), new Response(204), new Response(500));

    $result = $fake->client()->bulkDelete(['a', 'b', 'c']);

    expect($result['deleted'])->toBe(['a', 'b'])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['path'])->toBe('c')
        ->and($fake->requests()[0]->getUri()->getPath())->toEndWith(rawurlencode('local/a'));
});

it('bulkDelete with empty list sends nothing', function () {
    $fake = FakeOci::make();

    expect($fake->client()->bulkDelete([]))->toBe(['deleted' => [], 'errors' => []])
        ->and($fake->requests())->toBeEmpty();
});
