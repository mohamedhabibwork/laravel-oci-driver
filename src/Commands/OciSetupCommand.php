<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Command to set up Oracle Cloud Infrastructure folder structure and configuration files.
 *
 * This command creates Oracle-compatible configuration files following official OCI documentation:
 * https://docs.oracle.com/en-us/iaas/Content/API/Concepts/sdkconfig.htm
 * https://docs.oracle.com/en-us/iaas/Content/API/Concepts/apisigningkey.htm
 */
#[AsCommand(
    name: 'oci:setup',
    description: 'Create Oracle-compatible .oci folder with configuration and key files'
)]
final class OciSetupCommand extends Command
{
    public $signature = 'oci:setup 
                        {--path= : Custom path for .oci directory (defaults to ~/.oci)}
                        {--connection=* : Specific connection names to setup (defaults to all)}
                        {--force : Overwrite existing files}
                        {--generate-keys : Generate new API signing keys}
                        {--auto-config : Auto-fill from Laravel config without prompts}
                        {--profile= : Profile name for config (defaults to DEFAULT)}';

    public $description = 'Create Oracle-compatible .oci folder with configuration and key files';

    private string $ociPath;

    public function handle(): int
    {
        $this->info('🚀 Setting up Oracle Cloud Infrastructure folder structure...');

        // Check if OpenSSL is available for key generation
        if ($this->option('generate-keys') && ! $this->checkOpenSSLAvailability()) {
            $this->error('OpenSSL is not available. Cannot generate keys.');
            $this->line('Please install OpenSSL or use existing keys.');

            return self::FAILURE;
        }

        // Determine .oci directory path
        $this->ociPath = $this->getOciPath();

        // Ensure .oci directory exists
        $this->createOciDirectory();

        // Get OCI configurations from Laravel config
        $configs = $this->getOciConfigurations();

        if (empty($configs)) {
            $this->warn('No OCI configurations found in Laravel config.');
            $this->line('Please configure OCI connections first using: php artisan oci:config');

            return self::FAILURE;
        }

        $this->info('Found '.count($configs).' OCI configuration(s)');

        // Process each configuration
        foreach ($configs as $connectionName => $config) {
            $this->info("📋 Processing connection: {$connectionName}");
            $this->processConnection($connectionName, $config);
        }

        $this->createMainConfigFile($configs);

        $this->displaySetupSummary($configs);

        return self::SUCCESS;
    }

    /**
     * Get the .oci directory path.
     */
    private function getOciPath(): string
    {
        $customPath = $this->option('path');

        if (is_string($customPath) && $customPath !== '') {
            return rtrim($customPath, '/');
        }

        // Default to user's home directory
        $homeDir = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? getcwd();

        return $homeDir.'/.oci';
    }

    /**
     * Create .oci directory with proper permissions.
     */
    private function createOciDirectory(): void
    {
        if (! File::exists($this->ociPath)) {
            File::makeDirectory($this->ociPath, 0700, true);
            $this->info("✅ Created .oci directory: {$this->ociPath}");
        } else {
            $this->line("📁 Directory already exists: {$this->ociPath}");
        }

        // Ensure proper permissions (readable/writable by owner only)
        if (file_exists($this->ociPath)) {
            chmod($this->ociPath, 0700);
        }
    }

    /**
     * Get OCI configurations from Laravel config.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getOciConfigurations(): array
    {
        $configs = [];
        $specificConnections = $this->option('connection');
        $specificConnections = is_array($specificConnections) ? $specificConnections : [];

        // Get configurations from laravel-oci-driver config
        $ociDriverConfigs = config('laravel-oci-driver.connections', []);
        if (is_array($ociDriverConfigs)) {
            foreach ($ociDriverConfigs as $name => $config) {
                if (is_array($config) && (empty($specificConnections) || in_array($name, $specificConnections, true))) {
                    $configs[$name] = $config;
                }
            }
        }

        // Also check filesystem disks for OCI configurations
        $filesystems = config('filesystems.disks', []);
        if (is_array($filesystems)) {
            foreach ($filesystems as $name => $config) {
                if (is_array($config) && isset($config['driver']) && $config['driver'] === 'oci') {
                    if (empty($specificConnections) || in_array($name, $specificConnections, true)) {
                        $configs[$name] = $config;
                    }
                }
            }
        }

        return $configs;
    }

    /**
     * Process a single OCI connection configuration.
     *
     * @param  array<string, mixed>  $config
     */
    private function processConnection(string $connectionName, array $config): void
    {
        $this->line("  └─ Setting up connection: {$connectionName}");

        // Generate or create private key file
        $keyPath = $this->createPrivateKeyFile($connectionName, $config);

        // Add key path to config for reference
        $config['key_path'] = $keyPath;

        $this->line("     ✅ Private key: {$keyPath}");
    }

