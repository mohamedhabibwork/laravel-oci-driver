# Performance Guide

This guide covers optimization and tuning techniques for the Laravel OCI Driver.

---

## Performance Optimization Strategies

### 1. Connection Configuration

Optimize your OCI connection settings for better performance:

```php
// config/filesystems.php
'oci' => [
    'driver' => 'oci',
    // ... basic config
    'options' => [
        'timeout' => 60,           // Increase for large files
        'connect_timeout' => 15,   // Connection establishment timeout
        'retry_max' => 5,          // Retry failed requests
        'retry_delay' => 1000,     // Delay between retries (ms)
        'chunk_size' => 16777216,  // 16MB chunks for uploads
        'verify_ssl' => true,
        'user_agent' => 'Laravel-OCI-Driver/1.0',
        
        // Connection pooling
        'connection_pool_size' => 20,
        'connection_lifetime' => 300,
        
        // Upload optimization
        'multipart_threshold' => 104857600,  // 100MB
        'multipart_chunk_size' => 16777216,  // 16MB
        'parallel_uploads' => 4,
        
        // Caching
        'cache_metadata' => true,
        'cache_ttl' => 3600,
        'cache_prefix' => 'oci_metadata',
    ],
],
```

### 2. Environment-Specific Optimization

```php
// Production optimizations
if (app()->environment('production')) {
    config([
        'filesystems.disks.oci.options.connection_pool_size' => 50,
        'filesystems.disks.oci.options.parallel_uploads' => 8,
        'filesystems.disks.oci.options.cache_ttl' => 7200,
    ]);
}

// Development optimizations
if (app()->environment('local')) {
    config([
        'filesystems.disks.oci.options.timeout' => 30,
        'filesystems.disks.oci.options.connection_pool_size' => 5,
        'filesystems.disks.oci.debug' => true,
    ]);
}
```

---

## Large File Handling

### Streaming Large Files

```php
use Illuminate\Support\Facades\Storage;

class LargeFileHandler
{
    public function uploadLargeFile($filePath, $destinationPath)
    {
        $stream = fopen($filePath, 'r');
        
        try {
            // Stream upload - memory efficient
            $result = Storage::disk('oci')->writeStream($destinationPath, $stream);
            
            return $result;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
    
    public function downloadLargeFile($path, $outputPath = null)
    {
        $stream = Storage::disk('oci')->readStream($path);
        
        if ($outputPath) {
            $output = fopen($outputPath, 'w');
            stream_copy_to_stream($stream, $output);
            fclose($output);
        } else {
            return response()->stream(function () use ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 8192); // 8KB chunks
                    flush();
                }
                fclose($stream);
            });
        }
    }
    
    public function getFileSize($path)
    {
        // Efficient way to get file size without downloading
        return Storage::disk('oci')->size($path);
    }
}
```

### Chunked Upload Implementation

```php
class ChunkedUploader
{
    private int $chunkSize = 16777216; // 16MB
    
    public function uploadInChunks($filePath, $destinationPath, $callback = null)
    {
        $fileSize = filesize($filePath);
        $chunks = ceil($fileSize / $this->chunkSize);
        $handle = fopen($filePath, 'rb');
        
        $uploadedChunks = [];
        
        try {
            for ($i = 0; $i < $chunks; $i++) {
                $chunkData = fread($handle, $this->chunkSize);
                $chunkPath = "{$destinationPath}.chunk.{$i}";
                
                Storage::disk('oci')->put($chunkPath, $chunkData);
                $uploadedChunks[] = $chunkPath;
                
                if ($callback) {
                    $callback($i + 1, $chunks, strlen($chunkData));
                }
            }
            
            // Combine chunks (if your adapter supports it)
            $this->combineChunks($uploadedChunks, $destinationPath);
            
            return true;
        } finally {
            fclose($handle);
            // Cleanup temporary chunks
            Storage::disk('oci')->delete($uploadedChunks);
        }
    }
    
    private function combineChunks(array $chunkPaths, string $finalPath)
    {
        // Implementation depends on your specific needs
        // This is a simplified example
        $combinedContent = '';
        foreach ($chunkPaths as $chunkPath) {
            $combinedContent .= Storage::disk('oci')->get($chunkPath);
        }
        
        Storage::disk('oci')->put($finalPath, $combinedContent);
    }
}
```

