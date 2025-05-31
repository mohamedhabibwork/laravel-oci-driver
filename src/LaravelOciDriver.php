<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver;

use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\Config\OciConfig;
use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

/**
 * Laravel OCI Driver main class providing convenient methods for OCI Object Storage operations.
 *
 * This class serves as a high-level interface for common OCI operations,
 * providing Laravel-style convenience methods on top of the core adapter functionality.
 */
final class LaravelOciDriver
{
    /**
     * Get the OCI storage disk instance.
     *
     * @param  string|null  $disk  The disk name (defaults to 'oci')
     */
    public static function disk(?string $disk = null): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($disk ?? 'oci');
    }

    /**
     * Create a temporary URL for a file with enhanced options.
     *
     * @param  string  $path  File path
     * @param  \DateTimeInterface|null  $expiresAt  Expiration time (defaults to 1 hour)
     * @param  array<string, mixed>  $options  Additional options
     * @param  string|null  $disk  The disk name
     * @return string The temporary URL
     */
    public static function temporaryUrl(
        string $path,
        ?\DateTimeInterface $expiresAt = null,
        array $options = [],
        ?string $disk = null
    ): string {
        $expiresAt ??= now()->addHour();

        return self::disk($disk)->temporaryUrl($path, $expiresAt, $options);
    }

    /**
     * Upload a file with enhanced metadata and options.
     *
     * @param  string  $path  Destination path
     * @param  mixed  $file  File content, resource, or UploadedFile
     * @param  array<string, mixed>  $options  Upload options
     * @param  string|null  $disk  The disk name
     * @return bool True if successful
     */
    public static function upload(
        string $path,
        mixed $file,
        array $options = [],
        ?string $disk = null
    ): bool {
        $storage = self::disk($disk);

        // Handle different file types
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            return $storage->putFileAs(
                dirname($path),
                $file,
                basename($path),
                $options
            ) !== false;
        }

        if (is_resource($file)) {
            return $storage->writeStream($path, $file, $options);
        }

        return $storage->put($path, $file, $options);
    }

    /**
     * Bulk delete multiple files efficiently.
     *
     * @param  array<string>  $paths  List of file paths to delete
     * @param  string|null  $disk  The disk name
     * @return array<string, bool> Results of deletion attempts
     */
    public static function bulkDelete(array $paths, ?string $disk = null): array
    {
        $storage = self::disk($disk);
        $results = [];

        foreach ($paths as $path) {
            try {
                $storage->delete($path);
                $results[$path] = true;
            } catch (\Exception $e) {
                $results[$path] = false;
            }
        }

        return $results;
    }

    /**
     * Change the storage tier of a file.
     *
     * @param  string  $path  File path
     * @param  StorageTier|string  $tier  New storage tier
     * @param  string|null  $disk  The disk name
     * @return bool True if successful
     */
    public static function changeStorageTier(
        string $path,
        StorageTier|string $tier,
        ?string $disk = null
    ): bool {
        $storage = self::disk($disk);

        // Convert string to enum if needed
        if (is_string($tier)) {
            $tier = StorageTier::fromString($tier);
        }

        // Map storage tier to visibility for the adapter
        $visibility = match ($tier) {
            StorageTier::STANDARD => 'public',
            StorageTier::INFREQUENT_ACCESS => 'infrequent',
            StorageTier::ARCHIVE => 'private',
        };

        try {
            $storage->setVisibility($path, $visibility);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Restore archived files to make them accessible.
     *
     * @param  array<string>|string  $paths  File path(s) to restore
     * @param  int  $hours  Number of hours to keep restored (10-240000)
     * @param  string|null  $disk  The disk name
     * @return bool True if restoration request was successful
     */
    public static function restoreFiles(
        array|string $paths,
        int $hours = 24,
        ?string $disk = null
    ): bool {
        $paths = is_array($paths) ? $paths : [$paths];
        $storage = self::disk($disk);

        // Get the underlying adapter to access OCI-specific functionality
        $adapter = $storage->getAdapter();

        if (! $adapter instanceof OciAdapter) {
            throw new \InvalidArgumentException('Disk must use OCI adapter for restore operations');
        }

        foreach ($paths as $path) {
            if (! $adapter->restore($path, $hours)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get detailed file information including OCI-specific metadata.
     *
     * @param  string  $path  File path
     * @param  string|null  $disk  The disk name
     * @return array<string, mixed>|null File information or null if not found
     */
    public static function getFileInfo(string $path, ?string $disk = null): ?array
    {
        $storage = self::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        try {
            $info = [
                'path' => $path,
                'size' => $storage->size($path),
                'last_modified' => $storage->lastModified($path),
                'mime_type' => $storage->mimeType($path),
                'url' => $storage->url($path),
            ];

            // Try to get visibility if the method exists
            try {
                $info['visibility'] = method_exists($storage, 'visibility') ? $storage->visibility($path) : 'unknown';
            } catch (\Exception) {
                $info['visibility'] = 'unknown';
            }

            return $info;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Copy a file with enhanced options.
     *
     * @param  string  $source  Source file path
     * @param  string  $destination  Destination file path
     * @param  array<string, mixed>  $options  Copy options
     * @param  string|null  $disk  The disk name
     * @return bool True if successful
     */
    public static function copyFile(
        string $source,
        string $destination,
        array $options = [],
        ?string $disk = null
    ): bool {
        $storage = self::disk($disk);

        try {
            return $storage->copy($source, $destination);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Move a file with enhanced options.
     *
     * @param  string  $source  Source file path
     * @param  string  $destination  Destination file path
     * @param  array<string, mixed>  $options  Move options
     * @param  string|null  $disk  The disk name
     * @return bool True if successful
     */
    public static function moveFile(
        string $source,
        string $destination,
        array $options = [],
        ?string $disk = null
    ): bool {
        $storage = self::disk($disk);

        try {
            return $storage->move($source, $destination);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * List files in a directory with enhanced filtering options.
     *
     * @param  string  $directory  Directory path
     * @param  bool  $recursive  Whether to list recursively
     * @param  array<string, mixed>  $options  Listing options
     * @param  string|null  $disk  The disk name
     * @return array<string> List of file paths
     */
    public static function listFiles(
        string $directory = '',
        bool $recursive = false,
        array $options = [],
        ?string $disk = null
    ): array {
        $storage = self::disk($disk);

        if ($recursive) {
            return $storage->allFiles($directory);
        }

        return $storage->files($directory);
    }

    /**
     * List directories in a path.
     *
     * @param  string  $directory  Directory path
     * @param  bool  $recursive  Whether to list recursively
     * @param  string|null  $disk  The disk name
     * @return array<string> List of directory paths
     */
    public static function listDirectories(
        string $directory = '',
        bool $recursive = false,
        ?string $disk = null
    ): array {
        $storage = self::disk($disk);

        if ($recursive) {
            return $storage->allDirectories($directory);
        }

        return $storage->directories($directory);
    }

    /**
     * Check if the OCI connection is working properly.
     *
     * @param  string|null  $disk  The disk name
     * @return bool True if connection is working
     */
    public static function testConnection(?string $disk = null): bool
    {
        try {
            $storage = self::disk($disk);
            $testFile = 'oci-driver-test-'.time().'.txt';
            $testContent = 'OCI Driver Test';

            // Try to write, read, and delete a test file
            $storage->put($testFile, $testContent);
            $readContent = $storage->get($testFile);
            $storage->delete($testFile);

            return $readContent === $testContent;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Switch to a different OCI connection.
     *
     * @param  string  $connection  Connection name
     */
    public static function connection(string $connection): \Illuminate\Contracts\Filesystem\Filesystem
    {
        // Validate connection exists
        if (! OciConfig::connectionExists($connection)) {
            throw new \InvalidArgumentException("OCI connection '{$connection}' does not exist.");
        }

        return Storage::disk("oci_{$connection}");
    }

    /**
     * Get configuration for a specific connection.
     */
    public static function getConnectionConfig(string $connection = 'default'): OciConfig
    {
        return OciConfig::fromConnection($connection);
    }

    /**
     * Get all available OCI connections.
     *
     * @return array<string>
     */
    public static function getAvailableConnections(): array
    {
        return OciConfig::getAvailableConnections();
    }

    /**
     * Test multiple connections.
     *
     * @param  array<string>|null  $connections  Connection names to test, null for all
     * @return array<string, bool> Results of connection tests
     */
    public static function testMultipleConnections(?array $connections = null): array
    {
        $connections ??= self::getAvailableConnections();
        $results = [];

        foreach ($connections as $connection) {
            try {
                $config = self::getConnectionConfig($connection);
                $results[$connection] = self::testConnection("oci_{$connection}");
            } catch (\Exception $e) {
                $results[$connection] = false;
            }
        }

        return $results;
    }

    /**
     * Get connection summary for debugging.
     *
     * @param  string  $connection  Connection name
     * @return array<string, mixed>
     */
    public static function getConnectionSummary(string $connection = 'default'): array
    {
        try {
            $config = self::getConnectionConfig($connection);

            return $config->getConnectionSummary();
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'connection' => $connection,
            ];
        }
    }

    /**
     * Switch between production and development configurations.
     */
    public static function useProduction(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $connections = self::getAvailableConnections();

        // Look for production connection
        foreach (['production', 'prod', 'primary'] as $prodConnection) {
            if (in_array($prodConnection, $connections, true)) {
                return self::connection($prodConnection);
            }
        }

        // Fallback to default
        return self::disk();
    }

    /**
     * Switch to development configuration.
     */
    public static function useDevelopment(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $connections = self::getAvailableConnections();

        // Look for development connection
        foreach (['development', 'dev', 'local'] as $devConnection) {
            if (in_array($devConnection, $connections, true)) {
                return self::connection($devConnection);
            }
        }

        // Fallback to default
        return self::disk();
    }

    /**
     * Create a temporary disk configuration for testing.
     *
     * @param  array<string, mixed>  $overrides  Configuration overrides
     */
    public static function createTemporaryConnection(array $overrides = []): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $baseConfig = self::getConnectionConfig()->toArray();
        $tempConfig = array_merge($baseConfig, $overrides);

        $tempDiskName = 'oci_temp_'.uniqid();

        config(["filesystems.disks.{$tempDiskName}" => array_merge($tempConfig, ['driver' => 'oci'])]);

        return Storage::disk($tempDiskName);
    }
}
