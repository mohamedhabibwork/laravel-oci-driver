# Configuration Guide

This guide provides a comprehensive reference for all configuration options available in the Laravel OCI Driver.

---

## Introduction

The Laravel OCI Driver requires several configuration options to connect to Oracle Cloud Infrastructure Object Storage. Configuration can be set via Laravel's `config/filesystems.php`, the package config, and environment variables.

---

## Configuration Options

| Option             | Description                                      | Example                            |
|--------------------|--------------------------------------------------|------------------------------------|
| `driver`           | Storage driver (must be 'oci')                  | `oci`                              |
| `tenancy_id`       | Oracle Cloud tenancy OCID                       | `ocid1.tenancy.oc1..abcd...`       |
| `user_id`          | Oracle Cloud user OCID                          | `ocid1.user.oc1..efgh...`          |
| `key_fingerprint`  | API key fingerprint                              | `aa:bb:cc:dd:ee:ff:...`            |
| `key_path`         | Path to private key file                         | `/path/to/private-key.pem`         |
| `namespace`        | Object Storage namespace                         | `my-namespace`                     |
| `region`           | OCI region identifier                            | `us-phoenix-1`                     |
| `bucket`           | Object Storage bucket name                       | `my-bucket`                        |
| `storage_tier`     | Default storage tier                             | `Standard`                         |
| `timeout`          | Request timeout in seconds                       | `30`                               |
| `connect_timeout`  | Connection timeout in seconds                    | `10`                               |
| `retry_attempts`   | Number of retry attempts                         | `3`                                |
| `retry_delay`      | Delay between retries (milliseconds)             | `1000`                             |
| `prefix`           | Prefix for all stored files                      | `backups/`                         |
| `url`              | Custom endpoint URL                              |                                    |
| `passphrase`       | Passphrase for private key (if encrypted)        |                                    |
| `options`          | Advanced options (timeouts, retries, etc.)       | See below                          |
| `debug`            | Enable debug logging                             | `true`                             |
| `log_level`        | Log verbosity                                    | `info`                             |
| `log_channel`      | Laravel log channel                              | `default`                          |
| `url_path_prefix`  | URL path prefix for object paths                  | `my-prefix`                        |

## URL Path Prefix Configuration

The `url_path_prefix` option allows you to organize all files under a specific prefix in your OCI bucket. This feature is particularly useful for:

### Use Cases

1. **Multi-tenant Applications**: Isolate files for different tenants
   ```php
   'url_path_prefix' => 'tenant-' . auth()->user()->tenant_id,
   ```

2. **Environment Separation**: Separate files by environment
   ```php
   'url_path_prefix' => env('APP_ENV', 'production'),
   ```

3. **Application Organization**: Organize files by application or module
   ```php
   'url_path_prefix' => 'app-name/uploads',
   ```

4. **Version Control**: Separate files by API or application version
   ```php
   'url_path_prefix' => 'v1/files',
   ```

5. **Date-based Organization**: Organize files by date
   ```php
   'url_path_prefix' => 'backups/' . date('Y/m'),
   ```

### Prefix Behavior

- **Normalization**: Leading and trailing slashes are automatically normalized
- **Empty Values**: Empty string or null disables prefixing
- **Path Handling**: All file operations automatically apply the prefix

### Examples

```php
// Basic prefix
'url_path_prefix' => 'uploads'
// Files stored as: uploads/file.txt, uploads/images/photo.jpg

// Nested prefix
'url_path_prefix' => 'app/documents'
// Files stored as: app/documents/file.txt, app/documents/reports/report.pdf

// Dynamic prefix (configure in service provider)
'url_path_prefix' => config('app.name') . '/' . config('app.env')
// Files stored as: myapp/production/file.txt

// Date-based prefix
'url_path_prefix' => 'backups/' . date('Y/m/d')
// Files stored as: backups/2024/03/15/backup.sql
```

### Prefix Normalization

The system automatically normalizes prefixes:

```php
'url_path_prefix' => 'uploads'      // Becomes: uploads/
'url_path_prefix' => '/uploads'     // Becomes: uploads/
'url_path_prefix' => 'uploads/'     // Becomes: uploads/
'url_path_prefix' => '/uploads/'    // Becomes: uploads/
'url_path_prefix' => ''             // No prefix applied
'url_path_prefix' => null           // No prefix applied
```

### Advanced Usage

For dynamic prefixes, you can configure them in your service provider:

