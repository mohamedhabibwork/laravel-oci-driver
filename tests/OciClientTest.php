<?php

use Carbon\Carbon;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use LaravelOCI\LaravelOciDriver\OciClient;

/**
 * Create a test OCI client
 */
function createTestOciClient(array $overrides = []): OciClient
{
    // Use environment variables for real testing or default test values
    $config = array_merge(config('filesystems.disks.oci', []), $overrides);

    // For CI testing or local testing without credentials
    // Create a temporary key file for testing if it doesn't exist
    if (! file_exists($config['key_path'])) {
        // Create a proper private key for testing
        $privateKey = <<<'EOD'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQC7VJTUt9Us8cKj
MzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu
NMoSfm76oqFvAp8Gy0iz5sxjZmSnXyCdPEovGhLa0VzMaQ8s+CLOyS56YyCFGeJZ
3RR+JZLj9jm9Q8tCnRRJFVdOiQXesZ+V3TPdOxUnthcs8QiX06l+yKudhK2w0rON
6jR+nxN4G1CbSJVaQEQ+LyY4z0cNaWJuqXQVj0O0Kokyc9EByOTYvEwLjIpC6Xwt
ALGpxjAJ3pz5yPzuNoSWJJQx42/sFZ1z3IcnZuCfnsiQNJ+BJyjIyEOjRERYq8/2
BLnvxEjPAgMBAAECggEBAKM+FKYcnnxMQQfmw0ixTzDOKVFSfBh6zWygHH6BWyUg
3k7XtgE7o3QdUQU2vg3KsiOQQKZlkPiUAPKLJZiwZShSsqR+ZGZGYXkbUjEnBmVz
hM9spS0QSJ61qVfR6wHw4Y/LGk2LJgGnuyQG1Umw8Hu8brnDrW4HVdZQpMk3nt6N
tFT2iXGYL8p19pnWuZU+R+1XJkL5frHdOiOZ1RGM9SF8wUKLYw/EENLOy0bHgOQf
DBuEin0H9FrCxIv/S/JK3q5NBJ6/YwQJcOJu3fQ0a1lGnJ3MjMCyOIUmEfcPGKzE
lSm6/zOGl8ydpwQQmmm+J2P/SPk2WNM0pKnhFnG++FkCgYEA6FCnR9AHa5ktQwG9
qUZPZP9qIU/U16MC2Pl7KCJlhzYYxYK+U0GmOtE9u28m/Ga7MJXjYDQOhz1nZ00m
OfXjJEFoyggBXxQnOOfd+NGlRw8nG5Jor2M2/ZVgxj5j5Pd28YDw1xqxKQNsmXKk
MHzVPiQkAu5DOYZLQDOnSYTEV1sCgYEAzuIxwdFmWqABLGxHO5R6LJwj9ZXHMKpP
oZR2+ZcsJxMLeL1KXaQfnrJBqCfaWnhDJOQ2TPyVLUTKHCdtqxx5DgLHXzkzyKM5
yKsmmUfgJJmimaBHR1SCgxNQJhNAOkrDMSECZnXJLbsT8qPb40K+u2Gaj2hVLC93
A166NlrUYv0CgYEAgDTzJZSMZiPUu1lCkK5+SCF2T4U6CC/UnSWjU9fvJLRmOT+t
FsmDELVQt5/hn1ru8s99GXRjYHFvgafgGnTpKRLvL9kJZcwKSdkkRHHyUaQ1PR64
ZGApC4Vht6r4jm1DuqIifCzHcElTwkAjKa6HrpzJ6JktJYoYcpm8UOPKQqcCgYEA
gIttK7CM+hQJKNN39RGa9y7GviQlEi71tQxm/0LJkzC35jUkMNkfsapUOZfQJXeL
mG/K1bGwXcHNTKY1tTvVZJnchJp+WtU6hbLaWnwWqCiPrdl9yDky9tQMT7tKA3XP
xVXYWk0EGjJXcdf9v4x8nqjQBjFfkeyrrrUEF81FX40CgYBMTCQLD1gXeGKYw0yN
N0DXVQQPfBWbxPS3cXFBYXFfgKUCUvs0ZXyvQZgI2AKbCvfrMcKzXtYHLPBsv6RF
4XWckU+UJuFBmLZJNRQDlN/BQ5w6kCMJGkmlF2k/ozWTLN5+Jgi+S8h7hASDwmaT
BAL2K+I2IR+t0YZ5qCVW3o33OA==
-----END PRIVATE KEY-----
EOD;

        // Create a temporary private key file
        $tempKeyPath = sys_get_temp_dir().'/oci_test_key.pem';
        file_put_contents($tempKeyPath, $privateKey);
        $config['key_path'] = $tempKeyPath;
    }

    return OciClient::createWithConfiguration($config);
}

