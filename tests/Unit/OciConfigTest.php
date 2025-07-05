<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Unit;

use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\Enums\ConnectionType;
use LaravelOCI\LaravelOciDriver\Enums\LogLevel;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class OciConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $validConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary key file for testing
        $tempKeyPath = '/tmp/oci_test_key.pem';
        if (! file_exists($tempKeyPath)) {
            file_put_contents($tempKeyPath, "-----BEGIN PRIVATE KEY-----\ntest content\n-----END PRIVATE KEY-----");
        }

        $this->validConfig = [
            'tenancy_id' => 'ocid1.tenancy.oc1..aaaaaaaabbbbbbbbbcccccccccdddddddddeeeeeeeeeffffffffggggggggg',
            'user_id' => 'ocid1.user.oc1..aaaaaaaabbbbbbbbbcccccccccdddddddddeeeeeeeeeffffffffggggggggg',
            'key_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'key_path' => '/tmp/oci_test_key.pem',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'storage_tier' => 'Standard',
            'connection_type' => 'primary',
            'timeout' => 30,
            'cache' => [
                'enabled' => true,
                'ttl' => 300,
            ],
            'logging' => [
                'enabled' => false,
                'level' => 'info',
            ],
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temporary key file
        $tempKeyPath = '/tmp/oci_test_key.pem';
        if (file_exists($tempKeyPath)) {
            unlink($tempKeyPath);
        }
    }

    public function test_can_create_config_with_array(): void
    {
        $config = new OciConfig($this->validConfig);

        expect($config->getTenancyId())->toBe($this->validConfig['tenancy_id']);
        expect($config->getUserId())->toBe($this->validConfig['user_id']);
        expect($config->getKeyFingerprint())->toBe($this->validConfig['key_fingerprint']);
        expect($config->getKeyPath())->toBe($this->validConfig['key_path']);
        expect($config->getNamespace())->toBe($this->validConfig['namespace']);
        expect($config->getRegion())->toBe($this->validConfig['region']);
        expect($config->getBucket())->toBe($this->validConfig['bucket']);
    }

    public function test_can_get_storage_tier_enum(): void
    {
        $config = new OciConfig($this->validConfig);

        expect($config->getStorageTier())->toBe(StorageTier::STANDARD);
    }

    public function test_can_get_connection_type_enum(): void
    {
        $config = new OciConfig($this->validConfig);

        expect($config->getConnectionType())->toBe(ConnectionType::PRIMARY);
    }

    public function test_can_get_log_level_enum(): void
    {
        $config = new OciConfig($this->validConfig);

        expect($config->getLogLevel())->toBe(LogLevel::INFO);
    }

    public function test_handles_invalid_region_gracefully(): void
    {
        $configWithInvalidRegion = $this->validConfig;
        $configWithInvalidRegion['region'] = 'invalid-region';

        $config = new OciConfig($configWithInvalidRegion);

        expect($config->getRegion())->toBe('invalid-region');
    }

    public function test_handles_invalid_storage_tier_gracefully(): void
    {
        $configWithInvalidTier = $this->validConfig;
        $configWithInvalidTier['storage_tier'] = 'InvalidTier';

        $config = new OciConfig($configWithInvalidTier);

        expect($config->getStorageTier())->toBe(StorageTier::STANDARD); // Should default
    }

    public function test_can_get_nested_configuration_values(): void
    {
        $config = new OciConfig($this->validConfig);

        expect($config->get('cache.enabled'))->toBe(true);
        expect($config->get('cache.ttl'))->toBe(300);
        expect($config->get('logging.enabled'))->toBe(false);
        expect($config->get('logging.level'))->toBe('info');
    }

    public function test_validates_required_fields(): void
    {
        $incompleteConfig = [
            'tenancy_id' => 'test-tenancy',
            'user_id' => 'test-user',
            // Missing required fields
        ];

        $config = new OciConfig($incompleteConfig);
        $errors = $config->validate();

        expect($errors)->not->toBeEmpty();

        $errorString = implode(' ', $errors);
        expect($errorString)->toContain('key_fingerprint');
        expect($errorString)->toContain('key_path');
        expect($errorString)->toContain('namespace');
        expect($errorString)->toContain('region');
        expect($errorString)->toContain('bucket');
    }

    public function test_validates_fingerprint_format(): void
    {
        $configWithInvalidFingerprint = $this->validConfig;
        $configWithInvalidFingerprint['key_fingerprint'] = 'invalid-fingerprint';

        $config = new OciConfig($configWithInvalidFingerprint);
        $errors = $config->validate();

        $errorString = implode(' ', $errors);
        expect($errorString)->toContain('Invalid key fingerprint format');
    }

    public function test_validates_region_format(): void
    {
        $configWithInvalidRegion = $this->validConfig;
        $configWithInvalidRegion['region'] = 'INVALID_REGION!';

        $config = new OciConfig($configWithInvalidRegion);
        $errors = $config->validate();

        $errorString = implode(' ', $errors);
        expect($errorString)->toContain('Invalid region format');
    }

    public function test_is_valid_returns_correct_boolean(): void
    {
        $validConfig = new OciConfig($this->validConfig);
        expect($validConfig->isValid())->toBeTrue();

        $invalidConfig = new OciConfig(['tenancy_id' => 'test']);
        expect($invalidConfig->isValid())->toBeFalse();
    }

    public function test_can_get_connection_summary(): void
    {
        $config = new OciConfig($this->validConfig);
        $summary = $config->getConnectionSummary();

        expect($summary)->toBeArray();
        expect($summary)->toHaveKey('connection_type');
        expect($summary)->toHaveKey('region');
        expect($summary)->toHaveKey('namespace');
        expect($summary)->toHaveKey('bucket');
        expect($summary)->toHaveKey('storage_tier');
        expect($summary)->toHaveKey('cache_enabled');
        expect($summary)->toHaveKey('logging_enabled');
        expect($summary)->toHaveKey('log_level');

        expect($summary['connection_type'])->toBe('Primary Connection');
        expect($summary['region'])->toBe('us-phoenix-1');
        expect($summary['storage_tier'])->toBe('Standard');
        expect($summary['cache_enabled'])->toBe(true);
        expect($summary['logging_enabled'])->toBe(false);
        expect($summary['log_level'])->toBe('info');
    }

    public function test_can_clone_with_overrides(): void
    {
        $config = new OciConfig($this->validConfig);
        $overrides = ['bucket' => 'new-bucket', 'region' => 'us-ashburn-1'];

        $newConfig = $config->withOverrides($overrides);

        expect($newConfig->getBucket())->toBe('new-bucket');
        expect($newConfig->getRegion())->toBe('us-ashburn-1');
        expect($newConfig->getNamespace())->toBe($this->validConfig['namespace']);
    }

    public function test_can_get_url_path_prefix(): void
    {
        $config = new OciConfig($this->validConfig);
        expect($config->getUrlPathPrefix())->toBe('');

        $configWithPrefix = new OciConfig(array_merge($this->validConfig, [
            'url_path_prefix' => 'my-prefix',
        ]));
        expect($configWithPrefix->getUrlPathPrefix())->toBe('my-prefix/');

        $configWithSlashes = new OciConfig(array_merge($this->validConfig, [
            'url_path_prefix' => '/my-prefix/',
        ]));
        expect($configWithSlashes->getUrlPathPrefix())->toBe('my-prefix/');
    }

    public function test_url_path_prefix_handles_empty_values(): void
    {
        $configWithEmpty = new OciConfig(array_merge($this->validConfig, [
            'url_path_prefix' => '',
        ]));
        expect($configWithEmpty->getUrlPathPrefix())->toBe('');

        $configWithNull = new OciConfig(array_merge($this->validConfig, [
            'url_path_prefix' => null,
        ]));
        expect($configWithNull->getUrlPathPrefix())->toBe('');

        $configWithSpaces = new OciConfig(array_merge($this->validConfig, [
            'url_path_prefix' => '   ',
        ]));
        expect($configWithSpaces->getUrlPathPrefix())->toBe('');
    }

    public function test_url_path_prefix_normalizes_paths(): void
    {
        $testCases = [
            'simple' => 'simple/',
            'nested/path' => 'nested/path/',
            '/leading-slash' => 'leading-slash/',
            'trailing-slash/' => 'trailing-slash/',
            '/both-slashes/' => 'both-slashes/',
            'deep/nested/path' => 'deep/nested/path/',
            'with-dashes-and_underscores' => 'with-dashes-and_underscores/',
        ];

        foreach ($testCases as $input => $expected) {
            $config = new OciConfig(array_merge($this->validConfig, [
                'url_path_prefix' => $input,
            ]));
            expect($config->getUrlPathPrefix())->toBe($expected);
        }
    }

    public function test_can_clone_with_overrides_comprehensive(): void
    {
        $originalConfig = new OciConfig($this->validConfig);

        $overrides = [
            'bucket' => 'new-bucket',
            'storage_tier' => 'Archive',
            'timeout' => 60,
            'url_path_prefix' => 'new-prefix',
        ];

        $newConfig = $originalConfig->withOverrides($overrides);

        // Original should be unchanged
        expect($originalConfig->getBucket())->toBe('test-bucket');
        expect($originalConfig->getStorageTier())->toBe(StorageTier::STANDARD);
        expect($originalConfig->getTimeout())->toBe(30);
        expect($originalConfig->getUrlPathPrefix())->toBe('');

        // New config should have overrides
        expect($newConfig->getBucket())->toBe('new-bucket');
        expect($newConfig->getStorageTier())->toBe(StorageTier::ARCHIVE);
        expect($newConfig->getTimeout())->toBe(60);
        expect($newConfig->getUrlPathPrefix())->toBe('new-prefix/');

        // Other values should remain the same
        expect($newConfig->getTenancyId())->toBe($originalConfig->getTenancyId());
        expect($newConfig->getUserId())->toBe($originalConfig->getUserId());
    }

    public function test_prefix_in_connection_summary(): void
    {
        $configWithPrefix = new OciConfig(array_merge($this->validConfig, [
            'url_path_prefix' => 'my-app/uploads',
        ]));

        $summary = $configWithPrefix->getConnectionSummary();

        expect($summary)->toBeArray();
        expect($summary)->toHaveKey('url_path_prefix');
        expect($summary['url_path_prefix'])->toBe('my-app/uploads/');
    }

    public function test_static_methods_for_connection_management(): void
    {
        // Mock configuration for testing
        config([
            'laravel-oci-driver.connections.test1' => $this->validConfig,
            'laravel-oci-driver.connections.test2' => array_merge($this->validConfig, ['bucket' => 'test2-bucket']),
            'laravel-oci-driver.default' => 'test1',
        ]);

        // Test available connections
        $connections = OciConfig::getAvailableConnections();
        expect($connections)->toContain('test1');
        expect($connections)->toContain('test2');

        // Test connection exists
        expect(OciConfig::connectionExists('test1'))->toBeTrue();
        expect(OciConfig::connectionExists('nonexistent'))->toBeFalse();

        // Test default connection
        expect(OciConfig::getDefaultConnection())->toBe('test1');

        // Test switching connections
        $switchedConfig = OciConfig::switchConnection('test2');
        expect($switchedConfig->getBucket())->toBe('test2-bucket');
    }

    public function test_from_connection_throws_for_invalid_connection(): void
    {
        expect(fn () => OciConfig::fromConnection('nonexistent'))
            ->toThrow(\InvalidArgumentException::class, "OCI connection 'nonexistent' not found in configuration");
    }

    public function test_for_connections_returns_valid_configs(): void
    {
        config([
            'laravel-oci-driver.connections.valid' => $this->validConfig,
            'laravel-oci-driver.connections.invalid' => ['incomplete' => 'config'],
        ]);

        $configs = OciConfig::forConnections(['valid', 'invalid', 'nonexistent']);

        expect($configs)->toHaveKey('valid');
        // The invalid config will be created but should be invalid
        if (isset($configs['invalid'])) {
            expect($configs['invalid']->isValid())->toBeFalse();
        }
        expect($configs)->not->toHaveKey('nonexistent'); // Should skip nonexistent

        expect($configs['valid'])->toBeInstanceOf(OciConfig::class);
        expect($configs['valid']->getBucket())->toBe('test-bucket');
    }

    public function test_cache_and_logging_convenience_methods(): void
    {
        $config = new OciConfig($this->validConfig);

        expect($config->isCacheEnabled())->toBe(true);
        expect($config->isLoggingEnabled())->toBe(false);

        // Test with different values
        $configWithDifferentSettings = new OciConfig(array_merge($this->validConfig, [
            'cache' => ['enabled' => false],
            'logging' => ['enabled' => true],
        ]));

        expect($configWithDifferentSettings->isCacheEnabled())->toBe(false);
        expect($configWithDifferentSettings->isLoggingEnabled())->toBe(true);
    }

    public function test_get_upload_config(): void
    {
        $config = new OciConfig($this->validConfig);
        $uploadConfig = $config->getUploadConfig();

        expect($uploadConfig)->toHaveKey('chunk_size');
        expect($uploadConfig)->toHaveKey('multipart_threshold');
        expect($uploadConfig)->toHaveKey('enable_checksum');
    }

    public function test_get_temporary_url_config(): void
    {
        $config = new OciConfig($this->validConfig);
        $urlConfig = $config->getTemporaryUrlConfig();

        expect($urlConfig)->toHaveKey('default_expiry');
        expect($urlConfig)->toHaveKey('max_expiry');
    }

    public function test_can_convert_to_array(): void
    {
        $config = new OciConfig($this->validConfig);
        $array = $config->toArray();

        expect($array)->toBe($this->validConfig);
    }
}
