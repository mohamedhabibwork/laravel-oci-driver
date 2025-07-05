<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Command to help configure OCI settings interactively.
 */
#[AsCommand(
    name: 'oci:config',
    description: 'Configure Oracle Cloud Infrastructure storage settings'
)]
final class OciConfigCommand extends Command
{
    public $signature = 'oci:config {--validate : Validate existing configuration}';

    public $description = 'Configure Oracle Cloud Infrastructure storage settings';

    public function handle(): int
    {
        if ($this->option('validate')) {
            return $this->validateConfiguration();
        }

        return $this->configureOci();
    }

    /**
     * Interactive OCI configuration setup.
     */
    private function configureOci(): int
    {
        $this->info('🔧 Oracle Cloud Infrastructure Configuration Setup');
        $this->line('This command will help you configure your OCI Object Storage settings.');
        $this->newLine();

        if (! $this->confirm('Do you want to configure OCI settings?', true)) {
            $this->info('Configuration cancelled.');

            return self::SUCCESS;
        }

        $config = $this->gatherConfiguration();
        $this->displayConfiguration($config);

        if ($this->confirm('Save this configuration to your .env file?', true)) {
            $this->saveToEnvFile($config);
            $this->info('✅ Configuration saved successfully!');
            $this->line('You can now use the OCI storage driver in your Laravel application.');
        }

        return self::SUCCESS;
    }

    /**
     * Gather configuration from user input.
     *
     * @return array<string, string>
     */
    private function gatherConfiguration(): array
    {
        $config = [];

        $this->info('📋 Basic OCI Configuration');
        $config['OCI_TENANCY_ID'] = (string) ($this->ask('Tenancy OCID', (string) config('laravel-oci-driver.connections.default.tenancy_id', '')) ?? '');
        $config['OCI_USER_ID'] = (string) ($this->ask('User OCID', (string) config('laravel-oci-driver.connections.default.user_id', '')) ?? '');
        $config['OCI_KEY_FINGERPRINT'] = (string) ($this->ask('API Key Fingerprint', (string) config('laravel-oci-driver.connections.default.key_fingerprint', '')) ?? '');
        $config['OCI_KEY_PATH'] = $this->ask('Private Key File Path', (string) config('laravel-oci-driver.connections.default.key_path', storage_path('app/oci/private_key.pem'))) ?? '';

        $this->newLine();
        $this->info('🗂️ Object Storage Configuration');
        $config['OCI_NAMESPACE'] = $this->ask('Object Storage Namespace', (string) config('laravel-oci-driver.connections.default.namespace', '')) ?? '';

        // Common OCI regions
        $popularRegions = [
            'me-riyadh-1' => 'Saudi Arabia West (Riyadh)',
            'me-jeddah-1' => 'Saudi Arabia West (Jeddah)',
            'us-phoenix-1' => 'US West (Phoenix)',
            'us-ashburn-1' => 'US East (Ashburn)',
            'eu-frankfurt-1' => 'Germany Central (Frankfurt)',
            'ap-tokyo-1' => 'Japan East (Tokyo)',
            'ap-singapore-1' => 'Singapore',
            'uk-london-1' => 'UK South (London)',
            'ap-sydney-1' => 'Australia East (Sydney)',
            'me-dubai-1' => 'UAE Central (Dubai)',
        ];

        $this->line('Popular regions:');
        foreach ($popularRegions as $region => $name) {
            $this->line("  - {$region} ({$name})");
        }

        $selectedRegion = $this->choice(
            'Select Region',
            array_keys($popularRegions),
            (string) config('laravel-oci-driver.connections.default.region', 'us-phoenix-1')
        );
        $config['OCI_REGION'] = is_array($selectedRegion) ? (string) $selectedRegion[0] : (string) $selectedRegion;

        $config['OCI_BUCKET'] = $this->ask('Bucket Name', (string) config('laravel-oci-driver.connections.default.bucket', '')) ?? '';

        $storageTier = $this->choice(
            'Default Storage Tier',
            [StorageTier::STANDARD->value, StorageTier::INFREQUENT_ACCESS->value, StorageTier::ARCHIVE->value],
            (string) config('laravel-oci-driver.connections.default.storage_tier', StorageTier::STANDARD->value)
        );

        $config['OCI_STORAGE_TIER'] = is_array($storageTier) ? (string) $storageTier[0] : (string) $storageTier;

        $this->newLine();
        $this->info('⚙️ Optional Configuration');

        if ($this->confirm('Configure advanced settings?', false)) {
            $config['OCI_TIMEOUT'] = (string) $this->ask('Request Timeout (seconds)', (string) config('laravel-oci-driver.connections.default.timeout', '30'));
            $config['OCI_RETRY_ATTEMPTS'] = (string) $this->ask('Retry Attempts', (string) config('laravel-oci-driver.connections.default.retry_attempts', '3'));
            $ociCacheEnabled = $this->choice('Enable Caching', ['true', 'false'], (string) config('laravel-oci-driver.connections.default.cache.enabled', 'true'));
            $config['OCI_CACHE_ENABLED'] = is_array($ociCacheEnabled) ? (string) $ociCacheEnabled[0] : (string) $ociCacheEnabled;
            $ociLoggingEnabled = $this->choice('Enable Logging', ['true', 'false'], (string) config('laravel-oci-driver.connections.default.logging.enabled', 'false'));
            $config['OCI_LOGGING_ENABLED'] = is_array($ociLoggingEnabled) ? (string) $ociLoggingEnabled[0] : (string) $ociLoggingEnabled;
        }

        return $config;
    }

