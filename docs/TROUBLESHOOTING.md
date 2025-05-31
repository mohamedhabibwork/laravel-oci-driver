# Troubleshooting Guide

Comprehensive troubleshooting guide for the Laravel OCI Driver package.

## Table of Contents

-   [Quick Diagnostics](#quick-diagnostics)
-   [Authentication Issues](#authentication-issues)
-   [Configuration Problems](#configuration-problems)
-   [Network and Connectivity](#network-and-connectivity)
-   [File Operation Errors](#file-operation-errors)
-   [Performance Issues](#performance-issues)
-   [Security and Permissions](#security-and-permissions)
-   [Development and Testing](#development-and-testing)
-   [Production Issues](#production-issues)
-   [Debugging Tools](#debugging-tools)

## Quick Diagnostics

### Health Check Commands

Start with these commands to identify issues quickly:

```bash
# Quick system check
php artisan oci:health

# Validate configuration
php artisan oci:config --validate

# Test basic operations
php artisan oci:test

# Check with debug output
php artisan oci:test --debug

# Check specific connection
php artisan oci:health --connection=production
```

### System Requirements Check

```bash
# Check PHP version
php -v

# Check required extensions
php -m | grep -E "(openssl|curl|json|mbstring)"

# Check Laravel version
php artisan --version

# Check Composer packages
composer show | grep oci-driver
```

### Configuration Validation

```bash
# Check environment variables
env | grep OCI_

# Validate .oci directory structure
ls -la .oci/

# Check file permissions
ls -la .oci/*

# Test key file format
openssl rsa -in .oci/oci_api_key.pem -check -noout
```

## Authentication Issues

### Issue: Invalid API Key or Signature

**Symptoms:**

-   Error: "Invalid API key"
-   Error: "Signature verification failed"
-   HTTP 401 Unauthorized responses

**Diagnostic Commands:**

```bash
# Check fingerprint
php artisan oci:fingerprint

# Validate key format
openssl rsa -in .oci/oci_api_key.pem -check -noout

# Test authentication
php artisan oci:config --validate --verbose
```

**Common Causes & Solutions:**

#### 1. Fingerprint Mismatch

```bash
# Generate fingerprint from your private key
openssl rsa -pubout -outform DER -in .oci/oci_api_key.pem | openssl md5 -c

# Compare with OCI Console fingerprint
# Update OCI_FINGERPRINT in .env if different
```

#### 2. Wrong Private Key Format

```bash
# Convert private key to correct format
openssl rsa -in old_key.pem -out .oci/oci_api_key.pem

# Ensure key starts with -----BEGIN PRIVATE KEY-----
head -1 .oci/oci_api_key.pem
```

#### 3. Key File Permissions

```bash
# Fix file permissions
chmod 600 .oci/oci_api_key.pem
chmod 700 .oci/

# Check ownership
chown $USER:$USER .oci/oci_api_key.pem
```

#### 4. Environment Variable Issues

```bash
# Check environment loading
php -r "echo getenv('OCI_FINGERPRINT');"

# Reload environment
php artisan config:clear
php artisan cache:clear
```

### Issue: Key Provider Not Found

**Error:** `Key provider 'file' not found`

**Solution:**

```php
// Check config/filesystems.php
'key_provider' => 'file', // Should be 'file', 'environment', or custom class

// For custom providers, ensure class is registered
app()->bind(KeyProviderContract::class, CustomKeyProvider::class);
```

### Issue: Passphrase Required

**Error:** `Private key requires passphrase`

**Solutions:**

1. **Add passphrase to configuration:**

```env
OCI_PASSPHRASE=your_key_passphrase
```

2. **Remove passphrase from key:**

```bash
openssl rsa -in encrypted_key.pem -out .oci/oci_api_key.pem
```

## Configuration Problems

### Issue: Missing Configuration Values

**Error:** `Required configuration value missing`

**Check Configuration:**

```bash
# View current configuration
php artisan config:show filesystems.disks.oci

# Check environment variables
php artisan tinker
>>> env('OCI_TENANCY_OCID')
>>> env('OCI_USER_OCID')
>>> env('OCI_REGION')
```

**Required Variables Checklist:**

-   [ ] `OCI_TENANCY_OCID`
-   [ ] `OCI_USER_OCID`
-   [ ] `OCI_FINGERPRINT`
-   [ ] `OCI_PRIVATE_KEY_PATH`
-   [ ] `OCI_REGION`
-   [ ] `OCI_NAMESPACE`
-   [ ] `OCI_BUCKET`

### Issue: Invalid Region

**Error:** `Invalid region specified`

**Valid Regions:**

```bash
# Common OCI regions
us-phoenix-1      # US West (Phoenix)
us-ashburn-1      # US East (Ashburn)
eu-frankfurt-1    # Europe (Frankfurt)
ap-tokyo-1        # Asia Pacific (Tokyo)
uk-london-1       # UK South (London)
ap-sydney-1       # Asia Pacific (Sydney)
me-jeddah-1       # Middle East (Jeddah)
```

**Check Region:**

```bash
# Verify region in OCI Console
# Update .env file with correct region
```

### Issue: Bucket Not Found

**Error:** `Bucket does not exist`

**Diagnostic Steps:**

```bash
# Test bucket access
php artisan oci:test --operation=list

# Check bucket name and namespace
# Verify in OCI Console under Object Storage
```

**Solutions:**

1. **Create bucket in OCI Console**
2. **Update configuration with correct bucket name**
3. **Check compartment access permissions**

## Network and Connectivity

### Issue: Connection Timeout

**Error:** `Connection timeout after 30 seconds`

**Solutions:**

#### 1. Increase Timeout Values

```php
// config/filesystems.php
'options' => [
    'timeout' => 120,           // Increase from 30
    'connect_timeout' => 30,    // Increase from 10
]
```

#### 2. Check Network Connectivity

```bash
# Test connectivity to OCI endpoint
curl -I https://objectstorage.us-phoenix-1.oraclecloud.com

# Check DNS resolution
nslookup objectstorage.us-phoenix-1.oraclecloud.com

# Test with longer timeout
timeout 60 curl -I https://objectstorage.us-phoenix-1.oraclecloud.com
```

#### 3. Firewall and Proxy Issues

```bash
# Check if behind corporate firewall
# Configure proxy if needed
export HTTPS_PROXY=http://proxy.company.com:8080
```

### Issue: SSL Certificate Verification Failed

**Error:** `SSL certificate verification failed`

**Solutions:**

#### 1. Update Certificate Bundle

```bash
# Download latest CA bundle
curl -o cacert.pem https://curl.se/ca/cacert.pem

# Configure in Laravel
// config/filesystems.php
'options' => [
    'verify_ssl' => true,
    'ssl_cert_path' => storage_path('certs/cacert.pem'),
]
```

#### 2. Temporary Workaround (Development Only)

```php
// config/filesystems.php - DO NOT USE IN PRODUCTION
'options' => [
    'verify_ssl' => false,
]
```

### Issue: Rate Limiting

**Error:** `Too many requests`

**Solution:**

```php
// config/filesystems.php
'options' => [
    'retry_max' => 5,           // Increase retries
    'retry_delay' => 2000,      // Increase delay (ms)
    'retry_multiplier' => 2,    // Exponential backoff
]
```

## File Operation Errors

### Issue: File Not Found

**Error:** `File not found: path/to/file.txt`

**Debugging Steps:**

```bash
# List files in directory
php artisan tinker
>>> Storage::disk('oci')->files('path/to')

# Check if file exists
>>> Storage::disk('oci')->exists('path/to/file.txt')

# Check exact path and case sensitivity
>>> Storage::disk('oci')->allFiles()
```

### Issue: Large File Upload Failures

**Error:** `Request entity too large` or timeout errors

**Solutions:**

#### 1. Enable Chunked Uploads

```php
// config/filesystems.php
'options' => [
    'chunk_size' => 8388608,        // 8MB chunks
    'use_chunked_upload' => true,
    'multipart_threshold' => 104857600, // 100MB
]
```

#### 2. Use Streaming

```php
// Use streaming for large files
$stream = fopen('large-file.zip', 'r');
Storage::disk('oci')->writeStream('uploads/large-file.zip', $stream);
fclose($stream);
```

#### 3. Increase Memory Limit

```php
// For large files, temporarily increase memory
ini_set('memory_limit', '512M');
```

### Issue: Permission Denied

**Error:** `Permission denied for operation`

**Check IAM Permissions:**

1. **Object Storage permissions in OCI Console**
2. **User group memberships**
3. **Compartment access rights**

**Required IAM Policies:**

```
Allow group ObjectStorageUsers to manage objects in compartment YourCompartment
Allow group ObjectStorageUsers to manage buckets in compartment YourCompartment
```

### Issue: Invalid Storage Tier

**Error:** `Invalid storage tier specified`

**Valid Storage Tiers:**

```php
use MohamedHabibWork\LaravelOciDriver\Enums\StorageTier;

// Valid values
StorageTier::STANDARD
StorageTier::INFREQUENT_ACCESS
StorageTier::ARCHIVE
```

## Performance Issues

### Issue: Slow Upload/Download Speeds

**Diagnostic Commands:**

```bash
# Profile operations
php artisan oci:test --profile

# Check network speed
speedtest-cli

# Monitor memory usage
php artisan oci:test --memory-profile
```

**Optimization Solutions:**

#### 1. Connection Pooling

```php
// config/filesystems.php
'options' => [
    'connection_pool_size' => 10,
    'connection_lifetime' => 300,
]
```

#### 2. Parallel Operations

```php
// Enable parallel uploads
'options' => [
    'parallel_uploads' => 4,
    'chunk_size' => 16777216, // 16MB
]
```

#### 3. Caching

```php
// Enable metadata caching
'options' => [
    'cache_metadata' => true,
    'cache_ttl' => 3600,
]
```

### Issue: High Memory Usage

**Solution:**

```php
// Use streaming for large operations
$files = Storage::disk('oci')->files('large-directory');
foreach ($files as $file) {
    // Process one file at a time
    $stream = Storage::disk('oci')->readStream($file);
    // Process stream
    fclose($stream);
}
```

## Security and Permissions

### Issue: File Permissions

**Error:** Cannot read/write OCI configuration files

**Fix Permissions:**

```bash
# Fix OCI directory permissions
chmod 700 .oci/
chmod 600 .oci/*

# Check ownership
ls -la .oci/
chown -R $USER:$USER .oci/
```

### Issue: Key Exposure

**Security Check:**

```bash
# Ensure keys are not in version control
git status --ignored | grep .oci

# Check .gitignore includes .oci directory
echo ".oci/" >> .gitignore
```

**Production Security:**

```bash
# Store keys outside web root
sudo mkdir -p /etc/oci/
sudo chmod 700 /etc/oci/
sudo chown www-data:www-data /etc/oci/

# Update configuration
OCI_PRIVATE_KEY_PATH=/etc/oci/production_key.pem
```

## Development and Testing

### Issue: Test Failures

**Common Test Issues:**

#### 1. Environment Isolation

```bash
# Use separate test environment
cp .env .env.testing

# Configure test-specific OCI settings
OCI_BUCKET=test-bucket
OCI_PREFIX=test/
```

#### 2. Mock External Calls

```php
// In tests, mock OCI calls
use Mockery;

$mock = Mockery::mock(OciAdapter::class);
$mock->shouldReceive('put')->andReturn(true);
$this->app->instance(OciAdapter::class, $mock);
```

#### 3. Clean Test Data

```php
// Clean up test files after each test
protected function tearDown(): void
{
    Storage::disk('oci')->deleteDirectory('test/');
    parent::tearDown();
}
```

### Issue: Local Development Setup

**Quick Development Setup:**

```bash
# Use separate development bucket
OCI_BUCKET=dev-bucket-$(whoami)
OCI_PREFIX=dev/

# Enable debug mode
OCI_DEBUG=true
OCI_LOG_LEVEL=debug
```

## Production Issues

### Issue: Production Deployment Failures

**Deployment Checklist:**

#### 1. Environment Configuration

```bash
# Verify production environment
php artisan config:show filesystems.disks.oci

# Check environment-specific variables
env | grep OCI_ | grep -v PASS
```

#### 2. Key Management

```bash
# Ensure production keys are properly secured
ls -la /etc/oci/
# Should show: drwx------ (700) for directory
# Should show: -rw------- (600) for key files
```

#### 3. Health Checks

```bash
# Add health check endpoint
php artisan route:list | grep health

# Test health endpoint
curl -f http://yourapp.com/health/oci
```

### Issue: High Production Load

**Monitoring Solutions:**

#### 1. Enable Logging

```php
// config/logging.php
'oci' => [
    'driver' => 'daily',
    'path' => storage_path('logs/oci.log'),
    'level' => 'info',
    'days' => 14,
],
```

#### 2. Performance Monitoring

```php
// Add performance tracking
$startTime = microtime(true);
Storage::disk('oci')->put('file.txt', $content);
$duration = microtime(true) - $startTime;
Log::info('OCI operation completed', ['duration' => $duration]);
```

#### 3. Resource Optimization

```php
// Optimize for high load
'options' => [
    'connection_pool_size' => 20,
    'retry_max' => 5,
    'chunk_size' => 32768000, // 32MB
]
```

## Debugging Tools

### Enable Debug Mode

```bash
# Enable debug logging
export OCI_DEBUG=true
export OCI_LOG_LEVEL=debug

# Run operations with verbose output
php artisan oci:test --debug --verbose
```

### Custom Debug Commands

```php
// Create custom debugging command
php artisan make:command OciDebugCommand

// Add to command handle method
public function handle()
{
    $adapter = Storage::disk('oci')->getAdapter();

    // Test authentication
    $this->info('Testing authentication...');
    try {
        $adapter->getClient()->testAuthentication();
        $this->info('✅ Authentication successful');
    } catch (Exception $e) {
        $this->error('❌ Authentication failed: ' . $e->getMessage());
    }

    // Test bucket access
    $this->info('Testing bucket access...');
    try {
        $files = Storage::disk('oci')->files();
        $this->info('✅ Bucket accessible, found ' . count($files) . ' files');
    } catch (Exception $e) {
        $this->error('❌ Bucket access failed: ' . $e->getMessage());
    }
}
```

### Log Analysis

```bash
# Monitor OCI logs in real-time
tail -f storage/logs/oci.log

# Search for errors
grep -i error storage/logs/oci.log

# Analyze performance
grep -i "duration" storage/logs/oci.log | awk '{print $NF}' | sort -n
```

### Network Debugging

```bash
# Enable curl debugging
export CURLOPT_VERBOSE=1

# Monitor network traffic
tcpdump -i any host objectstorage.us-phoenix-1.oraclecloud.com

# Check SSL handshake
openssl s_client -connect objectstorage.us-phoenix-1.oraclecloud.com:443
```

## Getting Additional Help

### Support Channels

1. **GitHub Issues**: [Report bugs](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
2. **GitHub Discussions**: [Community support](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)
3. **Documentation**: Check other guides in `/docs`

### When Reporting Issues

Include this information:

```bash
# System information
php --version
composer show mohamedhabibwork/laravel-oci-driver

# Error details
tail -50 storage/logs/laravel.log
tail -50 storage/logs/oci.log

# Configuration (remove sensitive data)
php artisan config:show filesystems.disks.oci | grep -v -E "(key|secret|token)"

# Test results
php artisan oci:health --verbose
```

### Template for Bug Reports

```markdown
**Environment:**

-   PHP Version: 8.3.x
-   Laravel Version: 11.x
-   Package Version: x.x.x
-   OCI Region: us-phoenix-1
-   Operating System: Ubuntu 22.04

**Issue Description:**
Brief description of the problem

**Steps to Reproduce:**

1. Configure driver with...
2. Call method...
3. Error occurs...

**Expected Behavior:**
What should happen

**Actual Behavior:**
What actually happens with error messages

**Configuration:**
Relevant configuration without sensitive data

**Logs:**
Error logs and stack traces
```

---

**Need more help?** Check the [API Reference](API_REFERENCE.md) for detailed method documentation or the main [README](../README.md) for usage examples.
