<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Unit;

use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class StorageTierTest extends TestCase
{
    public function test_storage_tier_values(): void
    {
        expect(StorageTier::STANDARD->value)->toBe('Standard');
        expect(StorageTier::ARCHIVE->value)->toBe('Archive');
        expect(StorageTier::INFREQUENT_ACCESS->value)->toBe('InfrequentAccess');
    }

    public function test_storage_tier_value_method(): void
    {
        expect(StorageTier::STANDARD->value())->toBe('Standard');
        expect(StorageTier::ARCHIVE->value())->toBe('Archive');
        expect(StorageTier::INFREQUENT_ACCESS->value())->toBe('InfrequentAccess');
    }

    public function test_default_storage_tier(): void
    {
        expect(StorageTier::default())->toBe(StorageTier::STANDARD);
    }

    public function test_from_string_with_valid_values(): void
    {
        expect(StorageTier::fromString('Standard'))->toBe(StorageTier::STANDARD);
        expect(StorageTier::fromString('Archive'))->toBe(StorageTier::ARCHIVE);
        expect(StorageTier::fromString('InfrequentAccess'))->toBe(StorageTier::INFREQUENT_ACCESS);
    }

    public function test_from_string_with_invalid_values(): void
    {
        expect(StorageTier::fromString('Invalid'))->toBe(StorageTier::STANDARD);
        expect(StorageTier::fromString(''))->toBe(StorageTier::STANDARD);
        expect(StorageTier::fromString(null))->toBe(StorageTier::STANDARD);
    }

    public function test_from_string_case_sensitivity(): void
    {
        expect(StorageTier::fromString('standard'))->toBe(StorageTier::STANDARD);
        expect(StorageTier::fromString('ARCHIVE'))->toBe(StorageTier::STANDARD);
        expect(StorageTier::fromString('infrequentaccess'))->toBe(StorageTier::STANDARD);
    }

    public function test_all_storage_tiers_are_covered(): void
    {
        $cases = StorageTier::cases();

        expect($cases)->toHaveCount(3);
        expect($cases)->toContain(StorageTier::STANDARD);
        expect($cases)->toContain(StorageTier::ARCHIVE);
        expect($cases)->toContain(StorageTier::INFREQUENT_ACCESS);
    }
}