    /**
     * Create or generate private key file for a connection.
     *
     * @param  array<string, mixed>  $config
     */
    private function createPrivateKeyFile(string $connectionName, array $config): string
    {
        $keyFileName = "{$connectionName}.pem";
        $keyPath = "{$this->ociPath}/{$keyFileName}";

        // Check if key file already exists
        if (File::exists($keyPath) && ! $this->option('force')) {
            $this->line("     📝 Private key already exists: {$keyPath}");

            return $keyPath;
        }

        // Check if there's an existing key_path in the config
        $existingKeyPath = $config['key_path'] ?? $config['private_key_path'] ?? null;

        if (is_string($existingKeyPath) && File::exists($existingKeyPath) && ! $this->option('generate-keys')) {
            // Copy existing key file
            File::copy($existingKeyPath, $keyPath);
            if (file_exists($keyPath)) {
                chmod($keyPath, 0600);
            }
            $this->line("     📋 Copied existing private key to: {$keyPath}");

            return $keyPath;
        }

        // Generate new key pair if requested, otherwise create a template
        if ($this->option('generate-keys')) {
            $this->generateKeyPair($connectionName, $keyPath);
        } else {
            // Create a template key file
            $this->createTemplateKeyFile($keyPath);
        }

        return $keyPath;
    }

    /**
     * Generate a new RSA key pair for OCI API signing.
     */
    private function generateKeyPair(string $connectionName, string $keyPath): void
    {
        $this->line("     🔑 Generating new RSA key pair for {$connectionName}...");

        $publicKeyPath = str_replace('.pem', '_public.pem', $keyPath);

        try {
            // Generate private key (2048-bit RSA)
            $result = Process::run([
                'openssl', 'genrsa',
                '-out', $keyPath,
                '2048',
            ]);

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to generate private key: '.$result->errorOutput());
            }

            // Set proper permissions for private key
            if (file_exists($keyPath)) {
                chmod($keyPath, 0600);
            }

            // Generate public key from private key
            $result = Process::run([
                'openssl', 'rsa',
                '-pubout',
                '-in', $keyPath,
                '-out', $publicKeyPath,
            ]);

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to generate public key: '.$result->errorOutput());
            }

            // Append OCI_API_KEY label to private key for security
            $privateKeyContent = File::get($keyPath);
            $privateKeyContent .= "\nOCI_API_KEY\n";
            File::put($keyPath, $privateKeyContent);

            // Calculate key fingerprint
            $fingerprint = $this->calculateKeyFingerprint($keyPath);

            $this->line('     ✅ Generated key pair:');
            $this->line("        Private: {$keyPath}");
            $this->line("        Public:  {$publicKeyPath}");
            $this->line("        Fingerprint: {$fingerprint}");

