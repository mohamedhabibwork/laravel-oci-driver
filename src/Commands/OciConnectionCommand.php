<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Commands;

use Illuminate\Console\Command;
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\LaravelOciDriver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Command to manage OCI connections and switch between them.
 */
#[AsCommand(
    name: 'oci:connection',
    description: 'Manage Oracle Cloud Infrastructure connections'
)]
final class OciConnectionCommand extends Command
{
    public $signature = 'oci:connection {action : Action to perform (list|test|switch|summary)} {connection? : Connection name}';

    public $description = 'Manage Oracle Cloud Infrastructure connections';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listConnections(),
            'test' => $this->testConnections(),
            'switch' => $this->switchConnection(),
            'summary' => $this->showConnectionSummary(),
            default => $this->showHelp(),
        };
    }

    /**
     * List all available OCI connections.
     */
    private function listConnections(): int
    {
        $this->info('📋 Available OCI Connections:');
        $this->newLine();

        $connections = OciConfig::getAvailableConnections();

        if (empty($connections)) {
            $this->warn('No OCI connections found.');
            $this->line('Run "php artisan oci:config" to configure your first connection.');

            return self::SUCCESS;
        }

        $default = OciConfig::getDefaultConnection();
        $tableData = [];

        foreach ($connections as $connection) {
            try {
                $config = OciConfig::fromConnection($connection);
                $summary = $config->getConnectionSummary();

                $status = $connection === $default ? '✅ Default' : '⚪ Available';

                $tableData[] = [
                    $connection,
                    $status,
                    $summary['connection_type'],
                    $summary['region'],
                    $summary['bucket'],
                ];
            } catch (\Exception $e) {
                $tableData[] = [
                    $connection,
                    '❌ Invalid',
                    'Error',
                    $e->getMessage(),
                    '-',
                ];
            }
        }

        $this->table(
            ['Connection', 'Status', 'Type', 'Region', 'Bucket'],
            $tableData
        );

        return self::SUCCESS;
    }

    /**
     * Test connections.
     */
    private function testConnections(): int
    {
        $connection = $this->argument('connection');

        if ($connection && is_string($connection)) {
            return $this->testSingleConnection($connection);
        }

        return $this->testAllConnections();
    }

    /**
     * Test a single connection.
     */
    private function testSingleConnection(string $connection): int
    {
        $this->info("🔗 Testing connection: {$connection}");

        try {
            $config = OciConfig::fromConnection($connection);
            $errors = $config->validate();

            if (! empty($errors)) {
                $this->error('Configuration validation failed:');
                foreach ($errors as $error) {
                    $this->line("  ❌ {$error}");
                }

                return self::FAILURE;
            }

            $this->line('✅ Configuration is valid');

            // Test actual connection
            $result = LaravelOciDriver::testConnection("oci_{$connection}");

            if ($result) {
                $this->info('🎉 Connection test successful!');

                return self::SUCCESS;
            } else {
                $this->error('❌ Connection test failed');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Connection test failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Test all connections.
     */
    private function testAllConnections(): int
    {
        $this->info('🔗 Testing all OCI connections...');
        $this->newLine();

        $results = LaravelOciDriver::testMultipleConnections();
        $tableData = [];
        $allPassed = true;

        foreach ($results as $connection => $passed) {
            $status = $passed ? '✅ Pass' : '❌ Fail';
            $tableData[] = [$connection, $status];

            if (! $passed) {
                $allPassed = false;
            }
        }

        $this->table(['Connection', 'Status'], $tableData);

        if ($allPassed) {
            $this->info('🎉 All connections are working!');

            return self::SUCCESS;
        } else {
            $this->warn('Some connections failed. Run individual tests for more details.');

            return self::FAILURE;
        }
    }

    /**
     * Switch to a different connection.
     */
    private function switchConnection(): int
    {
        $connection = $this->argument('connection');

        if (! $connection || ! is_string($connection)) {
            $connections = OciConfig::getAvailableConnections();

            if (empty($connections)) {
                $this->error('No connections available.');

                return self::FAILURE;
            }

            $connection = $this->choice('Select connection to switch to:', $connections);
        }

        // Ensure connection is a string
        if (! is_string($connection)) {
            $this->error('Invalid connection selection.');

            return self::FAILURE;
        }

        if (! OciConfig::connectionExists($connection)) {
            $this->error("Connection '{$connection}' does not exist.");

            return self::FAILURE;
        }

        try {
            // Update default connection in config
            $configPath = config_path('laravel-oci-driver.php');

            if (! file_exists($configPath)) {
                $this->error('Configuration file not found. Run "php artisan vendor:publish --tag=laravel-oci-driver-config" first.');

                return self::FAILURE;
            }

            // For now, just show how to switch manually
            $this->info("To switch to connection '{$connection}', update your configuration:");
            $this->newLine();
            $this->line('Option 1: Update config/laravel-oci-driver.php:');
            $this->line("'default' => '{$connection}',");
            $this->newLine();
            $this->line('Option 2: Use programmatically:');
            $this->line("LaravelOciDriver::connection('{$connection}')");
            $this->newLine();

            // Test the connection
            $config = OciConfig::fromConnection($connection);
            $summary = $config->getConnectionSummary();

            $this->info('Connection details:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Type', $summary['connection_type']],
                    ['Region', $summary['region']],
                    ['Bucket', $summary['bucket']],
                    ['Storage Tier', $summary['storage_tier']],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to switch connection: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Show connection summary.
     */
    private function showConnectionSummary(): int
    {
        $connectionArg = $this->argument('connection');
        $connection = (is_string($connectionArg) ? $connectionArg : null) ?? OciConfig::getDefaultConnection();

        $this->info("📊 Connection Summary: {$connection}");
        $this->newLine();

        try {
            $summary = LaravelOciDriver::getConnectionSummary($connection);

            if (isset($summary['error'])) {
                $this->error("Error: {$summary['error']}");

                return self::FAILURE;
            }

            $tableData = [];
            foreach ($summary as $key => $value) {
                $displayKey = ucwords(str_replace('_', ' ', $key));
                $displayValue = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
                $tableData[] = [$displayKey, $displayValue];
            }

            $this->table(['Setting', 'Value'], $tableData);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to get connection summary: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Show command help.
     */
    private function showHelp(): int
    {
        $this->info('🛠️ OCI Connection Management');
        $this->newLine();
        $this->line('Available actions:');
        $this->line('  list     - List all available connections');
        $this->line('  test     - Test connections (all or specific)');
        $this->line('  switch   - Switch to a different connection');
        $this->line('  summary  - Show connection summary');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan oci:connection list');
        $this->line('  php artisan oci:connection test');
        $this->line('  php artisan oci:connection test production');
        $this->line('  php artisan oci:connection switch backup');
        $this->line('  php artisan oci:connection summary development');

        return self::SUCCESS;
    }
}
