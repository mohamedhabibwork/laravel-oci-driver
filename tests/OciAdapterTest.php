<?php

namespace LaravelOCI\LaravelOciDriver\Tests;

use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\OciClient;
use League\Flysystem\Config;

/**
 * Helper function to create a test OCI client with default or overridden parameters
 * 
 * @param array<string, mixed> $overrides
 */
function createTestOciClient(array $overrides = []): OciClient
{
    // Create a test configuration with environment variables or defaults
    $config = config('filesystems.disks.oci', []);

    // For actual OCI testing, ensure we have real credentials
    if (!file_exists($config['key_path'])) {
    // Create a temporary key file for testing if it doesn't exist
        $privateKey = <<<EOD
        -----BEGIN PRIVATE KEY-----
        MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQC7VJTUt9Us8cKj
        MzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu
        NMoSfm76oqFvAp8Gy0iz5sxjZmSnXyCdPEovGhLa0VzMaQ8s+CLOyS56YyCFGeJZ
        -----END PRIVATE KEY-----
        EOD;
        
    // Create a temporary private key file
        $tempKeyPath = sys_get_temp_dir() . '/oci_adapter_test_key.pem';
        file_put_contents($tempKeyPath, $privateKey);
        $config['key_path'] = $tempKeyPath;
    }
    

    // Override any configuration values with provided overrides
    $config = array_merge($config, $overrides);

    // Create and return the OCI client
    return OciClient::createWithConfiguration($config);
}

/**
 * Helper function to create a test OCI adapter with default or overridden parameters
 * 
 * @param array<string, mixed> $overrides
 */
function createTestOciAdapter(array $overrides = []): OciAdapter
{
    $client = createTestOciClient($overrides);
    return new OciAdapter($client);
}

/**
 * Helper function to get a test file path
 */
function getTestFilePath(): string
{
    return 'test-file-' . time() . '.txt';
}

/**
 * Clean up after tests
 */
afterEach(function () {
    if (file_exists(sys_get_temp_dir() . '/oci_adapter_test_key.pem')) {
        unlink(sys_get_temp_dir() . '/oci_adapter_test_key.pem');
    }
});

it('initializes the adapter with a client', function () {
    $adapter = createTestOciAdapter();
    expect($adapter)->toBeInstanceOf(OciAdapter::class);
});

it('checks if a file exists', function () {
    $adapter = createTestOciAdapter();
    $path = getTestFilePath();
        
    // File should not exist yet
    expect($adapter->fileExists($path))->toBeFalse();
        
    // Create the file
    $adapter->write($path, 'test content', new Config());
        
    // File should exist now
    expect($adapter->fileExists($path))->toBeTrue();
        
    // Clean up
    $adapter->delete($path);
});

it('reads file contents', function () {
    $adapter = createTestOciAdapter();
    $path = getTestFilePath();
    $content = 'test content ' . time();
        
        // Create the file
        $adapter->write($path, $content, new Config());
        
        // Read the file
        $readContent = $adapter->read($path);
        
        expect($readContent)->toBe($content);
        
        // Clean up
        $adapter->delete($path);
});

it('returns file size', function () {
    $adapter = createTestOciAdapter();
    $path = getTestFilePath();
    $content = 'test content';
        
    // Create the file
    $adapter->write($path, $content, new Config());
        
        // Create the file
        $adapter->write($path, $content, new Config());
        
        // Get the file size
        $fileSize = $adapter->fileSize($path);
        
        expect($fileSize)->toBe(strlen($content));
        
        // Clean up
        $adapter->delete($path);
});

it('returns file last modified time', function () {
    $adapter = createTestOciAdapter();
    $path = getTestFilePath();
    
    // Create the file
    $adapter->write($path, 'test content', new Config());
    
    // Get the last modified time
    $lastModified = $adapter->lastModified($path);
    
    expect($lastModified)->toBeInt();
    expect($lastModified)->toBeGreaterThan(0);
    
    // Clean up
    $adapter->delete($path);
});

it('lists contents of a directory', function () {
    $adapter = createTestOciAdapter();
    $dirPath = 'test-dir-' . time() . '/';
    $filePath1 = $dirPath . 'file1.txt';
    $filePath2 = $dirPath . 'file2.txt';
        
    // Create the files
    $adapter->write($filePath1, 'content 1', new Config());
    $adapter->write($filePath2, 'content 2', new Config());
        
    // List the directory contents
    $contents = $adapter->listContents($dirPath, false);
    
    // Convert iterator to array for testing
    $contentsArray = iterator_to_array($contents);
        
    expect($contentsArray)->toHaveCount(2);
    
    // Clean up
    $adapter->delete($filePath1);
    $adapter->delete($filePath2);
    $adapter->deleteDirectory($dirPath);
});

it('copies a file', function () {
    $adapter = createTestOciAdapter();
    $sourcePath = 'source-' . time() . '.txt';
    $destPath = 'dest-' . time() . '.txt';
    $content = 'test content';
        
    // Create the source file
    $adapter->write($sourcePath, $content, new Config());
        
    // Copy the file
    $adapter->copy($sourcePath, $destPath, new Config());
        
    // Check if the destination file exists and has the same content
    expect($adapter->fileExists($destPath))->toBeTrue();
    expect($adapter->read($destPath))->toBe($content);
    
    // Clean up
    $adapter->delete($sourcePath);
    $adapter->delete($destPath);
});

it('deletes a directory with contents', function () {
    $adapter = createTestOciAdapter();
    $dirPath = 'test-dir-' . time() . '/';
    $filePath1 = $dirPath . 'file1.txt';
    $filePath2 = $dirPath . 'subdir/file2.txt';
    
    // Create the files
    $adapter->write($filePath1, 'content 1', new Config());
    $adapter->write($filePath2, 'content 2', new Config());
    
    // Delete the directory
    $adapter->deleteDirectory($dirPath);
    
    // Check if the files and directory are gone
    expect($adapter->fileExists($filePath1))->toBeFalse();
    expect($adapter->fileExists($filePath2))->toBeFalse();
});