```php
// In AppServiceProvider::boot()
public function boot()
{
    // Dynamic tenant-based prefix
    if (auth()->check()) {
        config(['filesystems.disks.oci.url_path_prefix' => 'tenant-' . auth()->user()->tenant_id]);
    }
    
    // Environment-based prefix
    config(['filesystems.disks.oci.url_path_prefix' => config('app.env') . '/files']);
}
```

**Advanced `options` example:**
```php
'options' => [
    'timeout' => 30,
    'connect_timeout' => 10,
    'retry_max' => 3,
    'chunk_size' => 8388608, // 8MB
    'verify_ssl' => true,
    'connection_pool_size' => 10,
    'parallel_uploads' => 4,
    'cache_metadata' => true,
    'cache_ttl' => 3600,
],
```

---

## Example Configuration

**config/filesystems.php**
```php
'oci' => [
    'driver' => 'oci',
    'namespace' => env('OCI_NAMESPACE'),
    'region' => env('OCI_REGION'),
    'bucket' => env('OCI_BUCKET'),
    'tenancy_id' => env('OCI_TENANCY_OCID'),
    'user_id' => env('OCI_USER_OCID'),
    'storage_tier' => env('OCI_STORAGE_TIER', 'Standard'),
    'key_fingerprint' => env('OCI_FINGERPRINT'),
    'key_path' => env('OCI_PRIVATE_KEY_PATH'),
    'url_path_prefix' => env('OCI_PREFIX', ''), // Optional prefix
    'options' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry_max' => 3,
        'chunk_size' => 8388608,
    ],
    'debug' => env('OCI_DEBUG', false),
    'log_level' => env('OCI_LOG_LEVEL', 'info'),
],
```

**.env**
```env
OCI_NAMESPACE=my-namespace
OCI_REGION=us-phoenix-1
OCI_BUCKET=my-bucket
OCI_TENANCY_OCID=ocid1.tenancy.oc1..abcd...
OCI_USER_OCID=ocid1.user.oc1..efgh...
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:...
OCI_PRIVATE_KEY_PATH=/path/to/private-key.pem
OCI_STORAGE_TIER=Standard
OCI_PREFIX=uploads
OCI_DEBUG=false
OCI_LOG_LEVEL=info
```

## Multi-Environment Configuration

You can configure different prefixes for different environments:

```php
// config/filesystems.php
'oci' => [
    // ... other config
    'url_path_prefix' => env('OCI_PREFIX', config('app.env')),
],

// Different .env files
// .env.production
OCI_PREFIX=production

// .env.staging  
OCI_PREFIX=staging

// .env.development
OCI_PREFIX=development
```

## Multi-Tenant Configuration

For multi-tenant applications, you can set dynamic prefixes:

```php
// In a service provider or middleware
public function handle($request, Closure $next)
{
    if (auth()->check()) {
        $tenantId = auth()->user()->tenant_id;
        config(['filesystems.disks.oci.url_path_prefix' => "tenant-{$tenantId}"]);
    }
    
    return $next($request);
}
```

This ensures all file operations for the authenticated user are automatically prefixed with their tenant ID, providing complete isolation between tenants.

---

## Environment Variable Mapping

| Config Key         | Environment Variable      |
|--------------------|--------------------------|
| `namespace`        | `OCI_NAMESPACE`          |
| `region`           | `OCI_REGION`             |
| `bucket`           | `OCI_BUCKET`             |
| `tenancy_id`       | `OCI_TENANCY_OCID`       |
| `user_id`          | `OCI_USER_OCID`          |
| `storage_tier`     | `OCI_STORAGE_TIER`       |
| `key_fingerprint`  | `OCI_FINGERPRINT`        |
| `key_path`         | `OCI_PRIVATE_KEY_PATH`   |
| `debug`            | `OCI_DEBUG`              |
| `log_level`        | `OCI_LOG_LEVEL`          |

---

## Advanced Configuration Options

### Performance Tuning

