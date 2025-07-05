<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when the OCI private key file cannot be found or accessed.
 *
 * This exception is typically thrown during authentication setup when the
 * specified private key file path is invalid or the file is not accessible.
 */
final class PrivateKeyFileNotFoundException extends Exception
{
    /**
     * Create a new exception instance for a missing private key file.
     *
     * @param  string  $keyPath  The path to the private key file that was not found
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public function __construct(
        string $keyPath,
        ?Throwable $previous = null
    ) {
        $message = sprintf(
            'OCI private key file not found at path: %s. Please ensure the file exists and is readable.',
            $keyPath
        );

        parent::__construct($message, 0, $previous);
    }

    /**
     * Create an exception for an invalid key path.
     *
     * @param  string  $keyPath  The invalid key path
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function invalidPath(string $keyPath, ?Throwable $previous = null): static
    {
        return new self($keyPath, $previous);
    }

    /**
     * Create an exception for an inaccessible key file.
     *
     * @param  string  $keyPath  The inaccessible key path
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function inaccessible(string $keyPath, ?Throwable $previous = null): static
    {
        $message = sprintf(
            'OCI private key file exists but is not readable at path: %s. Please check file permissions.',
            $keyPath
        );

        $exception = new static($keyPath, $previous);
        $exception->message = $message;

        return $exception;
    }
}
