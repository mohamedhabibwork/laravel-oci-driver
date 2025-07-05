# Migration Guide

This guide will help you migrate from other storage providers to the Laravel OCI Driver.

---

## Migration Overview

Migrating to Oracle Cloud Infrastructure Object Storage requires careful planning and execution. This guide covers migration from various storage providers and provides tools to ensure data integrity throughout the process.

### Migration Phases

1. **Assessment**: Analyze current storage usage and requirements
2. **Planning**: Design migration strategy and timeline
3. **Preparation**: Set up OCI environment and tools
4. **Migration**: Transfer data with validation
5. **Validation**: Verify data integrity and completeness
6. **Cutover**: Switch applications to use OCI
7. **Cleanup**: Remove old storage resources

### Pre-Migration Checklist

- [ ] OCI account and credentials configured
- [ ] Required IAM policies and permissions set up
- [ ] Buckets created in OCI
- [ ] Network connectivity verified
- [ ] Migration tools tested
- [ ] Backup strategy in place
- [ ] Rollback plan documented

---

## Migrating from AWS S3

### Configuration Update

```php
// Before (AWS S3)
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
    ],
],

// After (OCI)
'disks' => [
    'oci' => [
        'driver' => 'oci',
        'namespace' => env('OCI_NAMESPACE'),
        'bucket' => env('OCI_BUCKET'),
        'region' => env('OCI_REGION'),
        'tenancy_id' => env('OCI_TENANCY_OCID'),
        'user_id' => env('OCI_USER_OCID'),
        'key_fingerprint' => env('OCI_FINGERPRINT'),
        'key_path' => env('OCI_PRIVATE_KEY_PATH'),
    ],
],
```

### S3 to OCI Migration Script

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;

class MigrateFromS3 extends Command
{
    protected $signature = 'migrate:s3-to-oci {--bucket=} {--prefix=} {--dry-run}';
    protected $description = 'Migrate files from AWS S3 to OCI Object Storage';
    
    private S3Client $s3Client;
    private array $migrationLog = [];
    
