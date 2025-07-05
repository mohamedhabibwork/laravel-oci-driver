<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when OCI signer validation fails.
 *
 * This exception is thrown when the OCI authentication signer encounters
 * invalid configuration or parameters that prevent proper request signing.
 */
final class SignerValidateException extends Exception
{
    /**
     * Create a new signer validation exception.
     *
     * @param  string  $message  The validation error message
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public function __construct(
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create an exception for missing required configuration.
     *
     * @param  array<string>  $missingKeys  List of missing configuration keys
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function missingConfiguration(array $missingKeys, ?Throwable $previous = null): static
    {
        $message = sprintf(
            'Missing required OCI configuration: %s. Please ensure all required environment variables are set.',
            implode(', ', $missingKeys)
        );

        return new self($message, $previous);
    }

    /**
     * Create an exception for invalid URL.
     *
     * @param  string  $url  The invalid URL
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function invalidUrl(string $url, ?Throwable $previous = null): static
    {
        $message = sprintf('Invalid URL provided for OCI request: %s', $url);

        return new static($message, $previous);
    }

    /**
     * Create an exception for invalid key fingerprint format.
     *
     * @param  string  $fingerprint  The invalid fingerprint
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function invalidFingerprint(string $fingerprint, ?Throwable $previous = null): static
    {
        $message = sprintf(
            'Invalid OCI key fingerprint format: %s. Expected format: xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx',
            $fingerprint
        );

        return new static($message, $previous);
    }
}