/**
 * Clean up test artifacts
 */
afterEach(function () {
    if (file_exists(__DIR__.'/test-key.pem') && ! ($_ENV['OCI_RUN_REAL_TESTS'] ?? false)) {
        unlink(__DIR__.'/test-key.pem');
    }
});

it('creates a client with valid configuration', function () {
    $client = createTestOciClient();
    expect($client)->toBeInstanceOf(OciClient::class);
});

it('throws exception with invalid configuration', function () {
    // Missing required keys
    $invalidConfig = [
        'namespace' => config('filesystems.disks.oci.namespace'),
        'region' => config('filesystems.disks.oci.region'),
    ];

    OciClient::createWithConfiguration($invalidConfig);
})->throws(\InvalidArgumentException::class);

it('builds correct bucket URI', function () {
    $client = createTestOciClient([
        'namespace' => config('filesystems.disks.oci.namespace'),
        'region' => config('filesystems.disks.oci.region'),
        'bucket' => config('filesystems.disks.oci.bucket'),
    ]);

    $uri = $client->getBucketUri();

    // Expected format: https://objectstorage.{region}.oraclecloud.com/n/{namespace}/b/{bucket}
    expect($uri)->toBe('https://objectstorage.test-region.oraclecloud.com/n/test-namespace/b/test-bucket');
});

it('gets storage tier as enum', function () {
    // Test standard tier
    $client = createTestOciClient(['storage_tier' => 'Standard']);
    expect($client->getStorageTier())->toBe(StorageTier::STANDARD);

    // Test archive tier
    $client = createTestOciClient(['storage_tier' => 'Archive']);
    expect($client->getStorageTier())->toBe(StorageTier::ARCHIVE);

    // Test infrequent access tier
    $client = createTestOciClient(['storage_tier' => 'InfrequentAccess']);
    expect($client->getStorageTier())->toBe(StorageTier::INFREQUENT_ACCESS);

    // Test default when invalid
    $client = createTestOciClient(['storage_tier' => 'InvalidTier']);
    expect($client->getStorageTier())->toBe(StorageTier::STANDARD);
});

it('creates temporary URLs for objects', function () {

    $client = createTestOciClient();
    $testFile = 'temp-url-test-'.time().'.txt';
    $adapter = new \LaravelOCI\LaravelOciDriver\OciAdapter($client);

    // Create a file to generate a URL for
    $adapter->write($testFile, 'test content', new \League\Flysystem\Config);

    try {
        // Generate temporary URL
        $expiresAt = Carbon::now()->addMinutes(5);
        $url = $client->createTemporaryUrl($testFile, $expiresAt);

        // Basic validation of the URL format
        expect($url)->toBeString();
        expect($url)->toContain('http');
        expect($url)->toContain($client->getBucket());

        // Make a HTTP request to the URL to confirm it works
        $response = (new \GuzzleHttp\Client)->get($url);
        expect($response->getStatusCode())->toBe(200);
        expect($response->getBody()->getContents())->toBe('test content');
    } finally {
        // Clean up
        $adapter->delete($testFile);
    }
});

it('handles authorization headers', function () {
    $client = createTestOciClient();

    $uri = 'https://objectstorage.'.config('filesystems.disks.oci.region').'.oraclecloud.com/n/'.config('filesystems.disks.oci.namespace').'/b/'.config('filesystems.disks.oci.bucket').'/o/test-file';
    $method = 'GET';

    $headers = $client->getAuthorizationHeaders($uri, $method);

    // Headers should contain required OCI authentication headers
    expect($headers)->toBeArray();
    expect(array_keys($headers))->toContain('Date');
    expect(array_keys($headers))->toContain('Authorization');
});
