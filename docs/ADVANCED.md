# Advanced Features

This guide describes advanced features such as storage tiers, bulk operations, and event handling in the Laravel OCI Driver.

---

## Advanced Configuration Options

### Custom Adapter Configuration

```php
// config/filesystems.php
'oci-advanced' => [
    'driver' => 'oci',
    'namespace' => env('OCI_NAMESPACE'),
    'bucket' => env('OCI_BUCKET'),
    'prefix' => env('OCI_PREFIX', ''),
    'region' => env('OCI_REGION'),
    
    // Advanced options
    'options' => [
        // Connection settings
        'timeout' => 120,
        'connect_timeout' => 30,
        'retry_max' => 5,
        'retry_delay' => 2000,
        
        // Upload optimization
        'multipart_threshold' => 104857600,  // 100MB
        'multipart_chunk_size' => 16777216,  // 16MB
        'parallel_uploads' => 4,
        
        // Caching
        'cache_metadata' => true,
        'cache_ttl' => 3600,
        'cache_driver' => 'redis',
        
        // Custom headers
        'default_headers' => [
            'x-oci-content-language' => 'en',
            'x-oci-content-encoding' => 'gzip',
        ],
        
        // Lifecycle management
        'lifecycle_rules' => [
            [
                'name' => 'archive-old-files',
                'enabled' => true,
                'prefix' => 'archives/',
                'transitions' => [
                    [
                        'days' => 30,
                        'storage_class' => 'InfrequentAccess',
                    ],
                    [
                        'days' => 90,
                        'storage_class' => 'Archive',
                    ],
                ],
            ],
        ],
    ],
    
    // Custom key provider
    'key_provider' => \App\Services\CustomKeyProvider::class,
    
    // Custom client configuration
    'client_config' => [
        'user_agent' => 'MyApp/1.0 Laravel-OCI-Driver',
        'debug' => env('OCI_DEBUG', false),
        'logger' => 'oci',
    ],
],
```

### Environment-Specific Configuration

```php
// config/oci-environments.php
return [
    'development' => [
        'bucket' => 'dev-bucket',
        'prefix' => 'dev/',
        'debug' => true,
        'cache_ttl' => 300,
    ],
    
    'staging' => [
        'bucket' => 'staging-bucket',
        'prefix' => 'staging/',
        'debug' => false,
        'cache_ttl' => 1800,
    ],
    
    'production' => [
        'bucket' => 'prod-bucket',
        'prefix' => 'prod/',
        'debug' => false,
        'cache_ttl' => 3600,
        'connection_pool_size' => 50,
    ],
];
```

---

## Custom Adapters and Extensions

### Custom OCI Adapter

```php
<?php

namespace App\Storage;

use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

class CustomOciAdapter extends OciAdapter
{
    public function putWithCustomMetadata($path, $contents, $options = [])
    {
        // Add custom metadata
        $options['metadata'] = array_merge($options['metadata'] ?? [], [
            'app_version' => config('app.version'),
            'environment' => app()->environment(),
            'uploaded_at' => now()->toISOString(),
            'user_id' => auth()->id(),
        ]);
        
        return parent::put($path, $contents, $options);
    }
    
    public function getWithAnalytics($path)
    {
        // Track download analytics
        $this->trackDownload($path);
        
        return parent::get($path);
    }
    
    public function deleteWithAudit($path)
    {
        // Log deletion for audit trail
        \Log::info('File deletion', [
            'path' => $path,
            'user_id' => auth()->id(),
            'deleted_at' => now()->toISOString(),
        ]);
        
        return parent::delete($path);
    }
    
    public function bulkUpload(array $files, $directory = '', $options = [])
    {
        $results = [];
        $failures = [];
        
        foreach ($files as $file) {
            try {
                $path = $this->putFile($directory, $file, $options);
                $results[] = [
                    'success' => true,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                ];
            } catch (\Exception $e) {
                $failures[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'original_name' => $file->getClientOriginalName(),
                ];
            }
        }
        
        return [
            'successful' => $results,
            'failed' => $failures,
            'total' => count($files),
            'success_count' => count($results),
            'failure_count' => count($failures),
        ];
    }
    
    private function trackDownload($path)
    {
        // Implement download tracking
        \App\Models\FileDownload::create([
            'file_path' => $path,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'downloaded_at' => now(),
        ]);
    }
}
```

### Custom Service Provider

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use App\Storage\CustomOciAdapter;

class CustomOciServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind('custom-oci-adapter', function ($app) {
            return new CustomOciAdapter(
                $app['config']['filesystems.disks.oci']
            );
        });
    }
    
    public function boot()
    {
        Storage::extend('custom-oci', function ($app, $config) {
            return new CustomOciAdapter($config);
        });
    }
}
```

---

## Event System Integration

### Custom Events

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploaded
{
    use Dispatchable, SerializesModels;
    
    public function __construct(
        public string $path,
        public int $size,
        public string $mimeType,
        public ?int $userId = null,
        public array $metadata = []
    ) {}
}

class FileDownloaded
{
    use Dispatchable, SerializesModels;
    
    public function __construct(
        public string $path,
        public ?int $userId = null,
        public string $ipAddress = '',
        public array $context = []
    ) {}
}

class FileDeleted
{
    use Dispatchable, SerializesModels;
    
    public function __construct(
        public string $path,
        public ?int $userId = null,
        public string $reason = ''
    ) {}
}
```

### Event Listeners

```php
<?php

namespace App\Listeners;

use App\Events\FileUploaded;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedFile
{
    public function handle(FileUploaded $event)
    {
        // Generate thumbnails for images
        if (str_starts_with($event->mimeType, 'image/')) {
            $this->generateThumbnails($event->path);
        }
        
        // Extract metadata for documents
        if ($event->mimeType === 'application/pdf') {
            $this->extractPdfMetadata($event->path);
        }
        
        // Virus scan
        $this->scanForViruses($event->path);
        
        // Update search index
        $this->updateSearchIndex($event->path, $event->metadata);
    }
    
    private function generateThumbnails($path)
    {
        $content = Storage::disk('oci')->get($path);
        
        // Generate different sizes
        $sizes = [150, 300, 600];
        
        foreach ($sizes as $size) {
            $thumbnail = $this->resizeImage($content, $size);
            $thumbnailPath = str_replace('.', "_thumb_{$size}.", $path);
            
            Storage::disk('oci')->put($thumbnailPath, $thumbnail, [
                'metadata' => [
                    'thumbnail_for' => $path,
                    'size' => $size,
                    'generated_at' => now()->toISOString(),
                ]
            ]);
        }
    }
    
    private function extractPdfMetadata($path)
    {
        // Extract PDF metadata using a library like smalot/pdfparser
        $content = Storage::disk('oci')->get($path);
        
        // Parse PDF and extract metadata
        $metadata = $this->parsePdfMetadata($content);
        
        // Store metadata in database
        \App\Models\FileMetadata::create([
            'file_path' => $path,
            'metadata' => $metadata,
        ]);
    }
    
    private function scanForViruses($path)
    {
        // Implement virus scanning
        $content = Storage::disk('oci')->get($path);
        
        if ($this->isVirusDetected($content)) {
            // Quarantine the file
            $quarantinePath = 'quarantine/' . basename($path);
            Storage::disk('oci')->move($path, $quarantinePath);
            
            // Log security incident
            \Log::critical('Virus detected in uploaded file', [
                'original_path' => $path,
                'quarantine_path' => $quarantinePath,
                'user_id' => auth()->id(),
            ]);
        }
    }
}
```

### Event-Driven File Processing Service

```php
<?php

namespace App\Services;

use App\Events\FileUploaded;
use App\Events\FileDownloaded;
use App\Events\FileDeleted;
use Illuminate\Support\Facades\Storage;

class EventDrivenFileService
{
    public function upload($file, $path, $options = [])
    {
        $result = Storage::disk('oci')->putFile($path, $file, $options);
        
        event(new FileUploaded(
            $result,
            $file->getSize(),
            $file->getMimeType(),
            auth()->id(),
            $options['metadata'] ?? []
        ));
        
        return $result;
    }
    
    public function download($path)
    {
        $content = Storage::disk('oci')->get($path);
        
        event(new FileDownloaded(
            $path,
            auth()->id(),
            request()->ip(),
            ['user_agent' => request()->userAgent()]
        ));
        
        return $content;
    }
    
    public function delete($path, $reason = '')
    {
        $result = Storage::disk('oci')->delete($path);
        
        if ($result) {
            event(new FileDeleted(
                $path,
                auth()->id(),
                $reason
            ));
        }
        
        return $result;
    }
}
```

---

## Advanced Storage Tier Management

### Intelligent Tier Management

