<?php

use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToListContents;
use Psr\Http\Message\RequestInterface;

/**
 * @return array<int|string, mixed>
 */
function queryOf(RequestInterface $request): array
{
    parse_str($request->getUri()->getQuery(), $query);

    return $query;
}

it('pages until nextStartWith is absent', function () {
    $fake = FakeOci::make()->queue(
        FakeOci::json(200, ['objects' => [['name' => 'a', 'size' => 1]], 'nextStartWith' => 'b']),
        FakeOci::json(200, ['objects' => [['name' => 'b', 'size' => 2]]]),
    );

    $paths = array_map(fn ($i) => $i->path(), iterator_to_array($fake->adapter()->listContents('', true), false));

    expect($paths)->toBe(['a', 'b'])
        ->and($fake->requests())->toHaveCount(2)
        ->and(queryOf($fake->requests()[1])['start'])->toBe('b');
});

it('requests fields', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(200, ['objects' => []]));

    iterator_to_array($fake->adapter()->listContents('', true));

    $query = queryOf($fake->lastRequest());
    expect($query['fields'])->toBe('name,size,etag,timeCreated,timeModified,md5,storageTier')
        ->and($query['limit'])->toBe('1000')
        ->and($query)->not->toHaveKey('delimiter');
});

it('strips prefix and maps attributes', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(FakeOci::json(200, ['objects' => [
        ['name' => 'local/x/a.txt', 'size' => 5, 'timeModified' => '2026-11-09T06:00:00Z', 'md5' => 'abc', 'storageTier' => 'Standard'],
    ]]));

    $items = iterator_to_array($fake->adapter()->listContents('x', true), false);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(FileAttributes::class)
        ->and($items[0]->path())->toBe('x/a.txt')
        ->and($items[0]->fileSize())->toBe(5)
        ->and($items[0]->lastModified())->toBe(1794204000)
        ->and($items[0]->extraMetadata()['md5'])->toBe('abc')
        ->and($items[0]->extraMetadata()['storage_tier'])->toBe('Standard')
        ->and(queryOf($fake->lastRequest())['prefix'])->toBe('local/x/');
});

it('non-deep yields DirectoryAttributes for prefixes', function () {
    $fake = FakeOci::make(['url_path_prefix' => 'local'])->queue(
        FakeOci::json(200, ['objects' => [], 'prefixes' => ['local/x/']]),
    );

    $items = iterator_to_array($fake->adapter()->listContents('', false), false);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(DirectoryAttributes::class)
        ->and($items[0]->path())->toBe('x')
        ->and(queryOf($fake->lastRequest())['delimiter'])->toBe('/')
        ->and(queryOf($fake->lastRequest())['prefix'])->toBe('local/');
});

it('placeholder plus children across two pages yields one directory and skips own placeholder', function () {
    $fake = FakeOci::make()->queue(
        FakeOci::json(200, ['objects' => [
            ['name' => 'd/', 'size' => 0],
            ['name' => 'd/x/', 'size' => 0],
            ['name' => 'd/x/1', 'size' => 1],
        ], 'nextStartWith' => 'd/x/2']),
        FakeOci::json(200, ['objects' => [['name' => 'd/x/2', 'size' => 1]]]),
    );

    $items = iterator_to_array($fake->adapter()->listContents('d', true), false);
    $dirs = array_map(fn ($i) => $i->path(), array_filter($items, fn ($i) => $i instanceof DirectoryAttributes));
    $files = array_map(fn ($i) => $i->path(), array_filter($items, fn ($i) => $i instanceof FileAttributes));

    expect(array_values($dirs))->toBe(['d/x'])
        ->and(array_values($files))->toBe(['d/x/1', 'd/x/2']);
});

it('api error throws UnableToListContents', function () {
    $fake = FakeOci::make()->queue(FakeOci::json(500, ['code' => 'InternalServerError', 'message' => 'boom']));

    iterator_to_array($fake->adapter()->listContents('', true));
})->throws(UnableToListContents::class);

it('stops paging when nextStartWith repeats', function () {
    $fake = FakeOci::make()->queue(
        FakeOci::json(200, ['objects' => [['name' => 'a', 'size' => 1]], 'nextStartWith' => 'b']),
        FakeOci::json(200, ['objects' => [['name' => 'b', 'size' => 1]], 'nextStartWith' => 'b']),
    );

    iterator_to_array($fake->adapter()->listContents('', true));

    expect($fake->requests())->toHaveCount(2);
});

it('listObjects throws on api error instead of returning empty', function () {
    $fake = FakeOci::make()->queue(new Response(500));

    $fake->client()->listObjects(['prefix' => 'a']);
})->throws(OciRequestFailed::class);
