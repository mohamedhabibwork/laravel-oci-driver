<?php

namespace LaravelOCI\LaravelOciDriver\Enums;

/**
 * Oracle Cloud Infrastructure Storage Tiers
 */
enum StorageTier: string
{
    case STANDARD = 'Standard';
    case ARCHIVE = 'Archive';
    case INFREQUENT_ACCESS = 'InfrequentAccess';

    /**
     * Get the value as a string for the OCI API
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Get the default storage tier
     */
    public static function default(): self
    {
        return self::STANDARD;
    }

    /**
     * Create from string value
     */
    public static function fromString(?string $value): self
    {
        return match ($value) {
            'Archive' => self::ARCHIVE,
            'InfrequentAccess' => self::INFREQUENT_ACCESS,
            default => self::STANDARD,
        };
    }
}