```php
<?php

namespace App\Services;

use LaravelOCI\LaravelOciDriver\Enums\StorageTier;
use Illuminate\Support\Facades\Storage;

class IntelligentTierManager
{
    private array $tierRules = [
        'immediate' => [
            'max_age_days' => 7,
            'access_frequency' => 'daily',
            'tier' => StorageTier::STANDARD,
        ],
        'frequent' => [
            'max_age_days' => 30,
            'access_frequency' => 'weekly',
            'tier' => StorageTier::STANDARD,
        ],
        'infrequent' => [
            'max_age_days' => 90,
            'access_frequency' => 'monthly',
            'tier' => StorageTier::INFREQUENT_ACCESS,
        ],
        'archive' => [
            'max_age_days' => 365,
            'access_frequency' => 'yearly',
            'tier' => StorageTier::ARCHIVE,
        ],
    ];
    
    public function optimizeTiers()
    {
        $files = Storage::disk('oci')->allFiles();
        $optimized = 0;
        
        foreach ($files as $file) {
            $currentTier = $this->getCurrentTier($file);
            $optimalTier = $this->calculateOptimalTier($file);
            
            if ($currentTier !== $optimalTier) {
                $this->migrateTier($file, $optimalTier);
                $optimized++;
            }
        }
        
        return $optimized;
    }
    
    private function calculateOptimalTier($file)
    {
        $age = $this->getFileAge($file);
        $accessFrequency = $this->getAccessFrequency($file);
        $fileSize = Storage::disk('oci')->size($file);
        
        // Large files (>1GB) go to archive faster
        if ($fileSize > 1024 * 1024 * 1024) {
            $age *= 0.5;
        }
        
        // Frequently accessed files stay in standard tier longer
        if ($accessFrequency > 10) { // 10+ accesses per month
            $age *= 0.3;
        }
        
        foreach ($this->tierRules as $rule) {
            if ($age <= $rule['max_age_days']) {
                return $rule['tier'];
            }
        }
        
        return StorageTier::ARCHIVE;
    }
    
    private function getFileAge($file)
    {
        $lastModified = Storage::disk('oci')->lastModified($file);
        return now()->diffInDays($lastModified);
    }
    
    private function getAccessFrequency($file)
    {
        // Get access frequency from analytics
        return \App\Models\FileDownload::where('file_path', $file)
            ->where('downloaded_at', '>=', now()->subDays(30))
            ->count();
    }
    
    private function migrateTier($file, $targetTier)
    {
        $adapter = Storage::disk('oci')->getAdapter();
        
        try {
            $adapter->changeStorageTier($file, $targetTier);
            
            \Log::info('Storage tier migrated', [
                'file' => $file,
                'target_tier' => $targetTier->value,
                'migrated_at' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Storage tier migration failed', [
                'file' => $file,
                'target_tier' => $targetTier->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Lifecycle Management

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

class LifecycleManager
{
    public function applyLifecycleRules()
    {
        $rules = config('oci.lifecycle_rules', []);
        
        foreach ($rules as $rule) {
            $this->applyRule($rule);
        }
    }
    
    private function applyRule($rule)
    {
        $files = Storage::disk('oci')->files($rule['prefix'] ?? '');
        
        foreach ($files as $file) {
            $this->processFileWithRule($file, $rule);
        }
    }
    
    private function processFileWithRule($file, $rule)
    {
        $age = $this->getFileAge($file);
        
        foreach ($rule['transitions'] ?? [] as $transition) {
            if ($age >= $transition['days']) {
                $targetTier = StorageTier::from($transition['storage_class']);
                $this->migrateTier($file, $targetTier);
            }
        }
        
        // Handle expiration
        if (isset($rule['expiration']) && $age >= $rule['expiration']['days']) {
            $this->expireFile($file);
        }
    }
    
    private function expireFile($file)
    {
        // Move to deletion queue instead of immediate deletion
        $deletionPath = 'deletion-queue/' . basename($file) . '.' . time();
        
        Storage::disk('oci')->move($file, $deletionPath);
        
        // Schedule for final deletion after grace period
        \App\Jobs\DeleteExpiredFile::dispatch($deletionPath)
            ->delay(now()->addDays(7));
        
        \Log::info('File expired and queued for deletion', [
            'original_path' => $file,
            'deletion_path' => $deletionPath,
            'final_deletion_date' => now()->addDays(7)->toISOString(),
        ]);
    }
}
```

---

## Bulk Operations

