# Usage Examples

This guide provides practical code examples for using the Laravel OCI Driver in real-world scenarios.

---

## Basic File Operations

### Upload Files

```php
use Illuminate\Support\Facades\Storage;

// Simple file upload
$content = 'Hello, Oracle Cloud!';
Storage::disk('oci')->put('documents/hello.txt', $content);

// Upload with options
Storage::disk('oci')->put('images/photo.jpg', $imageData, [
    'visibility' => 'public',
    'ContentType' => 'image/jpeg',
    'metadata' => [
        'uploaded_by' => auth()->id(),
        'original_name' => $originalFileName
    ]
]);

// Upload from file
Storage::disk('oci')->putFile('uploads', $uploadedFile);

// Upload with custom name
Storage::disk('oci')->putFileAs('uploads', $uploadedFile, 'custom-name.pdf');
```

### Download Files

```php
// Get file contents
$content = Storage::disk('oci')->get('documents/hello.txt');

// Download as response
return Storage::disk('oci')->download('documents/report.pdf');

// Download with custom name
return Storage::disk('oci')->download('documents/report.pdf', 'Monthly Report.pdf');

// Stream large files
return Storage::disk('oci')->response('videos/large-video.mp4');
```

### File Information

```php
// Check if file exists
if (Storage::disk('oci')->exists('documents/hello.txt')) {
    // File exists
}

// Get file size
$size = Storage::disk('oci')->size('documents/hello.txt');

// Get last modified time
$lastModified = Storage::disk('oci')->lastModified('documents/hello.txt');

// Get file URL (for public files)
$url = Storage::disk('oci')->url('public/image.jpg');
```

### List Files

```php
// List all files
$files = Storage::disk('oci')->files();

// List files in directory
$files = Storage::disk('oci')->files('documents');

// List files recursively
$files = Storage::disk('oci')->allFiles('documents');

// List directories
$directories = Storage::disk('oci')->directories('uploads');
```

### Delete Files

```php
// Delete single file
Storage::disk('oci')->delete('documents/old-file.txt');

// Delete multiple files
Storage::disk('oci')->delete([
    'documents/file1.txt',
    'documents/file2.txt',
    'images/old-image.jpg'
]);

// Delete directory
Storage::disk('oci')->deleteDirectory('old-uploads');
```

---

## Storage Tier Management

### Using Different Storage Tiers

```php
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

// Standard tier (default)
Storage::disk('oci')->put('documents/active.pdf', $content, [
    'storage_tier' => StorageTier::STANDARD
]);

// Infrequent Access tier
Storage::disk('oci')->put('archives/monthly-report.pdf', $content, [
    'storage_tier' => StorageTier::INFREQUENT_ACCESS
]);

// Archive tier
Storage::disk('oci')->put('backups/old-data.zip', $content, [
    'storage_tier' => StorageTier::ARCHIVE
]);
```

### Storage Tier Migration

```php
// Move file to different tier
$adapter = Storage::disk('oci')->getAdapter();
$adapter->changeStorageTier('documents/file.pdf', StorageTier::ARCHIVE);

// Bulk tier migration
$files = Storage::disk('oci')->files('old-documents');
foreach ($files as $file) {
    $adapter->changeStorageTier($file, StorageTier::ARCHIVE);
}
```

---

## Laravel Integration Examples

