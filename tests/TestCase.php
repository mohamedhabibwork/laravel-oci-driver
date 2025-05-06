<?php

namespace LaravelOCI\LaravelOciDriver\Tests;

use LaravelOCI\LaravelOciDriver\LaravelOciDriverServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelOciDriverServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('filesystems.disks.oci', [
            'driver' => 'oci',
            'namespace' => 'test-namespace',
            'region' => 'test-region',
            'bucket' => 'test-bucket',
            'tenancy_id' => 'test-tenancy-id',
            'user_id' => 'test-user-id',
            'storage_tier' => 'Standard',
            'key_fingerprint' => 'test-key-fingerprint',
            'key_path' => __DIR__.'/test-key.pem',
        ]);
    }
}
