<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Support;

/**
 * Encodes object names for use as a single URI path segment.
 *
 * @internal
 */
final class ObjectNameEncoder
{
    public static function encode(string $objectName): string
    {
        return rawurlencode($objectName);
    }
}