### File Upload Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'category' => 'required|string|in:documents,images,videos'
        ]);

        $file = $request->file('file');
        $category = $request->input('category');
        
        // Determine storage tier based on category
        $storageTier = match ($category) {
            'documents' => StorageTier::STANDARD,
            'images' => StorageTier::INFREQUENT_ACCESS,
            'videos' => StorageTier::ARCHIVE,
        };

        // Upload file
        $path = Storage::disk('oci')->putFile($category, $file, [
            'storage_tier' => $storageTier,
            'visibility' => 'private',
            'metadata' => [
                'uploaded_by' => auth()->id(),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'category' => $category
            ]
        ]);

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => Storage::disk('oci')->url($path)
        ]);
    }

    public function download($path)
    {
        if (!Storage::disk('oci')->exists($path)) {
            abort(404, 'File not found');
        }

        return Storage::disk('oci')->download($path);
    }
}
```

### Model with File Attachments

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
        'title', 'description', 'file_path', 'file_size', 'mime_type'
    ];

    public function uploadFile($file, $category = 'documents')
    {
        $path = Storage::disk('oci')->putFile($category, $file, [
            'metadata' => [
                'model_id' => $this->id,
                'model_type' => static::class,
                'uploaded_at' => now()->toISOString()
            ]
        ]);

        $this->update([
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType()
        ]);

        return $path;
    }

    public function getFileUrl()
    {
        return $this->file_path ? Storage::disk('oci')->url($this->file_path) : null;
    }

    public function downloadFile()
    {
        if (!$this->file_path || !Storage::disk('oci')->exists($this->file_path)) {
            return null;
        }

        return Storage::disk('oci')->download($this->file_path, $this->title);
    }

    public function deleteFile()
    {
        if ($this->file_path && Storage::disk('oci')->exists($this->file_path)) {
            Storage::disk('oci')->delete($this->file_path);
            $this->update(['file_path' => null]);
        }
    }
}
```

### Artisan Command for Bulk Operations

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

class MigrateOldFiles extends Command
{
    protected $signature = 'oci:migrate-old-files {--days=30 : Files older than X days}';
    protected $description = 'Migrate old files to archive storage tier';