            $this->newLine();
            $this->warn('🔴 IMPORTANT: Upload the public key to OCI Console!');
            $this->line('Public key content:');
            $this->line(File::get($publicKeyPath));
            $this->newLine();

        } catch (\Exception $e) {
            $this->error('Failed to generate key pair: '.$e->getMessage());
            $this->createTemplateKeyFile($keyPath);
        }
    }

    /**
     * Calculate key fingerprint for OCI.
     */
    private function calculateKeyFingerprint(string $keyPath): string
    {
        try {
            // For cross-platform compatibility, use separate commands
            $derResult = Process::run([
                'openssl', 'rsa',
                '-pubout',
                '-outform', 'DER',
                '-in', $keyPath,
            ]);

            if (! $derResult->successful()) {
                throw new \RuntimeException('Failed to convert key to DER format: '.$derResult->errorOutput());
            }

            // Pipe the DER output to md5
            $md5Result = Process::input($derResult->output())->run([
                'openssl', 'md5', '-c',
            ]);

            if ($md5Result->successful()) {
                $output = trim($md5Result->output());

                // Clean up the output to get just the fingerprint
                return trim(str_replace(['(stdin)= ', 'MD5(stdin)= '], '', $output));
            }
        } catch (\Exception $e) {
            $this->warn('Could not calculate fingerprint: '.$e->getMessage());
        }

        return 'CALCULATE_MANUALLY';
    }

    /**
     * Create a template private key file.
     */
    private function createTemplateKeyFile(string $keyPath): void
    {
        $template = <<<'EOL'
-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY_CONTENT_GOES_HERE
REPLACE_THIS_WITH_YOUR_ACTUAL_PRIVATE_KEY
-----END PRIVATE KEY-----
OCI_API_KEY
EOL;

        File::put($keyPath, $template);
        if (file_exists($keyPath)) {
            chmod($keyPath, 0600);
        }

        $this->line("     📝 Created template private key file: {$keyPath}");
        $this->warn('     ⚠️  Please replace with your actual private key content');
    }

    /**
     * Create the main OCI config file.
     *
     * @param  array<string, array<string, mixed>>  $configs
     */
    private function createMainConfigFile(array $configs): void
    {
        $configPath = "{$this->ociPath}/config";
        $profileName = $this->option('profile');
        $profileName = is_string($profileName) ? $profileName : 'DEFAULT';

        $configContent = '';

        foreach ($configs as $connectionName => $config) {
            $profile = count($configs) === 1 ? $profileName : strtoupper($connectionName);

            $configContent .= $this->generateConfigSection($profile, $connectionName, $config);
            $configContent .= "\n";
        }

        if (File::exists($configPath) && ! $this->option('force')) {
            if (! $this->option('auto-config') && ! $this->confirm('Config file already exists. Overwrite?', false)) {
                $this->line('📋 Skipped config file creation');

                return;
            }
        }

        File::put($configPath, $configContent);
        if (file_exists($configPath)) {
            chmod($configPath, 0600);
        }

        $this->info("✅ Created OCI config file: {$configPath}");
    }

    /**
     * Generate a config section for a connection.
     *
     * @param  array<string, mixed>  $config
     */
    private function generateConfigSection(string $profile, string $connectionName, array $config): string
    {
        $keyPath = "{$this->ociPath}/{$connectionName}.pem";

        $section = "[{$profile}]\n";
        $section .= 'user='.($config['user_id'] ?? 'ocid1.user.oc1..YOUR_USER_OCID')."\n";
        $section .= 'fingerprint='.($config['key_fingerprint'] ?? 'YOUR_KEY_FINGERPRINT')."\n";
        $section .= 'tenancy='.($config['tenancy_id'] ?? 'ocid1.tenancy.oc1..YOUR_TENANCY_OCID')."\n";
        $section .= 'region='.($config['region'] ?? 'us-phoenix-1')."\n";
        $section .= 'key_file='.$keyPath."\n";

        return $section;
    }

    /**
     * Display setup summary.
     *
     * @param  array<string, array<string, mixed>>  $configs
     */
    private function displaySetupSummary(array $configs): void
    {
        $this->newLine();
        $this->info('📋 Setup Summary');

        $this->table(
            ['Item', 'Path/Status'],
            [
                ['OCI Directory', $this->ociPath],
                ['Config File', "{$this->ociPath}/config"],
                ['Connections', count($configs)],
                ['Key Files', count($configs).' files created'],
            ]
        );

        $this->newLine();
        $this->info('🎯 Next Steps:');
        $this->line('1. Upload public keys to OCI Console (if generated)');
        $this->line('2. Update key fingerprints in config if needed');
        $this->line('3. Verify tenancy and user OCIDs');
        $this->line('4. Test connection: php artisan oci:config --validate');

        if (! $this->option('generate-keys')) {
            $this->newLine();
            $this->warn('💡 Tip: Use --generate-keys to auto-generate RSA key pairs');
        }
    }

    /**
     * Check if OpenSSL is available for key generation.
     */
    private function checkOpenSSLAvailability(): bool
    {
        try {
            $result = Process::run(['openssl', 'version']);

            return $result->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
