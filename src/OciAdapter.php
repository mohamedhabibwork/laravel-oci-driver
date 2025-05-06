<?php

namespace LaravelOCI\LaravelOciDriver;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

/**
 * Oracle Cloud Infrastructure Object Storage Adapter for Laravel Flysystem
 */
final readonly class OciAdapter implements FilesystemAdapter
{
    /**
     * @param OciClient $client OCI client for API requests
     */
    public function __construct(private OciClient $client) {}

    /**
     * Check if a file exists at the given path
     *
     * @param string $path File path
     *
     * @return bool True if file exists, false otherwise
     */
    public function fileExists(string $path): bool
    {
        return $this->exists($path);
    }

    /**
     * Check if a directory exists at the given path
     *
     * @param string $path Directory path
     *
     * @return bool True if directory exists, false otherwise
     */
    public function directoryExists(string $path): bool
    {
        return $this->exists($path);
    }

    /**
     * Check if a file or directory exists at the given path
     *
     * @param string $path Path to check
     *
     * @return bool True if exists, false otherwise
     */
    private function exists(string $path): bool
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send($uri, 'HEAD');

            return match ($response->getStatusCode()) {
                200 => true,
                404 => false,
                default => throw new UnableToReadFile(
                    'Invalid response code: ' . $response->getStatusCode(),
                    $response->getStatusCode()
                ),
            };
        } catch (GuzzleException $exception) {
            if ($exception->getCode() === 404) {
                return false;
            }
            
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Write a file using a stream
     *
     * @param string $path Path to write to
     * @param resource $contents Contents to write
     * @param Config $config Configuration options
     *
     * @throws UnableToWriteFile
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        $body = stream_get_contents($contents);
        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $body);

        try {
            $response = $this->client->send(
                $uri,
                'PUT',
                ['storage-tier' => $this->client->getStorageTier()->value()],
                $body,
                $mime,
            );

            if ($response->getStatusCode() !== 200) {
                throw new UnableToWriteFile('Unable to write file', $response->getStatusCode());
            }
        } catch (GuzzleException $exception) {
            throw new UnableToWriteFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Read a file's contents
     *
     * @param string $path Path to read from
     *
     * @return string File contents
     *
     * @throws UnableToReadFile
     */
    public function read(string $path): string
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send($uri, 'GET');

            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } 
            
            throw new UnableToReadFile('Unable to read file', $response->getStatusCode());
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Read a file as a stream
     *
     * @param string $path Path to read from
     *
     * @return resource File stream
     *
     * @throws UnableToReadFile
     */
    public function readStream(string $path)
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));
        
        try {
            $response = $this->client->send($uri, 'GET');
            
            if ($response->getStatusCode() === 200) {
                return $response->getBody()->detach();
            }
            
            throw new UnableToReadFile('Unable to read file', $response->getStatusCode());
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Delete a directory and all of its contents
     *
     * @param string $path Directory path
     */
    public function deleteDirectory(string $path): void
    {
        // Ensure path has trailing slash to represent a directory
        $dirPath = rtrim($path, '/') . '/';
        
        // List all files in this directory
        $contents = $this->listContents($dirPath, true);
        
        // Delete all files in the directory
        foreach ($contents as $item) {
            try {
                $this->delete($item->path());
            } catch (\Exception $e) {
                // Log error but continue with other files
                // This is important as we want to delete as many files as possible
                \Illuminate\Support\Facades\Log::error('Failed to delete file in directory', [
                    'path' => $item->path(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Finally try to delete the directory placeholder itself (if it exists)
        try {
            $this->delete($dirPath);
        } catch (\Exception $e) {
            // Directory placeholder might not exist, so ignore errors
        }
    }

    /**
     * Delete a file
     *
     * @param string $path File path
     *
     * @throws UnableToReadFile
     */
    public function delete(string $path): void
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send($uri, 'DELETE');

            if ($response->getStatusCode() !== 204) {
                throw new UnableToReadFile('Unable to delete file', $response->getStatusCode());
            }
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Create a directory
     *
     * @param string $path Directory path
     * @param Config $config Configuration options
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->write($path.'/', '', $config);
    }

    /**
     * Write a file
     *
     * @param string $path Path to write to
     * @param string $contents Contents to write
     * @param Config $config Configuration options
     *
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send(
                $uri,
                'PUT',
                ['storage-tier' => $this->client->getStorageTier()->value()],
                $contents,
                'application/octet-stream'
            );

            if ($response->getStatusCode() !== 200) {
                throw new UnableToWriteFile('Unable to write file', $response->getStatusCode());
            }
        } catch (GuzzleException $exception) {
            throw new UnableToWriteFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Set visibility of a file (Not supported by OCI)
     *
     * @param string $path File path
     * @param string $visibility Visibility level
     *
     * @throws \RuntimeException
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw new \RuntimeException('Adapter does not support visibility.');
    }

    /**
     * Get visibility of a file (Not supported by OCI)
     *
     * @param string $path File path
     *
     * @throws \RuntimeException
     */
    public function visibility(string $path): FileAttributes
    {
        throw new \RuntimeException('Adapter does not support visibility.');
    }

    /**
     * Get the mime type of a file
     *
     * @param string $path File path
     *
     * @return FileAttributes File attributes with mime type
     *
     * @throws UnableToReadFile
     */
    public function mimeType(string $path): FileAttributes
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send($uri, 'HEAD');

            if ($response->getStatusCode() === 200) {
                return new FileAttributes(
                    path: $path,
                    mimeType: $response->getHeader('Content-Type')[0]
                );
            }
            
            throw new UnableToReadFile('Unable to read file', $response->getStatusCode());
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Get the last modified time of a file
     *
     * @param string $path File path
     *
     * @return FileAttributes File attributes with last modified time
     *
     * @throws UnableToReadFile
     */
    public function lastModified(string $path): FileAttributes
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send($uri, 'HEAD');

            if ($response->getStatusCode() === 200) {
                return new FileAttributes(
                    path: $path,
                    lastModified: Carbon::parse($response->getHeader('last-modified')[0])->timestamp
                );
            }
            
            throw new UnableToReadFile('Unable to read file', $response->getStatusCode());
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Get the size of a file
     *
     * @param string $path File path
     *
     * @return FileAttributes File attributes with size
     *
     * @throws UnableToReadFile
     */
    public function fileSize(string $path): FileAttributes
    {
        $uri = sprintf('%s/o/%s', $this->client->getBucketUri(), urlencode($path));

        try {
            $response = $this->client->send($uri, 'HEAD');

            if ($response->getStatusCode() === 200) {
                return new FileAttributes(
                    path: $path,
                    fileSize: (int)$response->getHeader('Content-Length')[0]
                );
            }
            
            throw new UnableToReadFile('Unable to read file', $response->getStatusCode());
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * List contents of a directory
     *
     * @param string $path Directory path
     * @param bool $deep Whether to recurse into subdirectories
     *
     * @return iterable<FileAttributes> List of file attributes
     *
     * @throws UnableToReadFile
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $files = collect([]);
        $prefix = rtrim($path, '/');
        
        // Prepare query parameters to filter by prefix if path is provided
        $queryParams = [];
        if (!empty($prefix)) {
            $queryParams['prefix'] = $prefix;
            
            // Add delimiter if not recursively listing
            if (!$deep) {
                $queryParams['delimiter'] = '/';
            }
        }
        
        $uri = sprintf('%s/o', $this->client->getBucketUri());
        
        // Add query parameters to URI if they exist
        if (!empty($queryParams)) {
            $uri .= '?' . http_build_query($queryParams);
        }

        try {
            $response = $this->client->send($uri, 'GET');

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents());

                foreach ($data->objects as $object) {
                    // Skip the directory placeholder itself when listing
                    if ($object->name === $prefix . '/' && !empty($prefix)) {
                        continue;
                    }
                    
                    // For non-recursive listing, only include files directly in this directory
                    if (!$deep && strpos(substr($object->name, strlen($prefix . '/')), '/') !== false) {
                        continue;
                    }
                    
                    $fileSize = $object->size ?? null;
                    $lastModified = isset($object->timeModified) 
                        ? strtotime($object->timeModified) 
                        : null;
                        
                    $files->push(
                        new FileAttributes(
                            $object->name,
                            (int)$fileSize,
                            null,
                            $lastModified
                        )
                    );
                }

                return $files;
            }
            
            throw new UnableToReadFile('Unable to list contents', $response->getStatusCode());
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Move a file
     *
     * @param string $source Source path
     * @param string $destination Destination path
     * @param Config $config Configuration options
     *
     * @throws UnableToReadFile
     */
    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: copy only creates a copy request but does not copy the file directly.
        // That means that the delete will delete the file before it can be copied
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    /**
     * Copy a file
     *
     * @param string $source Source path
     * @param string $destination Destination path
     * @param Config $config Configuration options
     *
     * @throws UnableToReadFile
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $uri = sprintf('%s/actions/copyObject', $this->client->getBucketUri());

        $body = json_encode([
            'sourceObjectName' => $source,
            'destinationRegion' => $this->client->getRegion(),
            'destinationNamespace' => $this->client->getNamespace(),
            'destinationBucket' => $this->client->getBucket(),
            'destinationObjectName' => $destination,
        ]);

        try {
            $response = $this->client->send($uri, 'POST', [], $body);
            
            if ($response->getStatusCode() !== 200) {
                throw new UnableToReadFile('Unable to copy file', $response->getStatusCode());
            }
        } catch (GuzzleException $exception) {
            throw new UnableToReadFile($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Get a public URL for a file
     *
     * @param string $path File path
     *
     * @return string Public URL
     */
    public function getUrl($path): string
    {
        $expiresAt = now()->addDay();

        return cache()->remember(
            key: 'oci_url_'.$path,
            ttl: $expiresAt,
            callback: fn () => $this->client->createTemporaryUrl($path, $expiresAt)
        );
    }
}