    public function handle()
    {
        $days = $this->option('days');
        $cutoffDate = now()->subDays($days);
        
        $this->info("Migrating files older than {$days} days to archive tier...");

        $files = Storage::disk('oci')->allFiles();
        $migratedCount = 0;

        foreach ($files as $file) {
            $lastModified = Storage::disk('oci')->lastModified($file);
            
            if ($lastModified < $cutoffDate->timestamp) {
                try {
                    $adapter = Storage::disk('oci')->getAdapter();
                    $adapter->changeStorageTier($file, StorageTier::ARCHIVE);
                    $migratedCount++;
                    
                    $this->line("Migrated: {$file}");
                } catch (\Exception $e) {
                    $this->error("Failed to migrate {$file}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Migration completed. {$migratedCount} files migrated to archive tier.");
    }
}
```

---

## Error Handling

### Graceful Error Handling

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class FileService
{
    public function uploadWithRetry($file, $path, $maxRetries = 3)
    {
        $attempts = 0;
        
        while ($attempts < $maxRetries) {
            try {
                $result = Storage::disk('oci')->putFile($path, $file);
                
                Log::info('File uploaded successfully', [
                    'path' => $result,
                    'attempts' => $attempts + 1
                ]);
                
                return $result;
            } catch (\Exception $e) {
                $attempts++;
                
                Log::warning('File upload attempt failed', [
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'file' => $file->getClientOriginalName()
                ]);
                
                if ($attempts >= $maxRetries) {
                    Log::error('File upload failed after max retries', [
                        'file' => $file->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ]);
                    
                    throw $e;
                }
                
                // Wait before retry
                sleep(pow(2, $attempts)); // Exponential backoff
            }
        }
    }

    public function safeDelete($path)
    {
        try {
            if (Storage::disk('oci')->exists($path)) {
                Storage::disk('oci')->delete($path);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('File deletion failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }
}
```

### Exception Handling

```php
use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Exception\SignerValidateException;

try {
    $content = Storage::disk('oci')->get('documents/file.txt');
} catch (PrivateKeyFileNotFoundException $e) {
    // Handle authentication issues
    Log::error('OCI private key not found', ['error' => $e->getMessage()]);
    return response()->json(['error' => 'Storage authentication failed'], 500);
} catch (SignerValidateException $e) {
    // Handle signature validation issues
    Log::error('OCI signature validation failed', ['error' => $e->getMessage()]);
    return response()->json(['error' => 'Storage validation failed'], 500);
} catch (\Exception $e) {
    // Handle other storage errors
    Log::error('Storage operation failed', ['error' => $e->getMessage()]);
    return response()->json(['error' => 'Storage operation failed'], 500);
}
```

---

## Performance Optimization

### Streaming Large Files

```php
// Stream large file download
public function downloadLargeFile($path)
{
    $stream = Storage::disk('oci')->readStream($path);
    
    return response()->stream(function () use ($stream) {
        while (!feof($stream)) {
            echo fread($stream, 8192); // 8KB chunks
            flush();
        }
        fclose($stream);
    }, 200, [
        'Content-Type' => 'application/octet-stream',
        'Content-Disposition' => 'attachment; filename="' . basename($path) . '"'
    ]);
}

// Upload large file in chunks
public function uploadLargeFile($file, $path)
{
    $stream = fopen($file->getRealPath(), 'r');
    
    try {
        Storage::disk('oci')->writeStream($path, $stream);
    } finally {
        fclose($stream);
    }
}
```

### Bulk Operations

```php
// Bulk file operations
public function bulkUpload(array $files, $directory = 'uploads')
{
    $results = [];
    
    foreach ($files as $file) {
        try {
            $path = Storage::disk('oci')->putFile($directory, $file);
            $results[] = ['success' => true, 'path' => $path];
        } catch (\Exception $e) {
            $results[] = ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    return $results;
}

// Parallel file processing
public function processFilesInParallel(array $filePaths)
{
    $results = collect($filePaths)->map(function ($path) {
        return [
            'path' => $path,
            'exists' => Storage::disk('oci')->exists($path),
            'size' => Storage::disk('oci')->size($path),
            'modified' => Storage::disk('oci')->lastModified($path)
        ];
    });
    
    return $results->toArray();
}
```

---

## Advanced Use Cases

### Multi-Environment Configuration

```php
// config/filesystems.php
'disks' => [
    'oci-dev' => [
        'driver' => 'oci',
        'namespace' => env('OCI_DEV_NAMESPACE'),
        'bucket' => env('OCI_DEV_BUCKET'),
        'prefix' => 'dev/',
        // ... other dev config
    ],
    
    'oci-prod' => [
        'driver' => 'oci',
        'namespace' => env('OCI_PROD_NAMESPACE'),
        'bucket' => env('OCI_PROD_BUCKET'),
        'prefix' => 'prod/',
        // ... other prod config
    ]
],

// Usage
$disk = app()->environment('production') ? 'oci-prod' : 'oci-dev';
Storage::disk($disk)->put('file.txt', $content);
```

### Event-Driven File Processing

```php
// Event listener for file uploads
namespace App\Listeners;

use Illuminate\Support\Facades\Storage;

class ProcessUploadedFile
{
    public function handle($event)
    {
        $filePath = $event->path;
        
        // Process based on file type
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        match ($extension) {
            'jpg', 'png', 'gif' => $this->processImage($filePath),
            'pdf' => $this->processPdf($filePath),
            'zip' => $this->processArchive($filePath),
            default => $this->processGenericFile($filePath)
        };
    }
    
    private function processImage($path)
    {
        // Generate thumbnails, extract metadata, etc.
        $content = Storage::disk('oci')->get($path);
        // ... image processing logic
    }
}
```

### Custom Middleware for File Access

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Storage;

class ValidateFileAccess
{
    public function handle($request, Closure $next)
    {
        $filePath = $request->route('path');
        
        // Check if user has access to this file
        if (!$this->userCanAccessFile(auth()->user(), $filePath)) {
            abort(403, 'Access denied');
        }
        
        // Check if file exists
        if (!Storage::disk('oci')->exists($filePath)) {
            abort(404, 'File not found');
        }
        
        return $next($request);
    }
    
    private function userCanAccessFile($user, $filePath)
    {
        // Implement your access control logic
        return true;
    }
}
```

---

## Testing Examples

### Unit Tests

```php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_upload()
    {
        Storage::fake('oci');
        
        $file = UploadedFile::fake()->create('test.pdf', 1024);
        
        $response = $this->post('/upload', [
            'file' => $file,
            'category' => 'documents'
        ]);
        
        $response->assertStatus(200);
        Storage::disk('oci')->assertExists('documents/' . $file->hashName());
    }
    
    public function test_file_download()
    {
        Storage::fake('oci');
        Storage::disk('oci')->put('test.txt', 'Hello World');
        
        $response = $this->get('/download/test.txt');
        
        $response->assertStatus(200);
        $response->assertHeader('content-disposition', 'attachment; filename=test.txt');
    }
}
```

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Authentication Setup](AUTHENTICATION.md)
- [API Reference](API_REFERENCE.md)
- [Testing Guide](TESTING.md)
- [Performance Guide](PERFORMANCE.md)

## File Operations

### Basic File Operations

```php
use Illuminate\Support\Facades\Storage;

// Store a file
Storage::disk('oci')->put('documents/report.pdf', $content);

// Read a file
$content = Storage::disk('oci')->get('documents/report.pdf');

// Check if file exists
if (Storage::disk('oci')->exists('documents/report.pdf')) {
    // File exists
}

// Delete a file
Storage::disk('oci')->delete('documents/report.pdf');

// Get file size
$size = Storage::disk('oci')->size('documents/report.pdf');

// Get last modified time
$lastModified = Storage::disk('oci')->lastModified('documents/report.pdf');
```

## Prefix-based File Organization

### Basic Prefix Usage

Configure a prefix in your filesystem configuration:

```php
// config/filesystems.php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'url_path_prefix' => 'uploads',
],
```

Now all file operations are automatically prefixed:

```php
// Store file - actually stored as 'uploads/document.pdf'
Storage::disk('oci')->put('document.pdf', $content);

// Read file - reads from 'uploads/document.pdf'
$content = Storage::disk('oci')->get('document.pdf');

// List files - lists files under 'uploads/' prefix
$files = Storage::disk('oci')->files();
```

### Multi-Tenant File Organization

```php
// config/filesystems.php
'tenant_oci' => [
    'driver' => 'oci',
    // ... other config
    'url_path_prefix' => '', // Will be set dynamically
],
```

```php
// In your service provider or middleware
class TenantMiddleware
{
    public function handle($request, Closure $next)
    {
        if (auth()->check()) {
            $tenantId = auth()->user()->tenant_id;
            
            // Dynamically set prefix for current tenant
            config([
                'filesystems.disks.tenant_oci.url_path_prefix' => "tenant-{$tenantId}"
            ]);
        }
        
        return $next($request);
    }
}

// Now all operations are tenant-isolated
Storage::disk('tenant_oci')->put('documents/contract.pdf', $content);
// Stored as: tenant-123/documents/contract.pdf

Storage::disk('tenant_oci')->get('documents/contract.pdf');
// Reads from: tenant-123/documents/contract.pdf
```

### Environment-based Organization

```php
// config/filesystems.php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'url_path_prefix' => env('APP_ENV', 'production'),
],
```

```php
// In production: files stored under 'production/' prefix
// In staging: files stored under 'staging/' prefix
// In development: files stored under 'development/' prefix