### Bulk Upload Service

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class BulkUploadService
{
    public function uploadFiles(array $files, $directory = '', $options = [])
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($files),
        ];
        
        foreach ($files as $file) {
            try {
                $path = $this->uploadSingleFile($file, $directory, $options);
                $results['successful'][] = [
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'original_name' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
    
    public function uploadFromZip($zipFile, $directory = '', $options = [])
    {
        $tempDir = sys_get_temp_dir() . '/oci_bulk_' . uniqid();
        mkdir($tempDir, 0755, true);
        
        try {
            // Extract ZIP file
            $zip = new \ZipArchive();
            if ($zip->open($zipFile->getRealPath()) === TRUE) {
                $zip->extractTo($tempDir);
                $zip->close();
            } else {
                throw new \Exception('Failed to extract ZIP file');
            }
            
            // Upload extracted files
            $results = $this->uploadDirectory($tempDir, $directory, $options);
            
            return $results;
        } finally {
            // Cleanup
            $this->deleteDirectory($tempDir);
        }
    }
    
    public function uploadDirectory($localDir, $remoteDir = '', $options = [])
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => 0,
        ];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $results['total']++;
                
                $relativePath = $iterator->getSubPathName();
                $remotePath = $remoteDir . '/' . $relativePath;
                
                try {
                    $content = file_get_contents($file->getRealPath());
                    Storage::disk('oci')->put($remotePath, $content, $options);
                    
                    $results['successful'][] = [
                        'local_path' => $file->getRealPath(),
                        'remote_path' => $remotePath,
                        'size' => $file->getSize(),
                    ];
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'local_path' => $file->getRealPath(),
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }
        
        return $results;
    }
    
    private function uploadSingleFile(UploadedFile $file, $directory, $options)
    {
        return Storage::disk('oci')->putFile($directory, $file, $options);
    }
    
    private function deleteDirectory($dir)
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
            }
            rmdir($dir);
        }
    }
}
```

### Bulk Download Service

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BulkDownloadService
{
    public function downloadAsZip(array $filePaths, $zipName = 'download.zip')
    {
        $tempZip = tempnam(sys_get_temp_dir(), 'oci_download_');
        
        $zip = new ZipArchive();
        if ($zip->open($tempZip, ZipArchive::CREATE) !== TRUE) {
            throw new \Exception('Cannot create ZIP file');
        }
        
        foreach ($filePaths as $filePath) {
            try {
                $content = Storage::disk('oci')->get($filePath);
                $zip->addFromString(basename($filePath), $content);
            } catch (\Exception $e) {
                // Add error file instead of failing completely
                $zip->addFromString(
                    basename($filePath) . '.error',
                    "Error downloading file: " . $e->getMessage()
                );
            }
        }
        
        $zip->close();
        
        return response()->download($tempZip, $zipName)->deleteFileAfterSend(true);
    }
    
    public function downloadDirectory($directory, $zipName = null)
    {
        $files = Storage::disk('oci')->allFiles($directory);
        
        if (empty($files)) {
            throw new \Exception('Directory is empty or does not exist');
        }
        
        $zipName = $zipName ?: basename($directory) . '.zip';
        
        return $this->downloadAsZip($files, $zipName);
    }
    
    public function streamBulkDownload(array $filePaths)
    {
        return response()->stream(function () use ($filePaths) {
            $zip = new ZipArchive();
            $tempZip = tempnam(sys_get_temp_dir(), 'oci_stream_');
            
            if ($zip->open($tempZip, ZipArchive::CREATE) === TRUE) {
                foreach ($filePaths as $filePath) {
                    try {
                        $content = Storage::disk('oci')->get($filePath);
                        $zip->addFromString(basename($filePath), $content);
                    } catch (\Exception $e) {
                        // Skip failed files
                        continue;
                    }
                }
                $zip->close();
                
                // Stream the ZIP file
                $handle = fopen($tempZip, 'rb');
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                    flush();
                }
                fclose($handle);
                unlink($tempZip);
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="bulk-download.zip"',
        ]);
    }
}
```

---

## Custom Middleware

