<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Enums;

/**
 * OCI Connection Types
 *
 * Defines the different types of OCI connections available.
 */
enum ConnectionType: string
{
    case PRIMARY = 'primary';
    case SECONDARY = 'secondary';
    case BACKUP = 'backup';
    case DEVELOPMENT = 'development';
    case TESTING = 'testing';
    case STAGING = 'staging';
    case PRODUCTION = 'production';
    case ARCHIVE = 'archive';

    /**
     * Get the connection type value.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Get human-readable name for the connection type.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::PRIMARY => 'Primary Connection',
            self::SECONDARY => 'Secondary Connection',
            self::BACKUP => 'Backup Connection',
            self::DEVELOPMENT => 'Development Environment',
            self::TESTING => 'Testing Environment',
            self::STAGING => 'Staging Environment',
            self::PRODUCTION => 'Production Environment',
            self::ARCHIVE => 'Archive Storage',
        };
    }

    /**
     * Get the default storage tier for this connection type.
     */
    public function getDefaultStorageTier(): StorageTier
    {
        return match ($this) {
            self::PRIMARY, self::PRODUCTION => StorageTier::STANDARD,
            self::SECONDARY, self::STAGING => StorageTier::STANDARD,
            self::BACKUP, self::ARCHIVE => StorageTier::ARCHIVE,
            self::DEVELOPMENT, self::TESTING => StorageTier::INFREQUENT_ACCESS,
        };
    }

    /**
     * Check if this connection type is for production use.
     */
    public function isProduction(): bool
    {
        return match ($this) {
            self::PRODUCTION, self::PRIMARY => true,
            default => false,
        };
    }

    /**
     * Create from string value.
     */
    public static function fromString(?string $type): self
    {
        if ($type === null) {
            return self::PRIMARY;
        }

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === strtolower($type)) {
                return $case;
            }
        }

        return self::PRIMARY;
    }

    /**
     * Get environment-specific connection types.
     *
     * @return array<self>
     */
    public static function getEnvironmentTypes(): array
    {
        return [
            self::DEVELOPMENT,
            self::TESTING,
            self::STAGING,
            self::PRODUCTION,
        ];
    }

    /**
     * Get purpose-specific connection types.
     *
     * @return array<self>
     */
    public static function getPurposeTypes(): array
    {
        return [
            self::PRIMARY,
            self::SECONDARY,
            self::BACKUP,
            self::ARCHIVE,
        ];
    }
}
