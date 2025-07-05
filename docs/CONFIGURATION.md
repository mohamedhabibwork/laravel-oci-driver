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

## Best Practices

- Use environment variables for all sensitive values.
- Store private keys outside the web root and with strict permissions.
- Use different buckets/namespaces for dev, staging, and production.
- Validate your configuration with `php artisan oci:config --validate`.
- Use the `oci:setup` command to generate and manage key files securely.

---

## Troubleshooting Common Configuration Issues

- **Missing required config**: Ensure all required keys are set in your config and .env.
- **Invalid region or fingerprint**: Double-check for typos and correct format.
- **Key file not found**: Make sure the path is correct and file permissions are set (600).
- **Permission denied**: The Laravel process must have read access to the key file.
- **Wrong storage tier**: Only `Standard`, `InfrequentAccess`, or `Archive` are valid.

---

## References

- [Installation Guide](INSTALLATION.md)
- [Authentication Setup](AUTHENTICATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [API Reference](API_REFERENCE.md) 