<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use LaravelOCI\LaravelOciDriver\Commands\OciSetupCommand;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

/**
 * Test cases for OciSetupCommand.
 *
 * @covers \LaravelOCI\LaravelOciDriver\Commands\OciSetupCommand
 */
final class OciSetupCommandTest extends TestCase
{
    private string $testOciPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for testing
        $this->testOciPath = sys_get_temp_dir().'/test_oci_'.time();
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (File::exists($this->testOciPath)) {
            File::deleteDirectory($this->testOciPath);
        }

        parent::tearDown();
    }

    public function test_setup_command_creates_oci_directory(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections.default', [
            'tenancy_id' => 'ocid1.tenancy.oc1..test_tenancy',
            'user_id' => 'ocid1.user.oc1..test_user',
            'key_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'region' => 'us-phoenix-1',
            'namespace' => 'test_namespace',
            'bucket' => 'test_bucket',
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $this->assertTrue(File::exists($this->testOciPath));
        $this->assertTrue(File::exists($this->testOciPath.'/config'));
        $this->assertTrue(File::exists($this->testOciPath.'/default.pem'));
    }

    public function test_setup_command_handles_multiple_connections(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections', [
            'production' => [
                'tenancy_id' => 'ocid1.tenancy.oc1..prod_tenancy',
                'user_id' => 'ocid1.user.oc1..prod_user',
                'key_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
                'region' => 'us-phoenix-1',
            ],
            'staging' => [
                'tenancy_id' => 'ocid1.tenancy.oc1..staging_tenancy',
                'user_id' => 'ocid1.user.oc1..staging_user',
                'key_fingerprint' => 'bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99:aa',
                'region' => 'us-ashburn-1',
            ],
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $this->assertTrue(File::exists($this->testOciPath.'/production.pem'));
        $this->assertTrue(File::exists($this->testOciPath.'/staging.pem'));

        $configContent = File::get($this->testOciPath.'/config');
        $this->assertStringContainsString('[PRODUCTION]', $configContent);
        $this->assertStringContainsString('[STAGING]', $configContent);
    }

    public function test_setup_command_handles_specific_connection(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections', [
            'production' => [
                'tenancy_id' => 'ocid1.tenancy.oc1..prod_tenancy',
                'user_id' => 'ocid1.user.oc1..prod_user',
                'region' => 'us-phoenix-1',
            ],
            'staging' => [
                'tenancy_id' => 'ocid1.tenancy.oc1..staging_tenancy',
                'user_id' => 'ocid1.user.oc1..staging_user',
                'region' => 'us-ashburn-1',
            ],
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--connection' => ['production'],
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $this->assertTrue(File::exists($this->testOciPath.'/production.pem'));
        $this->assertFalse(File::exists($this->testOciPath.'/staging.pem'));

        $configContent = File::get($this->testOciPath.'/config');
        $this->assertStringContainsString('[DEFAULT]', $configContent); // Single connection uses DEFAULT profile
        $this->assertStringNotContainsString('[STAGING]', $configContent);
    }

    public function test_setup_command_fails_without_configurations(): void
    {
        // Arrange - no configurations set
        Config::set('laravel-oci-driver.connections', []);
        Config::set('filesystems.disks', []);

        // Act & Assert
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
        ])->assertExitCode(1);
    }

    public function test_setup_command_respects_force_option(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections.default', [
            'tenancy_id' => 'ocid1.tenancy.oc1..test_tenancy',
            'user_id' => 'ocid1.user.oc1..test_user',
            'region' => 'us-phoenix-1',
        ]);

        // Create initial files
        File::makeDirectory($this->testOciPath, 0700, true);
        File::put($this->testOciPath.'/config', 'old config content');
        File::put($this->testOciPath.'/default.pem', 'old key content');

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--force' => true,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $configContent = File::get($this->testOciPath.'/config');
        $this->assertStringNotContainsString('old config content', $configContent);
        $this->assertStringContainsString('[DEFAULT]', $configContent);

        $keyContent = File::get($this->testOciPath.'/default.pem');
        $this->assertStringNotContainsString('old key content', $keyContent);
    }

    public function test_setup_command_copies_existing_key_file(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        $existingKeyPath = sys_get_temp_dir().'/existing_key.pem';
        $existingKeyContent = <<<'EOL'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC...
-----END PRIVATE KEY-----
OCI_API_KEY
EOL;
        File::put($existingKeyPath, $existingKeyContent);

        Config::set('laravel-oci-driver.connections.default', [
            'tenancy_id' => 'ocid1.tenancy.oc1..test_tenancy',
            'user_id' => 'ocid1.user.oc1..test_user',
            'key_path' => $existingKeyPath,
            'region' => 'us-phoenix-1',
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $this->assertTrue(File::exists($this->testOciPath.'/default.pem'));
        $copiedContent = File::get($this->testOciPath.'/default.pem');
        $this->assertStringContainsString('MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC', $copiedContent);

        // Clean up
        File::delete($existingKeyPath);
    }

    public function test_setup_command_creates_template_when_no_existing_key(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections.default', [
            'tenancy_id' => 'ocid1.tenancy.oc1..test_tenancy',
            'user_id' => 'ocid1.user.oc1..test_user',
            'region' => 'us-phoenix-1',
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $keyContent = File::get($this->testOciPath.'/default.pem');
        $this->assertStringContainsString('YOUR_PRIVATE_KEY_CONTENT_GOES_HERE', $keyContent);
        $this->assertStringContainsString('OCI_API_KEY', $keyContent);
    }

    public function test_config_file_format_is_correct(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections.default', [
            'tenancy_id' => 'ocid1.tenancy.oc1..test_tenancy_id',
            'user_id' => 'ocid1.user.oc1..test_user_id',
            'key_fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'region' => 'us-phoenix-1',
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $configContent = File::get($this->testOciPath.'/config');

        $expectedLines = [
            '[DEFAULT]',
            'user=ocid1.user.oc1..test_user_id',
            'fingerprint=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'tenancy=ocid1.tenancy.oc1..test_tenancy_id',
            'region=us-phoenix-1',
            'key_file='.$this->testOciPath.'/default.pem',
        ];

        foreach ($expectedLines as $line) {
            $this->assertStringContainsString($line, $configContent);
        }
    }

    public function test_setup_command_uses_custom_profile_name(): void
    {
        // Arrange - Clear any default filesystem config that might interfere
        Config::set('filesystems.disks', []);
        Config::set('laravel-oci-driver.connections.default', [
            'tenancy_id' => 'ocid1.tenancy.oc1..test_tenancy',
            'user_id' => 'ocid1.user.oc1..test_user',
            'region' => 'us-phoenix-1',
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--profile' => 'CUSTOM_PROFILE',
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $configContent = File::get($this->testOciPath.'/config');
        $this->assertStringContainsString('[CUSTOM_PROFILE]', $configContent);
        $this->assertStringNotContainsString('[DEFAULT]', $configContent);
    }

    public function test_setup_command_from_filesystem_disks(): void
    {
        // Arrange
        Config::set('filesystems.disks.oci_storage', [
            'driver' => 'oci',
            'tenancy_id' => 'ocid1.tenancy.oc1..filesystem_tenancy',
            'user_id' => 'ocid1.user.oc1..filesystem_user',
            'region' => 'us-ashburn-1',
        ]);

        // Act
        $this->artisan('oci:setup', [
            '--path' => $this->testOciPath,
            '--auto-config' => true,
        ])->assertExitCode(0);

        // Assert
        $this->assertTrue(File::exists($this->testOciPath.'/oci_storage.pem'));

        $configContent = File::get($this->testOciPath.'/config');
        $this->assertStringContainsString('user=ocid1.user.oc1..filesystem_user', $configContent);
        $this->assertStringContainsString('tenancy=ocid1.tenancy.oc1..filesystem_tenancy', $configContent);
    }
}
