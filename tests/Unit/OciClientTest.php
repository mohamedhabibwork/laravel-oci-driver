<?php

declare(strict_types=1);

use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\OciClient;

describe('OciClient', function () {
    beforeEach(function () {
        $this->validConfig = [
            'tenancy_id' => 'ocid1.tenancy.oc1..test',
            'user_id' => 'ocid1.user.oc1..test',
            'key_fingerprint' => '00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff',
            'key_path' => '/dev/null',
            'namespace' => 'test-namespace',
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'storage_tier' => 'Standard',
        ];
    });

    it('can be created with array configuration', function () {
        $client = OciClient::createWithConfiguration($this->validConfig);
        expect($client)->toBeInstanceOf(OciClient::class);
        expect($client->getBucket())->toBe('test-bucket');
        expect($client->getRegion())->toBe('us-phoenix-1');
    });

    it('can be created with OciConfig instance', function () {
        $config = new OciConfig($this->validConfig);
        $client = OciClient::createWithConfiguration($config);
        expect($client)->toBeInstanceOf(OciClient::class);
        expect($client->getBucket())->toBe('test-bucket');
    });

    it('can be created using fromOciConfig factory', function () {
        $config = new OciConfig($this->validConfig);
        $client = OciClient::fromOciConfig($config);
        expect($client)->toBeInstanceOf(OciClient::class);
    });

    it('validates required configuration keys', function () {
        $incompleteConfig = ['bucket' => 'test-bucket'];
        expect(fn () => OciClient::createWithConfiguration($incompleteConfig))
            ->toThrow(\InvalidArgumentException::class);
    });

    describe('prefix functionality', function () {
        it('returns empty prefix when not configured', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getPrefix())->toBe('');
            expect($client->isPrefixEnabled())->toBeFalse();
        });

        it('returns normalized prefix when configured', function () {
            $configWithPrefix = array_merge($this->validConfig, [
                'url_path_prefix' => 'my-prefix',
            ]);
            $client = OciClient::createWithConfiguration($configWithPrefix);
            expect($client->getPrefix())->toBe('my-prefix');
            expect($client->isPrefixEnabled())->toBeTrue();
        });

        it('normalizes prefix by removing leading and trailing slashes', function () {
            $testCases = [
                'simple' => 'simple',
                '/leading-slash' => 'leading-slash',
                'trailing-slash/' => 'trailing-slash',
                '/both-slashes/' => 'both-slashes',
                '//multiple-slashes//' => 'multiple-slashes',
                'nested/path' => 'nested/path',
                '/nested/path/' => 'nested/path',
            ];

            foreach ($testCases as $input => $expected) {
                $configWithPrefix = array_merge($this->validConfig, [
                    'url_path_prefix' => $input,
                ]);
                $client = OciClient::createWithConfiguration($configWithPrefix);
                expect($client->getPrefix())->toBe($expected);
            }
        });

        it('applies prefix to object paths correctly', function () {
            $configWithPrefix = array_merge($this->validConfig, [
                'url_path_prefix' => 'my-app/uploads',
            ]);
            $client = OciClient::createWithConfiguration($configWithPrefix);

            expect($client->getPrefixedPath('file.txt'))->toBe('my-app/uploads/file.txt');
            expect($client->getPrefixedPath('folder/file.txt'))->toBe('my-app/uploads/folder/file.txt');
            expect($client->getPrefixedPath('/file.txt'))->toBe('my-app/uploads/file.txt');
            expect($client->getPrefixedPath(''))->toBe('my-app/uploads');
        });

        it('returns original path when no prefix is configured', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);

            expect($client->getPrefixedPath('file.txt'))->toBe('file.txt');
            expect($client->getPrefixedPath('folder/file.txt'))->toBe('folder/file.txt');
            expect($client->getPrefixedPath('/file.txt'))->toBe('file.txt');
            expect($client->getPrefixedPath(''))->toBe('');
        });

        it('removes prefix from paths correctly', function () {
            $configWithPrefix = array_merge($this->validConfig, [
                'url_path_prefix' => 'my-app/uploads',
            ]);
            $client = OciClient::createWithConfiguration($configWithPrefix);

            expect($client->removePrefixFromPath('my-app/uploads/file.txt'))->toBe('file.txt');
            expect($client->removePrefixFromPath('my-app/uploads/folder/file.txt'))->toBe('folder/file.txt');
            expect($client->removePrefixFromPath('other-prefix/file.txt'))->toBe('other-prefix/file.txt');
            expect($client->removePrefixFromPath('file.txt'))->toBe('file.txt');
        });

        it('handles edge cases for prefix removal', function () {
            $configWithPrefix = array_merge($this->validConfig, [
                'url_path_prefix' => 'prefix',
            ]);
            $client = OciClient::createWithConfiguration($configWithPrefix);

            // Path that starts with prefix but isn't actually prefixed
            expect($client->removePrefixFromPath('prefix-similar/file.txt'))->toBe('prefix-similar/file.txt');

            // Exact prefix match
            expect($client->removePrefixFromPath('prefix/'))->toBe('');

            // Empty path
            expect($client->removePrefixFromPath(''))->toBe('');
        });
    });

    describe('storage operations', function () {
        it('gets correct storage tier', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getStorageTier())->toBe(StorageTier::STANDARD);
        });

        it('gets correct bucket URI', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            $expected = 'https://objectstorage.us-phoenix-1.oraclecloud.com/n/test-namespace/b/test-bucket';
            expect($client->getBucketUri())->toBe($expected);
        });

        it('gets correct host for region', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getHost())->toBe('https://objectstorage.us-phoenix-1.oraclecloud.com');
        });
    });

    describe('authentication', function () {
        it('gets correct tenancy ID', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getTenancy())->toBe('ocid1.tenancy.oc1..test');
        });

        it('gets correct user ID', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getUser())->toBe('ocid1.user.oc1..test');
        });

        it('gets correct key fingerprint', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getFingerprint())->toBe('00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff');
        });

        it('gets correct private key path', function () {
            $client = OciClient::createWithConfiguration($this->validConfig);
            expect($client->getPrivateKey())->toBe('/dev/null');
        });
    });
});
