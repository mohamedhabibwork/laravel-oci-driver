<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Unit;

use LaravelOCI\LaravelOciDriver\Enums\ConnectionType;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class ConnectionTypeTest extends TestCase
{
    public function test_enum_has_correct_values(): void
    {
        expect(ConnectionType::PRIMARY->value)->toBe('primary');
        expect(ConnectionType::SECONDARY->value)->toBe('secondary');
        expect(ConnectionType::BACKUP->value)->toBe('backup');
        expect(ConnectionType::DEVELOPMENT->value)->toBe('development');
        expect(ConnectionType::TESTING->value)->toBe('testing');
        expect(ConnectionType::STAGING->value)->toBe('staging');
        expect(ConnectionType::PRODUCTION->value)->toBe('production');
        expect(ConnectionType::ARCHIVE->value)->toBe('archive');
    }

    public function test_can_get_display_names(): void
    {
        expect(ConnectionType::PRIMARY->getDisplayName())->toBe('Primary Connection');
        expect(ConnectionType::SECONDARY->getDisplayName())->toBe('Secondary Connection');
        expect(ConnectionType::BACKUP->getDisplayName())->toBe('Backup Connection');
        expect(ConnectionType::DEVELOPMENT->getDisplayName())->toBe('Development Environment');
        expect(ConnectionType::TESTING->getDisplayName())->toBe('Testing Environment');
        expect(ConnectionType::STAGING->getDisplayName())->toBe('Staging Environment');
        expect(ConnectionType::PRODUCTION->getDisplayName())->toBe('Production Environment');
        expect(ConnectionType::ARCHIVE->getDisplayName())->toBe('Archive Storage');
    }

    public function test_can_get_default_storage_tier(): void
    {
        expect(ConnectionType::PRIMARY->getDefaultStorageTier())->toBe(StorageTier::STANDARD);
        expect(ConnectionType::PRODUCTION->getDefaultStorageTier())->toBe(StorageTier::STANDARD);
        expect(ConnectionType::SECONDARY->getDefaultStorageTier())->toBe(StorageTier::STANDARD);
        expect(ConnectionType::STAGING->getDefaultStorageTier())->toBe(StorageTier::STANDARD);
        expect(ConnectionType::BACKUP->getDefaultStorageTier())->toBe(StorageTier::ARCHIVE);
        expect(ConnectionType::ARCHIVE->getDefaultStorageTier())->toBe(StorageTier::ARCHIVE);
        expect(ConnectionType::DEVELOPMENT->getDefaultStorageTier())->toBe(StorageTier::INFREQUENT_ACCESS);
        expect(ConnectionType::TESTING->getDefaultStorageTier())->toBe(StorageTier::INFREQUENT_ACCESS);
    }

    public function test_can_identify_production_connections(): void
    {
        expect(ConnectionType::PRIMARY->isProduction())->toBeTrue();
        expect(ConnectionType::PRODUCTION->isProduction())->toBeTrue();

        expect(ConnectionType::SECONDARY->isProduction())->toBeFalse();
        expect(ConnectionType::BACKUP->isProduction())->toBeFalse();
        expect(ConnectionType::DEVELOPMENT->isProduction())->toBeFalse();
        expect(ConnectionType::TESTING->isProduction())->toBeFalse();
        expect(ConnectionType::STAGING->isProduction())->toBeFalse();
        expect(ConnectionType::ARCHIVE->isProduction())->toBeFalse();
    }

    public function test_can_create_from_string(): void
    {
        expect(ConnectionType::fromString('primary'))->toBe(ConnectionType::PRIMARY);
        expect(ConnectionType::fromString('PRODUCTION'))->toBe(ConnectionType::PRODUCTION);
        expect(ConnectionType::fromString('Development'))->toBe(ConnectionType::DEVELOPMENT);

        // Invalid or null values should default to PRIMARY
        expect(ConnectionType::fromString('invalid'))->toBe(ConnectionType::PRIMARY);
        expect(ConnectionType::fromString(null))->toBe(ConnectionType::PRIMARY);
        expect(ConnectionType::fromString(''))->toBe(ConnectionType::PRIMARY);
    }

    public function test_value_method_returns_correct_string(): void
    {
        expect(ConnectionType::PRIMARY->value())->toBe('primary');
        expect(ConnectionType::PRODUCTION->value())->toBe('production');
        expect(ConnectionType::DEVELOPMENT->value())->toBe('development');
    }

    public function test_get_environment_types(): void
    {
        $envTypes = ConnectionType::getEnvironmentTypes();

        expect($envTypes)->toBeArray();
        expect($envTypes)->toHaveCount(4);
        expect($envTypes)->toContain(ConnectionType::DEVELOPMENT);
        expect($envTypes)->toContain(ConnectionType::TESTING);
        expect($envTypes)->toContain(ConnectionType::STAGING);
        expect($envTypes)->toContain(ConnectionType::PRODUCTION);

        // Should not contain purpose types
        expect($envTypes)->not->toContain(ConnectionType::PRIMARY);
        expect($envTypes)->not->toContain(ConnectionType::BACKUP);
        expect($envTypes)->not->toContain(ConnectionType::ARCHIVE);
    }

    public function test_get_purpose_types(): void
    {
        $purposeTypes = ConnectionType::getPurposeTypes();

        expect($purposeTypes)->toBeArray();
        expect($purposeTypes)->toHaveCount(4);
        expect($purposeTypes)->toContain(ConnectionType::PRIMARY);
        expect($purposeTypes)->toContain(ConnectionType::SECONDARY);
        expect($purposeTypes)->toContain(ConnectionType::BACKUP);
        expect($purposeTypes)->toContain(ConnectionType::ARCHIVE);

        // Should not contain environment types
        expect($purposeTypes)->not->toContain(ConnectionType::DEVELOPMENT);
        expect($purposeTypes)->not->toContain(ConnectionType::TESTING);
        expect($purposeTypes)->not->toContain(ConnectionType::STAGING);
        expect($purposeTypes)->not->toContain(ConnectionType::PRODUCTION);
    }

    public function test_all_connection_types_have_unique_values(): void
    {
        $types = ConnectionType::cases();
        $values = array_map(fn (ConnectionType $type) => $type->value, $types);

        expect(count($values))->toBe(count(array_unique($values)));
    }

    public function test_all_connection_types_have_display_names(): void
    {
        foreach (ConnectionType::cases() as $type) {
            $displayName = $type->getDisplayName();
            expect($displayName)->toBeString();
            expect($displayName)->not->toBeEmpty();
        }
    }

    public function test_all_connection_types_have_default_storage_tiers(): void
    {
        foreach (ConnectionType::cases() as $type) {
            $storageTier = $type->getDefaultStorageTier();
            expect($storageTier)->toBeInstanceOf(StorageTier::class);
        }
    }

    public function test_case_insensitive_string_conversion(): void
    {
        expect(ConnectionType::fromString('PRIMARY'))->toBe(ConnectionType::PRIMARY);
        expect(ConnectionType::fromString('Primary'))->toBe(ConnectionType::PRIMARY);
        expect(ConnectionType::fromString('pRiMaRy'))->toBe(ConnectionType::PRIMARY);
    }
}
