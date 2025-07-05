<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Unit;

use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\KeyProvider\EnvironmentKeyProvider;
use LaravelOCI\LaravelOciDriver\KeyProvider\FileKeyProvider;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class KeyProviderTest extends TestCase
{
    private string $testKeyContent = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKB\n-----END PRIVATE KEY-----";

    public function test_file_key_provider_constructor(): void
    {
        $provider = new FileKeyProvider(
            '/path/to/key.pem',
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        expect($provider->getKeyId())->toBe('tenancy-id/user-id/fingerprint');
    }

    public function test_file_key_provider_from_config(): void
    {
        $config = [
            'key_path' => '/path/to/key.pem',
            'tenancy_id' => 'tenancy-id',
            'user_id' => 'user-id',
            'key_fingerprint' => 'fingerprint',
        ];

        $provider = FileKeyProvider::fromConfig($config);

        expect($provider->getKeyId())->toBe('tenancy-id/user-id/fingerprint');
    }

    public function test_file_key_provider_from_config_missing_keys(): void
    {
        $config = [
            'key_path' => '/path/to/key.pem',
            // Missing other required keys
        ];

        expect(fn () => FileKeyProvider::fromConfig($config))
            ->toThrow(\InvalidArgumentException::class, 'Missing required configuration keys');
    }

    public function test_file_key_provider_get_private_key_file_not_exists(): void
    {
        $provider = new FileKeyProvider(
            '/nonexistent/key.pem',
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        expect(fn () => $provider->getPrivateKey())
            ->toThrow(PrivateKeyFileNotFoundException::class);
    }

    public function test_environment_key_provider_constructor(): void
    {
        $provider = new EnvironmentKeyProvider(
            $this->testKeyContent,
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        expect($provider->getKeyId())->toBe('tenancy-id/user-id/fingerprint');
        expect($provider->getPrivateKey())->toBe($this->testKeyContent);
    }

    public function test_environment_key_provider_from_config(): void
    {
        $config = [
            'private_key_content' => $this->testKeyContent,
            'tenancy_id' => 'tenancy-id',
            'user_id' => 'user-id',
            'key_fingerprint' => 'fingerprint',
        ];

        $provider = EnvironmentKeyProvider::fromConfig($config);

        expect($provider->getKeyId())->toBe('tenancy-id/user-id/fingerprint');
        expect($provider->getPrivateKey())->toBe($this->testKeyContent);
    }

    public function test_environment_key_provider_from_config_missing_keys(): void
    {
        $config = [
            'private_key_content' => $this->testKeyContent,
            // Missing other required keys
        ];

        expect(fn () => EnvironmentKeyProvider::fromConfig($config))
            ->toThrow(\InvalidArgumentException::class, 'Missing required configuration keys');
    }

    public function test_environment_key_provider_from_base64(): void
    {
        $base64Key = base64_encode($this->testKeyContent);

        $provider = EnvironmentKeyProvider::fromBase64(
            $base64Key,
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        expect($provider->getPrivateKey())->toBe($this->testKeyContent);
    }

    public function test_environment_key_provider_from_base64_invalid(): void
    {
        expect(fn () => EnvironmentKeyProvider::fromBase64(
            'invalid-base64!@#',
            'tenancy-id',
            'user-id',
            'fingerprint'
        ))->toThrow(\InvalidArgumentException::class, 'Invalid base64-encoded private key');
    }

    public function test_environment_key_provider_validate_key_empty(): void
    {
        $provider = new EnvironmentKeyProvider(
            '',
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        expect(fn () => $provider->validateKey())
            ->toThrow(\InvalidArgumentException::class, 'Private key content is empty');
    }

    public function test_environment_key_provider_validate_key_invalid_format(): void
    {
        $provider = new EnvironmentKeyProvider(
            'not-a-pem-key',
            'tenancy-id',
            'user-id',
            'fingerprint'
        );

        expect(fn () => $provider->validateKey())
            ->toThrow(\InvalidArgumentException::class, 'does not appear to be in PEM format');
    }

    public function test_key_providers_implement_interface(): void
    {
        $fileProvider = new FileKeyProvider('/test', 'tenancy', 'user', 'fingerprint');
        $envProvider = new EnvironmentKeyProvider('content', 'tenancy', 'user', 'fingerprint');

        expect($fileProvider)->toBeInstanceOf(\LaravelOCI\LaravelOciDriver\KeyProvider\KeyProviderInterface::class);
        expect($envProvider)->toBeInstanceOf(\LaravelOCI\LaravelOciDriver\KeyProvider\KeyProviderInterface::class);
    }

    public function test_key_providers_are_readonly(): void
    {
        $fileReflection = new \ReflectionClass(FileKeyProvider::class);
        $envReflection = new \ReflectionClass(EnvironmentKeyProvider::class);

        expect($fileReflection->isReadOnly())->toBeTrue();
        expect($envReflection->isReadOnly())->toBeTrue();
    }

    public function test_key_providers_are_final(): void
    {
        $fileReflection = new \ReflectionClass(FileKeyProvider::class);
        $envReflection = new \ReflectionClass(EnvironmentKeyProvider::class);

        expect($fileReflection->isFinal())->toBeTrue();
        expect($envReflection->isFinal())->toBeTrue();
    }
}