    public function handle()
    {
        $this->info('Starting S3 to OCI migration...');
        
        $this->initializeS3Client();
        
        $bucket = $this->option('bucket') ?: config('filesystems.disks.s3.bucket');
        $prefix = $this->option('prefix') ?: '';
        $dryRun = $this->option('dry-run');
        
        $objects = $this->listS3Objects($bucket, $prefix);
        
        $this->info("Found {$objects->count()} objects to migrate");
        
        $progressBar = $this->output->createProgressBar($objects->count());
        
        foreach ($objects as $object) {
            $this->migrateObject($bucket, $object, $dryRun);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        
        $this->generateMigrationReport();
    }
    
    private function initializeS3Client()
    {
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }
    
    private function listS3Objects($bucket, $prefix)
    {
        $objects = collect();
        
        $paginator = $this->s3Client->getPaginator('ListObjects', [
            'Bucket' => $bucket,
            'Prefix' => $prefix,
        ]);
        
        foreach ($paginator as $page) {
            if (isset($page['Contents'])) {
                foreach ($page['Contents'] as $object) {
                    $objects->push($object);
                }
            }
        }
        
        return $objects;
    }
    
    private function migrateObject($bucket, $object, $dryRun)
    {
        $key = $object['Key'];
        $size = $object['Size'];
        
        try {
            if ($dryRun) {
                $this->migrationLog[] = [
                    'key' => $key,
                    'size' => $size,
                    'status' => 'would_migrate',
                    'timestamp' => now(),
                ];
                return;
            }
            
            // Download from S3
            $result = $this->s3Client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            
            $content = $result['Body']->getContents();
            
            // Get metadata
            $metadata = $result['Metadata'] ?? [];
            
            // Upload to OCI
            $uploaded = Storage::disk('oci')->put($key, $content, [
                'metadata' => $metadata,
            ]);
            
            // Verify upload
            if ($this->verifyMigration($key, $size)) {
                $this->migrationLog[] = [
                    'key' => $key,
                    'size' => $size,
                    'status' => 'success',
                    'timestamp' => now(),
                ];
            } else {
                throw new \Exception('Verification failed');
            }
            
        } catch (\Exception $e) {
            $this->migrationLog[] = [
                'key' => $key,
                'size' => $size,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'timestamp' => now(),
            ];
            
            $this->error("Failed to migrate {$key}: {$e->getMessage()}");
        }
    }
    
    private function verifyMigration($key, $originalSize)
    {
        if (!Storage::disk('oci')->exists($key)) {
            return false;
        }
        
        $ociSize = Storage::disk('oci')->size($key);
        return $ociSize === $originalSize;
    }
    
    private function generateMigrationReport()
    {
        $successful = collect($this->migrationLog)->where('status', 'success')->count();
        $failed = collect($this->migrationLog)->where('status', 'failed')->count();
        $total = count($this->migrationLog);
        
        $this->info("\nMigration Report:");
        $this->info("Total objects: {$total}");
        $this->info("Successful: {$successful}");
        $this->info("Failed: {$failed}");
        
        // Save detailed log
        $logFile = storage_path('logs/s3-migration-' . now()->format('Y-m-d-H-i-s') . '.json');
        file_put_contents($logFile, json_encode($this->migrationLog, JSON_PRETTY_PRINT));
        
        $this->info("Detailed log saved to: {$logFile}");
    }
}
```

### Code Migration Examples

```php
// Before (S3)
Storage::disk('s3')->put('file.txt', $content);
$content = Storage::disk('s3')->get('file.txt');
$url = Storage::disk('s3')->url('file.txt');

// After (OCI)
Storage::disk('oci')->put('file.txt', $content);
$content = Storage::disk('oci')->get('file.txt');
$url = Storage::disk('oci')->url('file.txt');
```

---

## Migrating from Google Cloud Storage

### Configuration Update

```php
// Before (Google Cloud Storage)
'disks' => [
    'gcs' => [
        'driver' => 'gcs',
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
        'key_file' => env('GOOGLE_CLOUD_KEY_FILE'),
        'bucket' => env('GOOGLE_CLOUD_STORAGE_BUCKET'),
        'path_prefix' => env('GOOGLE_CLOUD_STORAGE_PATH_PREFIX'),
        'storage_api_uri' => env('GOOGLE_CLOUD_STORAGE_API_URI'),
    ],
],

// After (OCI)
'disks' => [
    'oci' => [
        'driver' => 'oci',
        'namespace' => env('OCI_NAMESPACE'),
        'bucket' => env('OCI_BUCKET'),
        'region' => env('OCI_REGION'),
        'tenancy_id' => env('OCI_TENANCY_OCID'),
        'user_id' => env('OCI_USER_OCID'),
        'key_fingerprint' => env('OCI_FINGERPRINT'),
        'key_path' => env('OCI_PRIVATE_KEY_PATH'),
    ],
],
```

### GCS to OCI Migration Script

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Google\Cloud\Storage\StorageClient;

class MigrateFromGCS extends Command
{
    protected $signature = 'migrate:gcs-to-oci {--bucket=} {--prefix=} {--dry-run}';
    protected $description = 'Migrate files from Google Cloud Storage to OCI';
    
    private StorageClient $gcsClient;
    private array $migrationLog = [];
    
    public function handle()
    {
        $this->info('Starting GCS to OCI migration...');
        
        $this->initializeGCSClient();
        
        $bucketName = $this->option('bucket') ?: config('filesystems.disks.gcs.bucket');
        $prefix = $this->option('prefix') ?: '';
        $dryRun = $this->option('dry-run');
        
        $bucket = $this->gcsClient->bucket($bucketName);
        $objects = $bucket->objects(['prefix' => $prefix]);
        
        $objectCount = iterator_count($objects);
        $this->info("Found {$objectCount} objects to migrate");
        
        $progressBar = $this->output->createProgressBar($objectCount);
        
        foreach ($objects as $object) {
            $this->migrateObject($object, $dryRun);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        
        $this->generateMigrationReport();
    }
    
    private function initializeGCSClient()
    {
        $this->gcsClient = new StorageClient([
            'projectId' => config('filesystems.disks.gcs.project_id'),
            'keyFilePath' => config('filesystems.disks.gcs.key_file'),
        ]);
    }
    
    private function migrateObject($object, $dryRun)
    {
        $name = $object->name();
        $size = $object->info()['size'];
        
        try {
            if ($dryRun) {
                $this->migrationLog[] = [
                    'name' => $name,
                    'size' => $size,
                    'status' => 'would_migrate',
                    'timestamp' => now(),
                ];
                return;
            }
            
            // Download from GCS
            $content = $object->downloadAsString();
            
            // Get metadata
            $metadata = $object->info()['metadata'] ?? [];
            
            // Upload to OCI
            Storage::disk('oci')->put($name, $content, [
                'metadata' => $metadata,
            ]);
            
            // Verify upload
            if ($this->verifyMigration($name, $size)) {
                $this->migrationLog[] = [
                    'name' => $name,
                    'size' => $size,
                    'status' => 'success',
                    'timestamp' => now(),
                ];
            } else {
                throw new \Exception('Verification failed');
            }
            
        } catch (\Exception $e) {
            $this->migrationLog[] = [
                'name' => $name,
                'size' => $size,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'timestamp' => now(),
            ];
            
            $this->error("Failed to migrate {$name}: {$e->getMessage()}");
        }
    }
    
    private function verifyMigration($name, $originalSize)
    {
        if (!Storage::disk('oci')->exists($name)) {
            return false;
        }
        
        $ociSize = Storage::disk('oci')->size($name);
        return $ociSize === $originalSize;
    }
    
    private function generateMigrationReport()
    {
        $successful = collect($this->migrationLog)->where('status', 'success')->count();
        $failed = collect($this->migrationLog)->where('status', 'failed')->count();
        $total = count($this->migrationLog);
        
        $this->info("\nMigration Report:");
        $this->info("Total objects: {$total}");
        $this->info("Successful: {$successful}");
        $this->info("Failed: {$failed}");
        
        // Save detailed log
        $logFile = storage_path('logs/gcs-migration-' . now()->format('Y-m-d-H-i-s') . '.json');
        file_put_contents($logFile, json_encode($this->migrationLog, JSON_PRETTY_PRINT));
        
        $this->info("Detailed log saved to: {$logFile}");
    }
}
```

---

## Migrating from Local Storage

### Local to OCI Migration Script

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrateFromLocal extends Command
{
    protected $signature = 'migrate:local-to-oci {--path=} {--dry-run}';
    protected $description = 'Migrate files from local storage to OCI';
    
    private array $migrationLog = [];
    
    public function handle()
    {
        $this->info('Starting local to OCI migration...');
        
        $localPath = $this->option('path') ?: storage_path('app');
        $dryRun = $this->option('dry-run');
        
        if (!is_dir($localPath)) {
            $this->error("Directory does not exist: {$localPath}");
            return 1;
        }
        
        $files = $this->getFilesRecursively($localPath);
        
        $this->info("Found {$files->count()} files to migrate");
        
        $progressBar = $this->output->createProgressBar($files->count());
        
        foreach ($files as $file) {
            $this->migrateFile($file, $localPath, $dryRun);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        
        $this->generateMigrationReport();
    }
    
    private function getFilesRecursively($directory)
    {
        $files = collect();
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files->push($file);
            }
        }
        
        return $files;
    }
    
