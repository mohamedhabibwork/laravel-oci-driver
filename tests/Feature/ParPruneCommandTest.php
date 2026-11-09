<?php

use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Tests\Support\FakeOci;
use League\Flysystem\Filesystem;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-09 06:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Test seam: swap the 'oci' disk for one built around the FakeOci adapter.
 */
function useFakeDisk(FakeOci $fake): void
{
    $adapter = $fake->adapter();
    Storage::set('oci', new FilesystemAdapter(new Filesystem($adapter), $adapter, []));
}

/**
 * @return array<string, string>
 */
function par(string $id, string $name, string $timeExpires): array
{
    return ['id' => $id, 'name' => $name, 'objectName' => 'x', 'accessType' => 'ObjectRead', 'timeExpires' => $timeExpires, 'timeCreated' => '2026-11-01T00:00:00Z'];
}

function queuePages(FakeOci $fake, bool $withDeletes = false): void
{
    $responses = [
        FakeOci::json(200, [
            par('id/1', 'laravel-oci-expired', '2026-11-09T05:00:00Z'),
            par('id2', 'laravel-oci-live', '2026-11-09T07:00:00Z'),
            par('id3', 'foreign-expired', '2026-11-01T00:00:00Z'),
        ], ['opc-next-page' => 'tok2']),
        new Response(204),
        FakeOci::json(200, [par('id4', 'laravel-oci-expired2', '2026-10-01T00:00:00Z')]),
        new Response(204),
    ];

    if (! $withDeletes) {
        unset($responses[1], $responses[3]);
    }

    $fake->queue(...array_values($responses));
}

it('deletes only expired laravel-oci PARs across pages', function () {
    $fake = FakeOci::make();
    queuePages($fake, withDeletes: true);
    useFakeDisk($fake);

    $this->artisan('oci:par:prune', ['disk' => 'oci'])
        ->expectsOutput('Pruned 2 expired PARs')
        ->assertSuccessful();

    $requests = $fake->requests();
    expect($requests)->toHaveCount(4)
        ->and($requests[0]->getMethod())->toBe('GET')
        ->and((string) $requests[0]->getUri())->toEndWith('/n/ns/b/bkt/p/?limit=1000')
        ->and($requests[1]->getMethod())->toBe('DELETE')
        ->and((string) $requests[1]->getUri())->toEndWith('/p/id%2F1')
        ->and((string) $requests[2]->getUri())->toEndWith('/p/?limit=1000&page=tok2')
        ->and($requests[3]->getMethod())->toBe('DELETE')
        ->and((string) $requests[3]->getUri())->toEndWith('/p/id4');
});

it('deletes nothing on a dry run', function () {
    $fake = FakeOci::make();
    queuePages($fake);
    useFakeDisk($fake);

    $this->artisan('oci:par:prune', ['disk' => 'oci', '--dry-run' => true])
        ->expectsOutput('Would prune 2 expired PARs')
        ->assertSuccessful();

    $methods = array_map(fn ($r) => $r->getMethod(), $fake->requests());
    expect($methods)->toBe(['GET', 'GET']);
});
