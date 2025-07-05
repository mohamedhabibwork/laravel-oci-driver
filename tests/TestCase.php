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
            'region' => 'us-phoenix-1',
            'bucket' => 'test-bucket',
            'tenancy_id' => 'ocid1.tenancy.oc1..test',
            'user_id' => 'ocid1.user.oc1..test',
            'storage_tier' => 'Standard',
            'key_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'key_path' => __DIR__.'/test-key.pem',
        ]);
    }
}