Storage::disk('oci')->put('logs/app.log', $logContent);
// Production: stored as 'production/logs/app.log'
// Staging: stored as 'staging/logs/app.log'
```

### Date-based Backup Organization

```php
// config/filesystems.php
'backup_oci' => [
    'driver' => 'oci',
    // ... other config
    'url_path_prefix' => 'backups/' . date('Y/m'),
    'storage_tier' => 'Archive', // Cost-effective for backups
],
```

```php
// Backup files organized by year and month
Storage::disk('backup_oci')->put('database.sql', $backupContent);
// Stored as: backups/2024/03/database.sql

Storage::disk('backup_oci')->put('files.tar.gz', $filesBackup);
// Stored as: backups/2024/03/files.tar.gz
```

### Application Module Organization

```php
// config/filesystems.php
'oci_uploads' => [
    'driver' => 'oci',
    // ... other config
    'url_path_prefix' => 'app/uploads',
],

'oci_documents' => [
    'driver' => 'oci',
    // ... other config  
    'url_path_prefix' => 'app/documents',
],

'oci_media' => [
    'driver' => 'oci',
    // ... other config
    'url_path_prefix' => 'app/media',
],
```

```php
// User uploads
Storage::disk('oci_uploads')->put('avatar.jpg', $avatarContent);
// Stored as: app/uploads/avatar.jpg