    private function migrateFile($file, $basePath, $dryRun)
    {
        $fullPath = $file->getRealPath();
        $relativePath = str_replace($basePath . '/', '', $fullPath);
        $size = $file->getSize();
        
        try {
            if ($dryRun) {
                $this->migrationLog[] = [
                    'local_path' => $fullPath,
                    'oci_path' => $relativePath,
                    'size' => $size,
                    'status' => 'would_migrate',
                    'timestamp' => now(),
                ];
                return;
            }
            
            // Read file content
            $content = file_get_contents($fullPath);
            
            // Get file metadata
            $metadata = [
                'original_path' => $fullPath,
                'migrated_at' => now()->toISOString(),
                'mime_type' => mime_content_type($fullPath),
            ];
            
            // Upload to OCI
            Storage::disk('oci')->put($relativePath, $content, [
                'metadata' => $metadata,
            ]);
            
            // Verify upload
            if ($this->verifyMigration($relativePath, $size)) {
                $this->migrationLog[] = [
                    'local_path' => $fullPath,
                    'oci_path' => $relativePath,
                    'size' => $size,
                    'status' => 'success',
                    'timestamp' => now(),
                ];
            } else {
                throw new \Exception('Verification failed');
            }
            
        } catch (\Exception $e) {
            $this->migrationLog[] = [
                'local_path' => $fullPath,
                'oci_path' => $relativePath,
                'size' => $size,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'timestamp' => now(),
            ];
            
            $this->error("Failed to migrate {$relativePath}: {$e->getMessage()}");
        }
    }
    
    private function verifyMigration($path, $originalSize)
    {
        if (!Storage::disk('oci')->exists($path)) {
            return false;
        }
        
        $ociSize = Storage::disk('oci')->size($path);
        return $ociSize === $originalSize;
    }
    
