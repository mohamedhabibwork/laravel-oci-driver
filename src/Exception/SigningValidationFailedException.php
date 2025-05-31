<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when OCI request signing validation fails.
 *
 * This exception is thrown when the cryptographic signing process fails
 * or when signature verification does not pass validation.
 */
final class SigningValidationFailedException extends Exception
{
    /**
     * Create a new signing validation exception.
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
     * Create an exception for signature generation failure.
     *
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function cannotGenerateSignature(?Throwable $previous = null): static
    {
        $message = 'Failed to generate OCI request signature. Please verify your private key is valid and properly formatted.';

        return new self($message, $previous);
    }

    /**
     * Create an exception for signature verification failure.
     *
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function cannotVerifySignature(?Throwable $previous = null): static
    {
        $message = 'Failed to verify OCI request signature. The generated signature could not be validated against the public key.';

        return new static($message, $previous);
    }

    /**
     * Create an exception for invalid private key format.
     *
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function invalidPrivateKey(?Throwable $previous = null): static
    {
        $message = 'Invalid OCI private key format. Please ensure the private key is in valid PEM format.';

        return new static($message, $previous);
    }

    /**
     * Create an exception for OpenSSL errors.
     *
     * @param  string  $operation  The operation that failed
     * @param  Throwable|null  $previous  Previous exception for chaining
     */
    public static function opensslError(string $operation, ?Throwable $previous = null): static
    {
        $message = sprintf(
            'OpenSSL error during %s operation. Please check your OpenSSL installation and private key format.',
            $operation
        );

        return new static($message, $previous);
    }
}