// System documents
Storage::disk('oci_documents')->put('terms.pdf', $termsContent);
// Stored as: app/documents/terms.pdf

// Media files
Storage::disk('oci_media')->put('video.mp4', $videoContent);
// Stored as: app/media/video.mp4
```

### Dynamic Prefix Configuration

```php
class FileService
{
    public function storeUserFile($userId, $filename, $content)
    {
        // Create a disk configuration with user-specific prefix
        $diskConfig = config('filesystems.disks.oci');
        $diskConfig['url_path_prefix'] = "users/{$userId}";
        
        // Create a new disk instance with the custom prefix
        $disk = Storage::build($diskConfig);
        
        return $disk->put($filename, $content);
        // Stored as: users/123/filename.ext
    }
    
    public function storeProjectFile($projectId, $filename, $content)
    {
        $diskConfig = config('filesystems.disks.oci');
        $diskConfig['url_path_prefix'] = "projects/{$projectId}/files";
        
        $disk = Storage::build($diskConfig);
        
        return $disk->put($filename, $content);
        // Stored as: projects/456/files/filename.ext
    }
}
```

### Prefix Migration Example

If you need to migrate files from one prefix to another:

```php
class PrefixMigrationCommand extends Command
{
    public function handle()
    {
        // Source disk (old prefix)
        $sourceDisk = Storage::build([
            'driver' => 'oci',
            // ... config
            'url_path_prefix' => 'old-prefix',
        ]);
        
        // Destination disk (new prefix)
        $destDisk = Storage::build([
            'driver' => 'oci',
            // ... config
            'url_path_prefix' => 'new-prefix',
        ]);
        
        // Get all files from source
        $files = $sourceDisk->allFiles();
        
        foreach ($files as $file) {
            $this->info("Migrating: {$file}");
            
            // Copy file to new prefix
            $content = $sourceDisk->get($file);
            $destDisk->put($file, $content);
            
            // Optionally delete from old prefix
            // $sourceDisk->delete($file);
        }
        
        $this->info('Migration completed!');
    }
}
```

### Working with OciClient Directly

```php
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\OciClient;

// Create client with prefix
$config = OciConfig::fromDisk('oci'); // or fromConnection()
$client = OciClient::fromOciConfig($config);

// Check if prefix is enabled
if ($client->isPrefixEnabled()) {
    $prefix = $client->getPrefix();
    echo "Using prefix: {$prefix}";
}

// Get prefixed path
$prefixedPath = $client->getPrefixedPath('documents/file.pdf');
echo $prefixedPath; // e.g., "uploads/documents/file.pdf"

// Remove prefix from path (useful for display)
$userFriendlyPath = $client->removePrefixFromPath('uploads/documents/file.pdf');
echo $userFriendlyPath; // "documents/file.pdf"

// List all objects under prefix
$objects = $client->listPrefixedObjects([
    'limit' => 100,
    'prefix' => 'documents/', // Additional prefix within the main prefix
]);
``` 