    private function generateMigrationReport()
    {
        $successful = collect($this->migrationLog)->where('status', 'success')->count();
        $failed = collect($this->migrationLog)->where('status', 'failed')->count();
        $total = count($this->migrationLog);
        
        $this->info("\nMigration Report:");
        $this->info("Total files: {$total}");
        $this->info("Successful: {$successful}");
        $this->info("Failed: {$failed}");
        
        // Save detailed log
        $logFile = storage_path('logs/local-migration-' . now()->format('Y-m-d-H-i-s') . '.json');
        file_put_contents($logFile, json_encode($this->migrationLog, JSON_PRETTY_PRINT));
        
        $this->info("Detailed log saved to: {$logFile}");
    }
}
```

---

## Data Validation and Verification

### Migration Verification Service

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class MigrationVerificationService
{
    public function verifyMigration($sourceProvider, $targetProvider = 'oci')
    {
        $this->info('Starting migration verification...');
        
        $sourceFiles = $this->getFileList($sourceProvider);
        $targetFiles = $this->getFileList($targetProvider);
        
        $results = [
            'total_source' => $sourceFiles->count(),
            'total_target' => $targetFiles->count(),
            'missing_files' => [],
            'size_mismatches' => [],
            'checksum_mismatches' => [],
            'verification_passed' => true,
        ];
        
        foreach ($sourceFiles as $file) {
            $verification = $this->verifyFile($file, $sourceProvider, $targetProvider);
            
            if (!$verification['exists']) {
                $results['missing_files'][] = $file;
                $results['verification_passed'] = false;
            }
            
            if (!$verification['size_match']) {
                $results['size_mismatches'][] = $file;
                $results['verification_passed'] = false;
            }
            
            if (!$verification['checksum_match']) {
                $results['checksum_mismatches'][] = $file;
                $results['verification_passed'] = false;
            }
        }
        
        return $results;
    }
    
    private function verifyFile($filePath, $sourceProvider, $targetProvider)
    {
        $result = [
            'exists' => false,
            'size_match' => false,
            'checksum_match' => false,
        ];
        
        // Check if file exists in target
        if (!Storage::disk($targetProvider)->exists($filePath)) {
            return $result;
        }
        
        $result['exists'] = true;
        
        // Check file size
        $sourceSize = Storage::disk($sourceProvider)->size($filePath);
        $targetSize = Storage::disk($targetProvider)->size($filePath);
        
        if ($sourceSize === $targetSize) {
            $result['size_match'] = true;
        }
        
        // Check file checksum (for critical verification)
        if ($this->shouldVerifyChecksum($filePath)) {
            $sourceChecksum = $this->calculateChecksum($sourceProvider, $filePath);
            $targetChecksum = $this->calculateChecksum($targetProvider, $filePath);
            
            if ($sourceChecksum === $targetChecksum) {
                $result['checksum_match'] = true;
            }
        } else {
            $result['checksum_match'] = true; // Skip checksum for non-critical files
        }
        
        return $result;
    }
    
    private function calculateChecksum($provider, $filePath)
    {
        $content = Storage::disk($provider)->get($filePath);
        return hash('sha256', $content);
    }
    
    private function shouldVerifyChecksum($filePath)
    {
        // Only verify checksums for critical files to save time
        $criticalExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        return in_array(strtolower($extension), $criticalExtensions);
    }
    
    private function getFileList($provider)
    {
        return collect(Storage::disk($provider)->allFiles());
    }
}
```

### Integrity Check Command

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MigrationVerificationService;

class VerifyMigration extends Command
{
    protected $signature = 'migrate:verify {--source=} {--target=oci} {--sample-size=100}';
    protected $description = 'Verify migration integrity between storage providers';
    
    private MigrationVerificationService $verificationService;
    
    public function __construct(MigrationVerificationService $verificationService)
    {
        parent::__construct();
        $this->verificationService = $verificationService;
    }
    
    public function handle()
    {
        $source = $this->option('source');
        $target = $this->option('target');
        $sampleSize = $this->option('sample-size');
        
        if (!$source) {
            $this->error('Source provider is required');
            return 1;
        }
        
        $this->info("Verifying migration from {$source} to {$target}...");
        
        if ($sampleSize) {
            $this->info("Using sample verification with {$sampleSize} files");
            $results = $this->verificationService->verifySample($source, $target, $sampleSize);
        } else {
            $this->info("Performing full verification");
            $results = $this->verificationService->verifyMigration($source, $target);
        }
        
        $this->displayResults($results);
        
        return $results['verification_passed'] ? 0 : 1;
    }
    
