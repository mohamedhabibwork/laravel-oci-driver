# API Reference

> **Note:** For a summary of currently implemented features and the project roadmap, see the [README](../README.md) or the documentation index.

This API reference documents all available methods and options for the Laravel OCI Driver. Only features listed in the Implemented Features section are guaranteed to be available.

## Table of Contents

-   [Storage Disk Methods](#storage-disk-methods)
-   [OCI Adapter Methods](#oci-adapter-methods)
-   [Enums Reference](#enums-reference)
-   [Exception Handling](#exception-handling)
-   [Configuration Options](#configuration-options)
-   [Event System](#event-system)

## Storage Disk Methods

The Laravel OCI Driver implements all standard Laravel filesystem methods plus OCI-specific enhancements.

### File Operations

#### `put(string $path, string|resource $contents, array $options = []): bool`

Store file contents at the specified path.

**Parameters:**

-   `$path` (string): File path within the bucket
-   `$contents` (string|resource): File contents or resource stream
-   `$options` (array): Optional configuration

**Options:**

```php
[
    'storage_tier' => StorageTier::STANDARD,
    'visibility' => Visibility::PRIVATE,
    'ContentType' => 'application/octet-stream',
    'metadata' => ['key' => 'value'],
    'cache_control' => 'max-age=3600',
]
```

**Returns:** `bool` - Success status

**Example:**

```php
// Basic usage
$success = Storage::disk('oci')->put('documents/report.pdf', $pdfContent);

// With options
$success = Storage::disk('oci')->put('images/photo.jpg', $imageData, [
    'storage_tier' => StorageTier::STANDARD,
    'visibility' => Visibility::PUBLIC,
    'ContentType' => 'image/jpeg',
    'metadata' => [
        'uploaded_by' => auth()->id(),
        'original_name' => 'vacation_photo.jpg'
    ]
]);
```

---

#### `get(string $path): string`

Retrieve file contents as a string.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `string` - File contents

**Throws:** `FileNotFoundException` if file doesn't exist

**Example:**

```php
try {
    $content = Storage::disk('oci')->get('documents/report.pdf');
    return response($content)->header('Content-Type', 'application/pdf');
} catch (FileNotFoundException $e) {
    abort(404, 'File not found');
}
```

---

#### `readStream(string $path): resource|null`

Get a read-stream for a file.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `resource|null` - File stream or null if not found

**Example:**

```php
$stream = Storage::disk('oci')->readStream('large-files/video.mp4');
if ($stream) {
    return response()->stream(function () use ($stream) {
        while (!feof($stream)) {
            echo fread($stream, 8192);
            flush();
        }
        fclose($stream);
    }, 200, [
        'Content-Type' => 'video/mp4',
        'Content-Disposition' => 'attachment; filename="video.mp4"'
    ]);
}
```

---

#### `writeStream(string $path, resource $resource, array $options = []): bool`

Write a stream to a file.

**Parameters:**

-   `$path` (string): File path within the bucket
-   `$resource` (resource): Stream resource
-   `$options` (array): Optional configuration

**Returns:** `bool` - Success status

**Example:**

```php
$inputStream = fopen('php://input', 'r');
$success = Storage::disk('oci')->writeStream('uploads/stream-file.bin', $inputStream, [
    'storage_tier' => StorageTier::INFREQUENT_ACCESS
]);
fclose($inputStream);
```

---

#### `exists(string $path): bool`

Check if a file exists.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `bool` - Existence status

**Example:**

```php
if (Storage::disk('oci')->exists('documents/important.pdf')) {
    // File exists, proceed with operation
    $lastModified = Storage::disk('oci')->lastModified('documents/important.pdf');
}
```

---

#### `missing(string $path): bool`

Check if a file is missing.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `bool` - Missing status (opposite of exists)

**Example:**

```php
if (Storage::disk('oci')->missing('cache/temp-file.txt')) {
    // File doesn't exist, create it
    Storage::disk('oci')->put('cache/temp-file.txt', 'temporary data');
}
```

---

#### `size(string $path): int`

Get file size in bytes.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `int` - File size in bytes

**Throws:** `FileNotFoundException` if file doesn't exist

**Example:**

```php
$sizeInBytes = Storage::disk('oci')->size('videos/large-video.mp4');
$sizeInMB = round($sizeInBytes / 1024 / 1024, 2);
echo "File size: {$sizeInMB} MB";
```

---

#### `lastModified(string $path): int`

Get the last modified timestamp.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `int` - Unix timestamp

**Example:**

```php
$timestamp = Storage::disk('oci')->lastModified('documents/report.pdf');
$date = Carbon::createFromTimestamp($timestamp);
echo "Last modified: " . $date->diffForHumans();
```

---

#### `mimeType(string $path): string|false`

Get the MIME type of a file.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `string|false` - MIME type or false if cannot be determined

**Example:**

```php
$mimeType = Storage::disk('oci')->mimeType('images/photo.jpg');
if ($mimeType === 'image/jpeg') {
    // Process as JPEG image
}
```

---

#### `copy(string $from, string $to): bool`

Copy a file to a new location.

**Parameters:**

-   `$from` (string): Source file path
-   `$to` (string): Destination file path

**Returns:** `bool` - Success status

**Example:**

```php
// Create backup copy
$success = Storage::disk('oci')->copy(
    'documents/important.pdf',
    'backups/important-backup.pdf'
);
```

---

#### `move(string $from, string $to): bool`

Move/rename a file.

**Parameters:**

-   `$from` (string): Source file path
-   `$to` (string): Destination file path

**Returns:** `bool` - Success status

**Example:**

```php
// Rename file
$success = Storage::disk('oci')->move(
    'temp/uploaded-file.txt',
    'documents/final-document.txt'
);
```

---

#### `delete(string|array $paths): bool`

Delete one or more files.

**Parameters:**

-   `$paths` (string|array): File path(s) to delete

**Returns:** `bool` - Success status

**Example:**

```php
// Delete single file
Storage::disk('oci')->delete('temp/old-file.txt');

// Delete multiple files
Storage::disk('oci')->delete([
    'temp/file1.txt',
    'temp/file2.txt',
    'cache/expired-data.json'
]);
```

### Directory Operations

#### `files(string $directory = null, bool $recursive = false): array`

Get all files in a directory.

**Parameters:**

-   `$directory` (string|null): Directory path (null for root)
-   `$recursive` (bool): Include subdirectories

**Returns:** `array` - Array of file paths

**Example:**

```php
// Get files in documents directory
$files = Storage::disk('oci')->files('documents');

// Get all files recursively
$allFiles = Storage::disk('oci')->files('documents', true);

foreach ($files as $file) {
    echo "File: {$file}\n";
}
```

---

#### `allFiles(string $directory = null): array`

Get all files recursively.

**Parameters:**

-   `$directory` (string|null): Directory path (null for root)

**Returns:** `array` - Array of all file paths

**Example:**

```php
$allFiles = Storage::disk('oci')->allFiles('uploads');
$imageFiles = array_filter($allFiles, function ($file) {
    return str_ends_with($file, ['.jpg', '.png', '.gif']);
});
```

---

#### `directories(string $directory = null, bool $recursive = false): array`

Get all directories.

**Parameters:**

-   `$directory` (string|null): Directory path (null for root)
-   `$recursive` (bool): Include subdirectories

**Returns:** `array` - Array of directory paths

**Example:**

```php
$directories = Storage::disk('oci')->directories('uploads');
foreach ($directories as $dir) {
    echo "Directory: {$dir}\n";
}
```

---

#### `makeDirectory(string $path): bool`

Create a directory.

**Parameters:**

-   `$path` (string): Directory path to create

**Returns:** `bool` - Success status

**Example:**

```php
// Create directory by uploading a placeholder file
Storage::disk('oci')->makeDirectory('new-folder');
```

---

#### `deleteDirectory(string $directory): bool`

Delete a directory and its contents.

**Parameters:**

-   `$directory` (string): Directory path to delete

**Returns:** `bool` - Success status

**Example:**

```php
// Delete temporary directory
Storage::disk('oci')->deleteDirectory('temp/processing');
```

### URL Generation

#### `url(string $path): string`

Get the URL for a file.

**Parameters:**

-   `$path` (string): File path within the bucket

**Returns:** `string` - Public URL

**Example:**

```php
$url = Storage::disk('oci')->url('public/images/logo.png');
echo "<img src=\"{$url}\" alt=\"Logo\">";
```

---

#### `temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string`

Generate a temporary URL for private files.

**Parameters:**

-   `$path` (string): File path within the bucket
-   `$expiration` (DateTimeInterface): URL expiration time
-   `$options` (array): Additional URL options

**Options:**

```php
[
    'ResponseContentType' => 'application/pdf',
    'ResponseContentDisposition' => 'attachment; filename="download.pdf"',
    'ResponseCacheControl' => 'no-cache',
]
```

**Returns:** `string` - Temporary URL

**Example:**

```php
// Generate 1-hour temporary URL
$url = Storage::disk('oci')->temporaryUrl(
    'private/secure-document.pdf',
    now()->addHour()
);

// With custom headers
$downloadUrl = Storage::disk('oci')->temporaryUrl(
    'documents/report.pdf',
    now()->addHours(24),
    [
        'ResponseContentType' => 'application/pdf',
        'ResponseContentDisposition' => 'attachment; filename="monthly-report.pdf"'
    ]
);
```

### File Upload Methods

#### `putFile(string $path, File|UploadedFile $file, array $options = []): string|false`

Store an uploaded file.

**Parameters:**

-   `$path` (string): Directory path
-   `$file` (File|UploadedFile): File to upload
-   `$options` (array): Optional configuration

**Returns:** `string|false` - Stored file path or false on failure

**Example:**

```php
// Handle file upload
if ($request->hasFile('document')) {
    $path = Storage::disk('oci')->putFile('uploads', $request->file('document'));
    return response()->json(['path' => $path]);
}
```

---

#### `putFileAs(string $path, File|UploadedFile $file, string $name, array $options = []): string|false`

Store an uploaded file with a specific name.

**Parameters:**

-   `$path` (string): Directory path
-   `$file` (File|UploadedFile): File to upload
-   `$name` (string): Desired filename
-   `$options` (array): Optional configuration

**Returns:** `string|false` - Stored file path or false on failure

**Example:**

```php
$filename = 'user-' . auth()->id() . '-avatar.jpg';
$path = Storage::disk('oci')->putFileAs(
    'avatars',
    $request->file('avatar'),
    $filename,
    ['visibility' => Visibility::PUBLIC]
);
```

## OCI Adapter Methods

Access OCI-specific functionality through the adapter:

```php
$adapter = Storage::disk('oci')->getAdapter();
```

### Storage Tier Management

#### `setStorageTier(string $path, StorageTier $tier): bool`

Change the storage tier of an object.

**Parameters:**

-   `$path` (string): Object path
-   `$tier` (StorageTier): Target storage tier

**Returns:** `bool` - Success status

**Example:**

```php
use MohamedHabibWork\LaravelOciDriver\Enums\StorageTier;

$adapter = Storage::disk('oci')->getAdapter();

// Move old files to cheaper storage
$adapter->setStorageTier('archive/old-data.json', StorageTier::ARCHIVE);
```

---

#### `getStorageTier(string $path): StorageTier`

Get the current storage tier of an object.

**Parameters:**

-   `$path` (string): Object path

**Returns:** `StorageTier` - Current storage tier

**Example:**

```php
$currentTier = $adapter->getStorageTier('documents/report.pdf');
echo "Current tier: " . $currentTier->value;
```

---

#### `restoreObject(string $path, int $hours = 24): bool`

Restore an archived object for temporary access.

**Parameters:**

-   `$path` (string): Object path
-   `$hours` (int): Restoration duration in hours

**Returns:** `bool` - Success status

**Example:**

```php
// Restore archived file for 48 hours
$success = $adapter->restoreObject('archive/old-backup.tar.gz', 48);
if ($success) {
    // Object will be available for download after restoration completes
}
```

### Metadata Operations

#### `getObjectMetadata(string $path): array`

Get object metadata.

**Parameters:**

-   `$path` (string): Object path

**Returns:** `array` - Metadata array

**Example:**

```php
$metadata = $adapter->getObjectMetadata('documents/report.pdf');
echo "Content Type: " . $metadata['ContentType'];
echo "Last Modified: " . $metadata['LastModified'];
echo "Storage Tier: " . $metadata['StorageTier'];
```

---

#### `setObjectMetadata(string $path, array $metadata): bool`

Set custom metadata for an object.

**Parameters:**

-   `$path` (string): Object path
-   `$metadata` (array): Metadata key-value pairs

**Returns:** `bool` - Success status

**Example:**

```php
$success = $adapter->setObjectMetadata('documents/report.pdf', [
    'department' => 'finance',
    'quarter' => 'Q1-2024',
    'author' => 'John Doe'
]);
```

### Bulk Operations

#### `bulkDelete(array $paths): array`

Delete multiple objects efficiently.

**Parameters:**

-   `$paths` (array): Array of object paths

**Returns:** `array` - Results with success/failure status for each path

**Example:**

```php
$results = $adapter->bulkDelete([
    'temp/file1.txt',
    'temp/file2.txt',
    'cache/expired.json'
]);

foreach ($results as $path => $result) {
    if ($result['success']) {
        echo "Deleted: {$path}\n";
    } else {
        echo "Failed to delete {$path}: {$result['error']}\n";
    }
}
```

---

#### `bulkCopy(array $operations): array`

Copy multiple objects efficiently.

**Parameters:**

-   `$operations` (array): Array of copy operations

**Example:**

```php
$operations = [
    ['from' => 'source1.txt', 'to' => 'backup/source1.txt'],
    ['from' => 'source2.txt', 'to' => 'backup/source2.txt'],
];

$results = $adapter->bulkCopy($operations);
```

## Enums Reference

### StorageTier

Represents OCI Object Storage tiers:

```php
use MohamedHabibWork\LaravelOciDriver\Enums\StorageTier;

StorageTier::STANDARD         // Frequently accessed data
StorageTier::INFREQUENT_ACCESS // Monthly access pattern
StorageTier::ARCHIVE         // Long-term storage
```

**Usage:**

```php
$tier = StorageTier::ARCHIVE;
echo $tier->value; // "Archive"
echo $tier->getDescription(); // "Long-term archival storage"
echo $tier->getCostMultiplier(); // 0.1 (relative to Standard)
```

### Visibility

File visibility options:

```php
use MohamedHabibWork\LaravelOciDriver\Enums\Visibility;

Visibility::PUBLIC   // Publicly accessible
Visibility::PRIVATE  // Private access only
```

### HttpMethod

HTTP methods for API requests:

```php
use MohamedHabibWork\LaravelOciDriver\Enums\HttpMethod;

HttpMethod::GET
HttpMethod::POST
HttpMethod::PUT
HttpMethod::DELETE
HttpMethod::HEAD
```

### ContentType

Common MIME types:

```php
use MohamedHabibWork\LaravelOciDriver\Enums\ContentType;

ContentType::TEXT_PLAIN
ContentType::APPLICATION_JSON
ContentType::IMAGE_JPEG
ContentType::APPLICATION_PDF
// ... and many more
```

## Exception Handling

### Exception Hierarchy

```
MohamedHabibWork\LaravelOciDriver\Exceptions\OciException
├── AuthenticationException
├── ConfigurationException
├── NetworkException
├── StorageException
│   ├── FileNotFoundException
│   ├── InsufficientStorageException
│   └── InvalidStorageTierException
└── ValidationException
```

### Exception Usage

```php
use MohamedHabibWork\LaravelOciDriver\Exceptions\{
    OciException,
    AuthenticationException,
    FileNotFoundException,
    StorageException
};

try {
    Storage::disk('oci')->put('test.txt', 'content');
} catch (AuthenticationException $e) {
    Log::error('OCI Authentication failed', [
        'message' => $e->getMessage(),
        'oci_error_code' => $e->getOciErrorCode(),
        'suggestions' => $e->getSuggestions()
    ]);
} catch (FileNotFoundException $e) {
    return response()->json(['error' => 'File not found'], 404);
} catch (StorageException $e) {
    return response()->json(['error' => 'Storage operation failed'], 500);
} catch (OciException $e) {
    Log::error('General OCI error', ['exception' => $e]);
    return response()->json(['error' => 'Service unavailable'], 503);
}
```

## Configuration Options

### Complete Configuration Reference

```php
// config/filesystems.php
'oci' => [
    // Required settings
    'driver' => 'oci',
    'key_provider' => 'file', // 'file' | 'environment' | 'custom'
    'tenancy_ocid' => env('OCI_TENANCY_OCID'),
    'user_ocid' => env('OCI_USER_OCID'),
    'fingerprint' => env('OCI_FINGERPRINT'),
    'private_key_path' => env('OCI_PRIVATE_KEY_PATH'),
    'region' => env('OCI_REGION'),
    'namespace' => env('OCI_NAMESPACE'),
    'bucket' => env('OCI_BUCKET'),

    // Optional settings
    'prefix' => env('OCI_PREFIX', ''),
    'url' => env('OCI_URL'),
    'visibility' => 'private', // 'public' | 'private'
    'storage_tier' => 'Standard', // 'Standard' | 'InfrequentAccess' | 'Archive'
    'passphrase' => env('OCI_PASSPHRASE'),

    // Performance options
    'options' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry_max' => 3,
        'retry_delay' => 1000, // milliseconds
        'chunk_size' => 8388608, // 8MB
        'verify_ssl' => true,
        'user_agent' => 'Laravel-OCI-Driver/1.0',

        // Connection pooling
        'connection_pool_size' => 10,
        'connection_lifetime' => 300, // seconds

        // Upload options
        'multipart_threshold' => 104857600, // 100MB
        'multipart_chunk_size' => 16777216, // 16MB
        'parallel_uploads' => 4,

        // Cache settings
        'cache_metadata' => true,
        'cache_ttl' => 3600, // seconds
        'cache_prefix' => 'oci_metadata',
    ],

    // Debug and logging
    'debug' => env('OCI_DEBUG', false),
    'log_level' => env('OCI_LOG_LEVEL', 'info'),
    'log_channel' => env('OCI_LOG_CHANNEL', 'default'),
],
```

## Event System

The package dispatches events for monitoring and extensibility:

### Available Events

```php
use MohamedHabibWork\LaravelOciDriver\Events\{
    FileUploaded,
    FileDownloaded,
    FileDeleted,
    StorageTierChanged,
    ConnectionEstablished,
    OperationFailed
};
```

### Event Listeners

```php
// app/Listeners/OciEventListener.php
class OciEventListener
{
    public function handleFileUploaded(FileUploaded $event): void
    {
        Log::info('File uploaded to OCI', [
            'path' => $event->path,
            'size' => $event->size,
            'storage_tier' => $event->storageTier,
            'duration' => $event->duration
        ]);
    }

    public function handleOperationFailed(OperationFailed $event): void
    {
        Log::error('OCI operation failed', [
            'operation' => $event->operation,
            'path' => $event->path,
            'error' => $event->error,
            'context' => $event->context
        ]);

        // Send notification to administrators
        Notification::route('slack', config('logging.slack.webhook'))
            ->notify(new OciOperationFailedNotification($event));
    }
}
```

### Registering Listeners

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    FileUploaded::class => [OciEventListener::class . '@handleFileUploaded'],
    OperationFailed::class => [OciEventListener::class . '@handleOperationFailed'],
];
```

---

For more examples and detailed usage instructions, see the main [README](../README.md) and other documentation files.

## 🗺️ Roadmap

See the [README](../README.md) for the most up-to-date roadmap and planned features.

- [ ] Advanced Health Checks (Spatie Health integration)
- [ ] Connection Pooling and advanced parallel/multipart upload support
- [ ] Custom Event Listeners for all storage operations
- [ ] Improved Error Reporting and user-friendly CLI output
- [ ] Web UI for Connection Management
- [ ] More Key Providers (e.g., HashiCorp Vault, AWS Secrets Manager)
- [ ] Automatic Key Rotation
- [ ] Enhanced Documentation & Examples
- [ ] Support for Additional OCI Services (beyond Object Storage)
- [ ] Performance Benchmarks and Tuning Guides