```php
'oci' => [
    'driver' => 'oci',
    // ... basic config
    'options' => [
        // Connection settings
        'timeout' => 60,                    // Request timeout in seconds
        'connect_timeout' => 30,            // Connection timeout in seconds
        'read_timeout' => 300,              // Read timeout for large files
        'write_timeout' => 300,             // Write timeout for uploads
        
        // Retry settings
        'retry_max' => 5,                   // Maximum retry attempts
        'retry_delay' => 1000,              // Delay between retries (milliseconds)
        'retry_exponential' => true,        // Use exponential backoff
        
        // Transfer settings
        'chunk_size' => 8388608,            // 8MB chunks for multipart uploads
        'multipart_threshold' => 104857600, // 100MB threshold for multipart
        'max_concurrent_requests' => 10,    // Maximum concurrent requests
        
        // Caching
        'cache_metadata' => true,           // Cache file metadata
        'cache_ttl' => 3600,               // Cache TTL in seconds
        'cache_prefix' => 'oci_metadata',   // Cache key prefix
        
        // SSL settings
        'verify_ssl' => true,               // Verify SSL certificates
        'ssl_cert' => null,                 // Custom SSL certificate path
        'ssl_key' => null,                  // Custom SSL key path
        'ssl_ca' => null,                   // Custom CA bundle path
    ],
],
```

### Logging Configuration

```php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'logging' => [
        'enabled' => env('OCI_LOGGING_ENABLED', true),
        'level' => env('OCI_LOG_LEVEL', 'info'),
        'channel' => env('OCI_LOG_CHANNEL', 'oci'),
        'log_requests' => env('OCI_LOG_REQUESTS', false),
        'log_responses' => env('OCI_LOG_RESPONSES', false),
        'log_metadata' => env('OCI_LOG_METADATA', true),
    ],
],
```

### Health Check Configuration

```php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'health_check' => [
        'enabled' => env('OCI_HEALTH_CHECK_ENABLED', true),
        'test_file' => 'health-check.txt',
        'test_content' => 'Laravel OCI Driver Health Check',
        'timeout' => 30,
        'cache_results' => true,
        'cache_ttl' => 300, // 5 minutes
    ],
],
```

---

## Connection Management

### Multiple Connection Configuration

```php
// config/filesystems.php
'disks' => [
    'oci_primary' => [
        'driver' => 'oci',
        'region' => 'us-phoenix-1',
        'bucket' => 'primary-storage',
        // ... other config
    ],
    
    'oci_backup' => [
        'driver' => 'oci',
        'region' => 'us-ashburn-1',
        'bucket' => 'backup-storage',
        'storage_tier' => 'Archive',
        // ... other config
    ],
    
    'oci_cdn' => [
        'driver' => 'oci',
        'region' => 'eu-frankfurt-1',
        'bucket' => 'cdn-assets',
        'visibility' => 'public',
        // ... other config
    ],
],
```

### Using Connection Manager

```php
use LaravelOCI\LaravelOciDriver\OciConnectionManager;

// Get connection manager
$manager = app(OciConnectionManager::class);

// Test all connections
$results = $manager->testAllConnections();

// Get specific connection
$primaryConfig = $manager->getConnection('oci_primary');

// Switch default connection
$manager->setDefaultConnection('oci_backup');
```

---

## Environment-Specific Configurations

### Development Environment

```php
// config/filesystems.php (development)
'oci' => [
    'driver' => 'oci',
    'bucket' => env('OCI_BUCKET', 'dev-bucket'),
    'url_path_prefix' => 'development',
    'debug' => true,
    'log_level' => 'debug',
    'options' => [
        'timeout' => 30,
        'retry_max' => 3,
        'cache_metadata' => false, // Disable caching in dev
    ],
],
```

### Production Environment

```php
// config/filesystems.php (production)
'oci' => [
    'driver' => 'oci',
    'bucket' => env('OCI_BUCKET'),
    'url_path_prefix' => env('OCI_PREFIX', ''),
    'debug' => false,
    'log_level' => 'warning',
    'options' => [
        'timeout' => 60,
        'retry_max' => 5,
        'cache_metadata' => true,
        'cache_ttl' => 3600,
        'multipart_threshold' => 100 * 1024 * 1024, // 100MB
        'max_concurrent_requests' => 20,
    ],
],
```

---

## Security Configuration

### Key Security

```php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'security' => [
        'key_rotation_days' => 90,          // Rotate keys every 90 days
        'encrypt_at_rest' => true,          // Enable server-side encryption
        'encryption_key' => env('OCI_ENCRYPTION_KEY'),
        'allowed_origins' => [              // CORS origins for public buckets
            'https://example.com',
            'https://www.example.com',
        ],
        'max_file_size' => 500 * 1024 * 1024, // 500MB max file size
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'text/plain',
        ],
    ],
],
```

### Access Control Configuration

```php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'access_control' => [
        'enable_pre_signed_urls' => true,
        'default_url_expiry' => 3600,       // 1 hour
        'max_url_expiry' => 86400,          // 24 hours
        'require_authentication' => true,
        'role_based_access' => [
            'admin' => ['read', 'write', 'delete'],
            'editor' => ['read', 'write'],
            'viewer' => ['read'],
        ],
    ],
],
```