### File Access Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileAccessControl
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        $filePath = $request->route('path');
        $user = auth()->user();
        
        // Check if file exists
        if (!Storage::disk('oci')->exists($filePath)) {
            abort(404, 'File not found');
        }
        
        // Check permissions
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($user, $filePath, $permission)) {
                abort(403, "Missing permission: {$permission}");
            }
        }
        
        // Log access
        $this->logAccess($user, $filePath, $request->method());
        
        return $next($request);
    }
    
    private function hasPermission($user, $filePath, $permission)
    {
        // Implement your permission logic
        switch ($permission) {
            case 'read':
                return $this->canRead($user, $filePath);
            case 'write':
                return $this->canWrite($user, $filePath);
            case 'delete':
                return $this->canDelete($user, $filePath);
            default:
                return false;
        }
    }
    
    private function canRead($user, $filePath)
    {
        // Check if user owns the file or has read access
        return str_starts_with($filePath, "users/{$user->id}/") || 
               $this->hasSharedAccess($user, $filePath, 'read');
    }
    
    private function canWrite($user, $filePath)
    {
        // Check if user owns the file or has write access
        return str_starts_with($filePath, "users/{$user->id}/") || 
               $this->hasSharedAccess($user, $filePath, 'write');
    }
    
    private function canDelete($user, $filePath)
    {
        // Only owners can delete files
        return str_starts_with($filePath, "users/{$user->id}/");
    }
    
    private function hasSharedAccess($user, $filePath, $permission)
    {
        return \App\Models\FilePermission::where('file_path', $filePath)
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->where('expires_at', '>', now())
            ->exists();
    }
    
    private function logAccess($user, $filePath, $method)
    {
        \Log::info('File access', [
            'user_id' => $user->id,
            'file_path' => $filePath,
            'method' => $method,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
```

---

## Integration with Other Services

### Search Integration

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Laravel\Scout\Searchable;

class FileSearchService
{
    public function indexFile($filePath)
    {
        $metadata = $this->extractMetadata($filePath);
        
        \App\Models\SearchableFile::create([
            'path' => $filePath,
            'name' => basename($filePath),
            'content' => $metadata['content'] ?? '',
            'tags' => $metadata['tags'] ?? [],
            'metadata' => $metadata,
        ]);
    }
    
    public function searchFiles($query, $filters = [])
    {
        $search = \App\Models\SearchableFile::search($query);
        
        if (isset($filters['mime_type'])) {
            $search->where('mime_type', $filters['mime_type']);
        }
        
        if (isset($filters['size_min'])) {
            $search->where('size', '>=', $filters['size_min']);
        }
        
        if (isset($filters['size_max'])) {
            $search->where('size', '<=', $filters['size_max']);
        }
        
        if (isset($filters['date_from'])) {
            $search->where('created_at', '>=', $filters['date_from']);
        }
        
        return $search->get();
    }
    
    private function extractMetadata($filePath)
    {
        $content = Storage::disk('oci')->get($filePath);
        $mimeType = Storage::disk('oci')->mimeType($filePath);
        
        $metadata = [
            'mime_type' => $mimeType,
            'size' => Storage::disk('oci')->size($filePath),
            'last_modified' => Storage::disk('oci')->lastModified($filePath),
        ];
        
        // Extract text content based on file type
        switch ($mimeType) {
            case 'application/pdf':
                $metadata['content'] = $this->extractPdfText($content);
                break;
            case 'text/plain':
                $metadata['content'] = $content;
                break;
            case 'application/msword':
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                $metadata['content'] = $this->extractDocumentText($content);
                break;
        }
        
        return $metadata;
    }
}
```

### Notification Integration

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Notification;
use App\Notifications\FileUploadedNotification;
use App\Notifications\FileSharedNotification;

class FileNotificationService
{
    public function notifyFileUploaded($filePath, $userId)
    {
        $user = \App\Models\User::find($userId);
        
        if ($user) {
            $user->notify(new FileUploadedNotification($filePath));
        }
        
        // Notify administrators for large files
        $fileSize = Storage::disk('oci')->size($filePath);
        if ($fileSize > 100 * 1024 * 1024) { // 100MB
            $admins = \App\Models\User::where('role', 'admin')->get();
            Notification::send($admins, new LargeFileUploadedNotification($filePath, $fileSize));
        }
    }
    
    public function notifyFileShared($filePath, $fromUserId, $toUserId)
    {
        $fromUser = \App\Models\User::find($fromUserId);
        $toUser = \App\Models\User::find($toUserId);
        
        if ($toUser) {
            $toUser->notify(new FileSharedNotification($filePath, $fromUser));
        }
    }
}
```

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Usage Examples](EXAMPLES.md)
- [Performance Guide](PERFORMANCE.md)
- [Security Guide](SECURITY.md) 