    /**
     * Display the gathered configuration.
     *
     * @param  array<string, string>  $config
     */
    private function displayConfiguration(array $config): void
    {
        $this->newLine();
        $this->info('📄 Configuration Summary:');

        $tableData = [];
        foreach ($config as $key => $value) {
            // Mask sensitive values
            $displayValue = $this->maskSensitiveValue($key, $value);
            $tableData[] = [$key, $displayValue];
        }

        $this->table(['Setting', 'Value'], $tableData);
    }

    /**
     * Mask sensitive configuration values for display.
     */
    private function maskSensitiveValue(string $key, string $value): string
    {
        $sensitiveKeys = ['OCI_TENANCY_ID', 'OCI_USER_ID', 'OCI_KEY_FINGERPRINT', 'OCI_KEY_PATH'];

        if (in_array($key, $sensitiveKeys, true) && ! empty($value)) {
            return Str::mask($value, '*', 4, -4);
        }

        return $value;
    }

    /**
     * Save configuration to .env file.
     *
     * @param  array<string, string>  $config
     */
    private function saveToEnvFile(array $config): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('.env file not found. Please create one first.');

            return;
        }

        $envContent = file_get_contents($envPath);

        foreach ($config as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $replacement = "{$key}={$value}";

            if ($envContent !== false && preg_match($pattern, $envContent)) {
                $envContent = (string) preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        file_put_contents($envPath, $envContent);
    }

    /**
     * Validate existing OCI configuration.
     */
    private function validateConfiguration(): int
    {
        $this->info('🔍 Validating OCI Configuration');

        try {
            // Try to create OciConfig from disk configuration
            $ociConfig = OciConfig::fromDisk('oci');
            $errors = $ociConfig->validate();

            if (empty($errors)) {
                $this->newLine();
                $this->info('🎉 All OCI configuration settings are valid!');

                // Display configuration summary
                $this->displayValidConfiguration($ociConfig);

                // Test connection if possible
                if ($this->confirm('Test connection to OCI?', true)) {
                    return $this->testConnection();
                }

                return self::SUCCESS;
            } else {
                $this->newLine();
                $this->error('Configuration Errors:');
                foreach ($errors as $error) {
                    $this->line("❌ {$error}");
                }

                $this->newLine();
                $this->info('💡 Run "php artisan oci:config" to fix these issues interactively.');

                return self::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error("Configuration Error: {$e->getMessage()}");
            $this->newLine();
            $this->info('💡 Run "php artisan oci:setup" to create the initial configuration.');

            return self::FAILURE;
        }
    }

    /**
     * Display valid configuration summary.
     */
    private function displayValidConfiguration(OciConfig $config): void
    {
        $this->table(['Setting', 'Value'], [
            ['Region', $config->getRegion()],
            ['Namespace', $config->getNamespace()],
            ['Bucket', $config->getBucket()],
            ['Storage Tier', $config->getStorageTier()->value],
            ['Tenancy ID', Str::mask($config->getTenancyId(), '*', 4, -4)],
            ['User ID', Str::mask($config->getUserId(), '*', 4, -4)],
            ['Key Path', $config->getKeyPath()],
            ['Cache Enabled', $config->isCacheEnabled() ? 'Yes' : 'No'],
            ['Logging Enabled', $config->isLoggingEnabled() ? 'Yes' : 'No'],
        ]);
    }

    /**
     * Test the OCI connection.
     */
    private function testConnection(): int
    {
        $this->info('🔗 Testing OCI connection...');

        try {
            $this->call('oci:status');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Connection test failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