---

## Configuration Validation

### Using Artisan Commands

```bash
# Validate current configuration
php artisan oci:config --validate

# Test connection with current config
php artisan oci:status

# Interactive configuration setup
php artisan oci:config

# Setup OCI environment (keys, config, etc.)
php artisan oci:setup
```

### Programmatic Validation

```php
use LaravelOCI\LaravelOciDriver\Services\ConfigValidationService;

$validator = app(ConfigValidationService::class);

// Validate all configurations
$results = $validator->validateAllConfigurations();

// Validate specific configuration
$result = $validator->validateConfiguration('oci');

if (!$result->isValid()) {
    foreach ($result->getErrors() as $error) {
        Log::error('OCI Configuration Error: ' . $error);
    }
}
```

---

## Best Practices

### Security Best Practices

- **Never commit private keys to version control**
- **Use environment variables for all sensitive configuration**
- **Set proper file permissions on private keys (600)**
- **Store private keys outside the web root**
- **Rotate keys regularly (every 90 days recommended)**
- **Use different buckets for different environments**
- **Enable server-side encryption for sensitive data**

### Performance Best Practices

- **Configure appropriate timeouts for your use case**
- **Use multipart uploads for large files (>100MB)**
- **Enable metadata caching in production**
- **Optimize chunk sizes based on your network**
- **Use CDN for frequently accessed public files**
- **Monitor and tune concurrent request limits**

### Operational Best Practices

- **Validate configuration in your deployment pipeline**
- **Use health checks to monitor OCI connectivity**
- **Set up proper logging and monitoring**
- **Document your configuration choices**
- **Test configuration changes in staging first**
- **Keep backup configurations for rollback**

---

## Troubleshooting Common Configuration Issues

### Authentication Issues

```bash
# Test authentication
php artisan oci:config --validate

# Check key file permissions
ls -la /path/to/private-key.pem

# Verify key fingerprint
openssl rsa -in /path/to/private-key.pem -pubout -outform DER | openssl md5 -c
```

### Connection Issues

- **Timeout errors**: Increase timeout values in options
- **SSL verification failures**: Check SSL certificate settings
- **Network connectivity**: Verify firewall and proxy settings
- **Region errors**: Ensure region matches your OCI setup

### Configuration Issues

- **Missing required config**: Use `oci:config` command to set missing values
- **Invalid region or fingerprint**: Double-check for typos and correct format
- **Key file not found**: Ensure path is correct and accessible
- **Permission denied**: Check file permissions and process ownership
- **Wrong storage tier**: Only `Standard`, `InfrequentAccess`, or `Archive` are valid

### Performance Issues

- **Slow uploads**: Adjust chunk size and concurrent requests
- **Memory issues**: Reduce chunk size for large files
- **Cache issues**: Clear metadata cache if experiencing stale data

---

## Configuration Examples

### Basic Development Setup

```bash
# .env
OCI_NAMESPACE=my-namespace
OCI_REGION=us-phoenix-1
OCI_BUCKET=dev-bucket
OCI_TENANCY_OCID=ocid1.tenancy.oc1..example
OCI_USER_OCID=ocid1.user.oc1..example
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:11:22:33:44:55:66:77:88:99:00
OCI_PRIVATE_KEY_PATH=/home/app/.oci/private-key.pem
OCI_PREFIX=development
OCI_DEBUG=true
OCI_LOG_LEVEL=debug
```

### Production Setup

```bash
# .env
OCI_NAMESPACE=production-namespace
OCI_REGION=us-phoenix-1
OCI_BUCKET=production-storage
OCI_TENANCY_OCID=ocid1.tenancy.oc1..production
OCI_USER_OCID=ocid1.user.oc1..production
OCI_FINGERPRINT=11:22:33:44:55:66:77:88:99:00:aa:bb:cc:dd:ee:ff
OCI_PRIVATE_KEY_PATH=/secure/keys/oci-production-key.pem
OCI_PREFIX=
OCI_DEBUG=false
OCI_LOG_LEVEL=warning
OCI_HEALTH_CHECK_ENABLED=true
OCI_LOGGING_ENABLED=true
```

---

## References

- [Installation Guide](INSTALLATION.md)
- [Authentication Setup](AUTHENTICATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [API Reference](API_REFERENCE.md)
- [Security Guide](SECURITY.md)
- [Performance Guide](PERFORMANCE.md) 