---

## Caching Strategies

### Metadata Caching

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CachedOciService
{
    private int $cacheTtl = 3600; // 1 hour
    
    public function getFileInfo($path)
    {
        $cacheKey = "oci.file_info.{$path}";
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($path) {
            return [
                'exists' => Storage::disk('oci')->exists($path),
                'size' => Storage::disk('oci')->size($path),
                'last_modified' => Storage::disk('oci')->lastModified($path),
                'mime_type' => Storage::disk('oci')->mimeType($path),
            ];
        });
    }
    
    public function listFiles($directory = '')
    {
        $cacheKey = "oci.files.{$directory}";
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($directory) {
            return Storage::disk('oci')->files($directory);
        });
    }
    
    public function invalidateCache($path)
    {
        $keys = [
            "oci.file_info.{$path}",
            "oci.files." . dirname($path),
            "oci.files.", // Root directory
        ];
        
        Cache::forget($keys);
    }
    
    public function warmCache($paths)
    {
        foreach ($paths as $path) {
            $this->getFileInfo($path);
        }
    }
}
```

### Response Caching

```php
use Illuminate\Http\Request;

class CachedFileController
{
    public function download(Request $request, $path)
    {
        $etag = md5($path . Storage::disk('oci')->lastModified($path));
        
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304);
        }
        
        $response = Storage::disk('oci')->download($path);
        
        return $response->header('ETag', $etag)
                       ->header('Cache-Control', 'public, max-age=3600');
    }
    
    public function streamWithCache($path)
    {
        $lastModified = Storage::disk('oci')->lastModified($path);
        $etag = md5($path . $lastModified);
        
        return response()->stream(
            function () use ($path) {
                $stream = Storage::disk('oci')->readStream($path);
                while (!feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }
                fclose($stream);
            },
            200,
            [
                'Content-Type' => Storage::disk('oci')->mimeType($path),
                'Content-Length' => Storage::disk('oci')->size($path),
                'ETag' => $etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }
}
```

---

## Connection Pooling

### Custom Connection Pool

```php
class OciConnectionPool
{
    private array $pool = [];
    private int $maxConnections = 20;
    private int $currentConnections = 0;
    
    public function getConnection()
    {
        if (!empty($this->pool)) {
            return array_pop($this->pool);
        }
        
        if ($this->currentConnections < $this->maxConnections) {
            $this->currentConnections++;
            return $this->createConnection();
        }
        
        // Wait for available connection
        return $this->waitForConnection();
    }
    
    public function releaseConnection($connection)
    {
        if (count($this->pool) < $this->maxConnections) {
            $this->pool[] = $connection;
        } else {
            $this->closeConnection($connection);
            $this->currentConnections--;
        }
    }
    
    private function createConnection()
    {
        // Create new OCI connection
        return Storage::disk('oci')->getAdapter()->getClient();
    }
    
    private function waitForConnection($timeout = 30)
    {
        $start = time();
        while (time() - $start < $timeout) {
            if (!empty($this->pool)) {
                return array_pop($this->pool);
            }
            usleep(100000); // 100ms
        }
        
        throw new \RuntimeException('Connection pool timeout');
    }
    
    private function closeConnection($connection)
    {
        // Clean up connection resources
        unset($connection);
    }
}
```

---

## Monitoring and Metrics

### Performance Monitoring Service

```php
use Illuminate\Support\Facades\Log;

class OciPerformanceMonitor
{
    private array $metrics = [];
    
    public function startOperation($operation, $context = [])
    {
        $id = uniqid();
        $this->metrics[$id] = [
            'operation' => $operation,
            'context' => $context,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
        ];
        
        return $id;
    }
    
    public function endOperation($id, $success = true, $additionalData = [])
    {
        if (!isset($this->metrics[$id])) {
            return;
        }
        
        $metric = &$this->metrics[$id];
        $metric['end_time'] = microtime(true);
        $metric['end_memory'] = memory_get_usage(true);
        $metric['duration'] = $metric['end_time'] - $metric['start_time'];
        $metric['memory_used'] = $metric['end_memory'] - $metric['start_memory'];
        $metric['success'] = $success;
        $metric['additional_data'] = $additionalData;
        
        $this->logMetric($metric);
        unset($this->metrics[$id]);
    }
    
    private function logMetric($metric)
    {
        $logData = [
            'operation' => $metric['operation'],
            'duration' => round($metric['duration'], 3),
            'memory_mb' => round($metric['memory_used'] / 1024 / 1024, 2),
            'success' => $metric['success'],
        ];
        
        if ($metric['duration'] > 5.0) { // Log slow operations
            Log::warning('Slow OCI operation detected', $logData);
        } else {
            Log::info('OCI operation completed', $logData);
        }
        
        // Send to monitoring service (e.g., CloudWatch, DataDog)
        $this->sendToMonitoring($logData);
    }
    
    private function sendToMonitoring($data)
    {
        // Implementation for your monitoring service
        // Example: CloudWatch, DataDog, New Relic, etc.
    }
}

// Usage example
class MonitoredFileService
{
    private OciPerformanceMonitor $monitor;
    
    public function __construct(OciPerformanceMonitor $monitor)
    {
        $this->monitor = $monitor;
    }
    
    public function uploadFile($file, $path)
    {
        $operationId = $this->monitor->startOperation('file_upload', [
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'path' => $path,
        ]);
        
        try {
            $result = Storage::disk('oci')->putFile($path, $file);
            
            $this->monitor->endOperation($operationId, true, [
                'uploaded_path' => $result,
            ]);
            
            return $result;
        } catch (\Exception $e) {
            $this->monitor->endOperation($operationId, false, [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### Health Check Implementation

```php
class OciHealthCheck
{
    public function check()
    {
        $results = [
            'connection' => $this->checkConnection(),
            'upload' => $this->checkUpload(),
            'download' => $this->checkDownload(),
            'performance' => $this->checkPerformance(),
        ];
        
        $overall = collect($results)->every(fn($result) => $result['status'] === 'ok');
        
        return [
            'status' => $overall ? 'healthy' : 'unhealthy',
            'checks' => $results,
            'timestamp' => now()->toISOString(),
        ];
    }
    
    private function checkConnection()
    {
        try {
            $start = microtime(true);
            Storage::disk('oci')->files('', 1); // List 1 file
            $duration = microtime(true) - $start;
            
            return [
                'status' => 'ok',
                'response_time' => round($duration, 3),
                'message' => 'Connection successful'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function checkUpload()
    {
        try {
            $testFile = 'health-check/upload-' . time() . '.txt';
            $content = 'Health check upload test';
            
            $start = microtime(true);
            Storage::disk('oci')->put($testFile, $content);
            $duration = microtime(true) - $start;
            
            // Cleanup
            Storage::disk('oci')->delete($testFile);
            
            return [
                'status' => 'ok',
                'upload_time' => round($duration, 3),
                'message' => 'Upload test successful'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function checkDownload()
    {
        try {
            // Create a test file first
            $testFile = 'health-check/download-' . time() . '.txt';
            $content = 'Health check download test';
            Storage::disk('oci')->put($testFile, $content);
            
            $start = microtime(true);
            $downloaded = Storage::disk('oci')->get($testFile);
            $duration = microtime(true) - $start;
            
            // Cleanup
            Storage::disk('oci')->delete($testFile);
            
            $success = $downloaded === $content;
            
            return [
                'status' => $success ? 'ok' : 'error',
                'download_time' => round($duration, 3),
                'message' => $success ? 'Download test successful' : 'Content mismatch'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function checkPerformance()
    {
        $thresholds = [
            'connection_time' => 2.0,  // 2 seconds
            'upload_time' => 5.0,      // 5 seconds for small file
            'download_time' => 3.0,    // 3 seconds for small file
        ];
        
        // Use previous check results to evaluate performance
        $warnings = [];
        
        return [
            'status' => empty($warnings) ? 'ok' : 'warning',
            'warnings' => $warnings,
            'thresholds' => $thresholds,
        ];
    }
}
```

---

## Troubleshooting Performance Issues

### Common Performance Problems

#### 1. Slow Upload/Download Speeds

**Diagnosis:**
```php
// Test network speed to OCI
$testSize = 1024 * 1024; // 1MB
$content = str_repeat('A', $testSize);

$start = microtime(true);
Storage::disk('oci')->put('speed-test.txt', $content);
$uploadTime = microtime(true) - $start;

$start = microtime(true);
$downloaded = Storage::disk('oci')->get('speed-test.txt');
$downloadTime = microtime(true) - $start;

$uploadSpeed = ($testSize / $uploadTime) / 1024 / 1024; // MB/s
$downloadSpeed = ($testSize / $downloadTime) / 1024 / 1024; // MB/s

echo "Upload speed: {$uploadSpeed} MB/s\n";
echo "Download speed: {$downloadSpeed} MB/s\n";
```

**Solutions:**
- Increase chunk size for large files
- Use connection pooling
- Enable compression
- Check network latency

#### 2. High Memory Usage

**Diagnosis:**
```php
$initialMemory = memory_get_usage(true);

// Your OCI operation
Storage::disk('oci')->put('large-file.txt', $largeContent);

$finalMemory = memory_get_usage(true);
$memoryUsed = ($finalMemory - $initialMemory) / 1024 / 1024; // MB

echo "Memory used: {$memoryUsed} MB\n";
```

**Solutions:**
- Use streaming for large files
- Implement chunked uploads
- Clear caches periodically

#### 3. Connection Timeouts

**Configuration adjustments:**
```php
'options' => [
    'timeout' => 120,          // Increase for large files
    'connect_timeout' => 30,   // Increase for slow networks
    'retry_max' => 5,          // More retries
    'retry_delay' => 2000,     // Longer delay between retries
]
```

---

## Benchmarking Examples

### Upload Performance Benchmark

```php
class OciUploadBenchmark
{
    public function runBenchmark()
    {
        $sizes = [
            '1KB' => 1024,
            '100KB' => 100 * 1024,
            '1MB' => 1024 * 1024,
            '10MB' => 10 * 1024 * 1024,
            '100MB' => 100 * 1024 * 1024,
        ];
        
        $results = [];
        
        foreach ($sizes as $label => $size) {
            $results[$label] = $this->benchmarkSize($size);
        }
        
        $this->generateReport($results);
        
        return $results;
    }
    
    private function benchmarkSize($size)
    {
        $content = str_repeat('A', $size);
        $iterations = $size > 10 * 1024 * 1024 ? 3 : 10; // Fewer iterations for large files
        
        $times = [];
        $memoryUsage = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $path = "benchmark/test-{$size}-{$i}.txt";
            
            $startMemory = memory_get_usage(true);
            $start = microtime(true);
            
            Storage::disk('oci')->put($path, $content);
            
            $end = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $times[] = $end - $start;
            $memoryUsage[] = $endMemory - $startMemory;
            
            // Cleanup
            Storage::disk('oci')->delete($path);
        }
        
        return [
            'avg_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times),
            'avg_memory' => array_sum($memoryUsage) / count($memoryUsage),
            'throughput_mbps' => ($size / (array_sum($times) / count($times))) / 1024 / 1024,
        ];
    }
    
    private function generateReport($results)
    {
        echo "OCI Upload Performance Benchmark\n";
        echo "================================\n\n";
        
        foreach ($results as $size => $stats) {
            echo "File Size: {$size}\n";
            echo "  Average Time: " . round($stats['avg_time'], 3) . "s\n";
            echo "  Min Time: " . round($stats['min_time'], 3) . "s\n";
            echo "  Max Time: " . round($stats['max_time'], 3) . "s\n";
            echo "  Throughput: " . round($stats['throughput_mbps'], 2) . " MB/s\n";
            echo "  Memory Usage: " . round($stats['avg_memory'] / 1024 / 1024, 2) . " MB\n\n";
        }
    }
}
```

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Usage Examples](EXAMPLES.md)
- [Testing Guide](TESTING.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md) 