    private function displayResults($results)
    {
        $this->info("\nVerification Results:");
        $this->info("Total source files: {$results['total_source']}");
        $this->info("Total target files: {$results['total_target']}");
        
        if (!empty($results['missing_files'])) {
            $this->error("Missing files: " . count($results['missing_files']));
            foreach ($results['missing_files'] as $file) {
                $this->line("  - {$file}");
            }
        }
        
        if (!empty($results['size_mismatches'])) {
            $this->error("Size mismatches: " . count($results['size_mismatches']));
            foreach ($results['size_mismatches'] as $file) {
                $this->line("  - {$file}");
            }
        }
        
        if (!empty($results['checksum_mismatches'])) {
            $this->error("Checksum mismatches: " . count($results['checksum_mismatches']));
            foreach ($results['checksum_mismatches'] as $file) {
                $this->line("  - {$file}");
            }
        }
        
        if ($results['verification_passed']) {
            $this->info("\n✅ Migration verification passed!");
        } else {
            $this->error("\n❌ Migration verification failed!");
        }
    }
}
```

---

## Rollback Strategies

### Rollback Service

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class MigrationRollbackService
{
    public function createRollbackPlan($migrationLogFile)
    {
        $migrationLog = json_decode(file_get_contents($migrationLogFile), true);
        
        $rollbackPlan = [
            'created_at' => now()->toISOString(),
            'migration_log' => $migrationLogFile,
            'actions' => [],
        ];
        
        foreach ($migrationLog as $entry) {
            if ($entry['status'] === 'success') {
                $rollbackPlan['actions'][] = [
                    'action' => 'delete',
                    'provider' => 'oci',
                    'path' => $entry['oci_path'] ?? $entry['key'] ?? $entry['name'],
                    'backup_info' => $entry,
                ];
            }
        }
        
        $rollbackFile = storage_path('logs/rollback-plan-' . now()->format('Y-m-d-H-i-s') . '.json');
        file_put_contents($rollbackFile, json_encode($rollbackPlan, JSON_PRETTY_PRINT));
        
        return $rollbackFile;
    }
    
    public function executeRollback($rollbackPlanFile)
    {
        $rollbackPlan = json_decode(file_get_contents($rollbackPlanFile), true);
        
        $results = [
            'total_actions' => count($rollbackPlan['actions']),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        
        foreach ($rollbackPlan['actions'] as $action) {
            try {
                switch ($action['action']) {
                    case 'delete':
                        Storage::disk($action['provider'])->delete($action['path']);
                        $results['successful']++;
                        break;
                    
                    case 'restore':
                        // Restore from backup if available
                        $this->restoreFromBackup($action);
                        $results['successful']++;
                        break;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'action' => $action,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
    
    private function restoreFromBackup($action)
    {
        // Implementation depends on your backup strategy
        // This could involve restoring from a backup storage location
    }
}
```

### Rollback Command

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MigrationRollbackService;

class RollbackMigration extends Command
{
    protected $signature = 'migrate:rollback {--plan=} {--migration-log=}';
    protected $description = 'Rollback a migration';
    
    private MigrationRollbackService $rollbackService;
    
    public function __construct(MigrationRollbackService $rollbackService)
    {
        parent::__construct();
        $this->rollbackService = $rollbackService;
    }
    
    public function handle()
    {
        $planFile = $this->option('plan');
        $migrationLogFile = $this->option('migration-log');
        
        if (!$planFile && !$migrationLogFile) {
            $this->error('Either --plan or --migration-log must be provided');
            return 1;
        }
        
        if (!$planFile) {
            $this->info('Creating rollback plan from migration log...');
            $planFile = $this->rollbackService->createRollbackPlan($migrationLogFile);
            $this->info("Rollback plan created: {$planFile}");
        }
        
        if (!$this->confirm('Are you sure you want to execute the rollback?')) {
            $this->info('Rollback cancelled');
            return 0;
        }
        
        $this->info('Executing rollback...');
        $results = $this->rollbackService->executeRollback($planFile);
        
        $this->info("Rollback completed:");
        $this->info("Total actions: {$results['total_actions']}");
        $this->info("Successful: {$results['successful']}");
        $this->info("Failed: {$results['failed']}");
        
        if (!empty($results['errors'])) {
            $this->error("Errors occurred during rollback:");
            foreach ($results['errors'] as $error) {
                $this->line("  - {$error['error']}");
            }
        }
        
        return empty($results['errors']) ? 0 : 1;
    }
}
```

---

## Migration Best Practices

### 1. Test with Sample Data
Always test your migration process with a small subset of data first.

### 2. Use Dry Run Mode
Most migration commands support a `--dry-run` flag to preview changes.

### 3. Monitor Progress
Use progress bars and logging to track migration status.

### 4. Verify Data Integrity
Always verify that migrated data matches the source.

### 5. Plan for Downtime
Schedule migrations during low-traffic periods.

### 6. Keep Backups
Maintain backups of your original data until migration is confirmed successful.

### 7. Document Everything
Keep detailed logs of all migration activities.

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Installation Guide](INSTALLATION.md)
- [Deployment Guide](DEPLOYMENT.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md) 