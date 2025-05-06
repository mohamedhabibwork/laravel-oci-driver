<?php

namespace LaravelOCI\LaravelOciDriver\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LaravelOciDriverCommand extends Command
{
    public $signature = 'oci:status {--bucket= : Show information for a specific bucket}';

    public $description = 'Check Oracle Cloud Infrastructure storage connection status';

    public function handle(): int
    {
        $this->info('Laravel OCI Driver Status Check');

        try {
            $diskName = 'oci';
            $bucket = $this->option('bucket');

            if (! config()->has("filesystems.disks.$diskName")) {
                $this->error("The '$diskName' disk is not configured in your filesystems.php config file.");
                $this->line('Please make sure you have added the OCI disk configuration:');
                $this->line("
                'oci' => [
                    'driver' => 'oci',
                    'namespace' => env('OCI_NAMESPACE'),
                    'region' => env('OCI_REGION'),
                    'bucket' => env('OCI_BUCKET'),
                    'tenancy_id' => env('OCI_TENANCY_ID'),
                    'user_id' => env('OCI_USER_ID'),
                    'storage_tier' => env('OCI_STORAGE_TIER', 'Standard'),
                    'key_fingerprint' => env('OCI_KEY_FINGERPRINT'),
                    'key_path' => env('OCI_KEY_PATH'),
                ],");

                return self::FAILURE;
            }

            $disk = Storage::disk($diskName);

            $config = config("filesystems.disks.$diskName");
            $this->info('OCI Configuration:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Namespace', $config['namespace'] ?? 'Not set'],
                    ['Region', $config['region'] ?? 'Not set'],
                    ['Bucket', $config['bucket'] ?? 'Not set'],
                    ['Storage Tier', $config['storage_tier'] ?? 'Standard'],
                ]
            );

            // Perform a simple operation to check connectivity
            $testFile = 'laravel-oci-driver-test-'.time().'.txt';
            $content = 'Laravel OCI Driver Test - '.now()->toDateTimeString();

            $this->line('Testing connection to OCI storage...');

            // Write test file
            $disk->put($testFile, $content);
            $this->line('✓ Successfully wrote test file');

            // Read test file
            $readContent = $disk->get($testFile);
            if ($readContent === $content) {
                $this->line('✓ Successfully read test file');
            } else {
                $this->error('Error: Content mismatch when reading test file');

                return self::FAILURE;
            }

            // Delete test file
            $disk->delete($testFile);
            $this->line('✓ Successfully deleted test file');

            // List files in bucket or specific path
            if ($bucket) {
                $this->info("Files in bucket '$bucket':");
                $files = $disk->files($bucket);
            } else {
                $this->info('Files in root:');
                $files = $disk->files();
            }

            if (empty($files)) {
                $this->line('No files found.');
            } else {
                $tableData = [];
                foreach ($files as $file) {
                    $lastModified = $disk->lastModified($file);
                    $size = $disk->size($file);
                    $tableData[] = [
                        $file,
                        $this->formatBytes($size),
                        $lastModified ? date('Y-m-d H:i:s', $lastModified) : 'Unknown',
                    ];
                }

                $this->table(['File', 'Size', 'Last Modified'], $tableData);
            }

            $this->info('Connection to OCI storage is working properly!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Connection Error: '.$e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
