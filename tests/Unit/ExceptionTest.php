<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Unit;

use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Exception\SignerValidateException;
use LaravelOCI\LaravelOciDriver\Exception\SigningValidationFailedException;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class ExceptionTest extends TestCase
{
    public function test_private_key_file_not_found_exception(): void
    {
        $keyPath = '/path/to/missing/key.pem';
        $exception = new PrivateKeyFileNotFoundException($keyPath);

        expect($exception->getMessage())
            ->toContain('OCI private key file not found at path: /path/to/missing/key.pem')
            ->toContain('Please ensure the file exists and is readable');
    }

    public function test_private_key_file_not_found_exception_with_previous(): void
    {
        $keyPath = '/path/to/missing/key.pem';
        $previous = new \Exception('Previous exception');
        $exception = new PrivateKeyFileNotFoundException($keyPath, $previous);

        expect($exception->getPrevious())->toBe($previous);
    }

    public function test_private_key_file_not_found_invalid_path(): void
    {
        $keyPath = '/invalid/path';
        $exception = PrivateKeyFileNotFoundException::invalidPath($keyPath);

        expect($exception)->toBeInstanceOf(PrivateKeyFileNotFoundException::class);
        expect($exception->getMessage())->toContain($keyPath);
    }

    public function test_private_key_file_not_found_inaccessible(): void
    {
        $keyPath = '/inaccessible/path';
        $exception = PrivateKeyFileNotFoundException::inaccessible($keyPath);

        expect($exception)->toBeInstanceOf(PrivateKeyFileNotFoundException::class);
        expect($exception->getMessage())
            ->toContain('exists but is not readable')
            ->toContain('check file permissions');
    }

    public function test_signer_validate_exception(): void
    {
        $message = 'Validation failed';
        $exception = new SignerValidateException($message);

        expect($exception->getMessage())->toBe($message);
    }

    public function test_signer_validate_exception_missing_configuration(): void
    {
        $missingKeys = ['tenancy_id', 'user_id'];
        $exception = SignerValidateException::missingConfiguration($missingKeys);

        expect($exception->getMessage())
            ->toContain('Missing required OCI configuration: tenancy_id, user_id')
            ->toContain('ensure all required environment variables are set');
    }

    public function test_signer_validate_exception_invalid_url(): void
    {
        $url = 'invalid-url';
        $exception = SignerValidateException::invalidUrl($url);

        expect($exception->getMessage())
            ->toContain('Invalid URL provided for OCI request: invalid-url');
    }

    public function test_signer_validate_exception_invalid_fingerprint(): void
    {
        $fingerprint = 'invalid-fingerprint';
        $exception = SignerValidateException::invalidFingerprint($fingerprint);

        expect($exception->getMessage())
            ->toContain('Invalid OCI key fingerprint format: invalid-fingerprint')
            ->toContain('Expected format: xx:xx:xx:xx');
    }

    public function test_signing_validation_failed_exception(): void
    {
        $message = 'Signing failed';
        $exception = new SigningValidationFailedException($message);

        expect($exception->getMessage())->toBe($message);
    }

    public function test_signing_validation_failed_cannot_generate_signature(): void
    {
        $exception = SigningValidationFailedException::cannotGenerateSignature();

        expect($exception->getMessage())
            ->toContain('Failed to generate OCI request signature')
            ->toContain('verify your private key is valid');
    }

    public function test_signing_validation_failed_cannot_verify_signature(): void
    {
        $exception = SigningValidationFailedException::cannotVerifySignature();

        expect($exception->getMessage())
            ->toContain('Failed to verify OCI request signature')
            ->toContain('could not be validated against the public key');
    }

    public function test_signing_validation_failed_invalid_private_key(): void
    {
        $exception = SigningValidationFailedException::invalidPrivateKey();

        expect($exception->getMessage())
            ->toContain('Invalid OCI private key format')
            ->toContain('ensure the private key is in valid PEM format');
    }

    public function test_signing_validation_failed_openssl_error(): void
    {
        $operation = 'signing';
        $exception = SigningValidationFailedException::opensslError($operation);

        expect($exception->getMessage())
            ->toContain('OpenSSL error during signing operation')
            ->toContain('check your OpenSSL installation');
    }

    public function test_all_exceptions_extend_base_exception(): void
    {
        expect(new PrivateKeyFileNotFoundException('/test'))->toBeInstanceOf(\Exception::class);
        expect(new SignerValidateException('test'))->toBeInstanceOf(\Exception::class);
        expect(new SigningValidationFailedException('test'))->toBeInstanceOf(\Exception::class);
    }

    public function test_all_exceptions_are_final(): void
    {
        $reflection1 = new \ReflectionClass(PrivateKeyFileNotFoundException::class);
        $reflection2 = new \ReflectionClass(SignerValidateException::class);
        $reflection3 = new \ReflectionClass(SigningValidationFailedException::class);

        expect($reflection1->isFinal())->toBeTrue();
        expect($reflection2->isFinal())->toBeTrue();
        expect($reflection3->isFinal())->toBeTrue();
    }
}
