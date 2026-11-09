<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Feature;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\LaravelOciDriver;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class OciCommandsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function diskConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'oci',
            'namespace' => 'ns',
            'region' => 'us-phoenix-1',
            'bucket' => 'bkt',
            'tenancy_id' => 'ocid1.tenancy.oc1..t',
            'user_id' => 'ocid1.user.oc1..u',
            'key_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'key_path' => __DIR__.'/../fixtures/test-key.pem',
        ], $overrides);
    }

    public function test_connection_resolves_plain_oci_disk_name(): void
    {
        Config::set('filesystems.disks.media', $this->diskConfig());

        expect(LaravelOciDriver::connection('media'))->toBeInstanceOf(Filesystem::class);
    }

    public function test_connection_falls_back_to_legacy_prefixed_disk(): void
    {
        Config::set('filesystems.disks.oci_legacy', $this->diskConfig());

        expect(LaravelOciDriver::connection('legacy'))->toBeInstanceOf(Filesystem::class);
    }

    public function test_connection_throws_for_unknown_disk(): void
    {
        expect(fn () => LaravelOciDriver::connection('nope'))
            ->toThrow(\InvalidArgumentException::class, "OCI disk 'nope' is not configured");
    }

    public function test_config_validate_uses_given_disk(): void
    {
        Config::set('filesystems.disks.bad', $this->diskConfig(['bucket' => '']));

        $this->artisan('oci:config', ['--validate' => true, '--disk' => 'bad'])
            ->expectsOutputToContain('bad')
            ->assertExitCode(1);
    }

    public function test_config_validate_reports_missing_disk(): void
    {
        $this->artisan('oci:config', ['--validate' => true, '--disk' => 'ghost'])
            ->expectsOutputToContain('ghost')
            ->assertExitCode(1);
    }

    public function test_config_validate_accepts_default_oci_disk(): void
    {
        $this->artisan('oci:config', ['--validate' => true])
            ->expectsConfirmation('Test connection to OCI?', 'no')
            ->assertExitCode(0);
    }

    public function test_connection_test_reports_missing_disk_clearly(): void
    {
        $this->artisan('oci:connection', ['action' => 'test', 'connection' => 'nope'])
            ->expectsOutputToContain("OCI disk 'nope' is not configured")
            ->assertExitCode(1);
    }

    public function test_validate_accepts_private_key_base64_only(): void
    {
        $config = OciConfig::fromDiskConfig($this->diskConfig([
            'key_path' => null,
            'private_key_base64' => base64_encode('pem'),
        ]), 'x');

        expect($config->validate())->toBe([]);
    }
}
