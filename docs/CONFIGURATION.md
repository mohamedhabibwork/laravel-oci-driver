# Configuration Guide

This guide provides a comprehensive reference for all configuration options available in the Laravel OCI Driver.

---

## Introduction

The Laravel OCI Driver requires several configuration options to connect to Oracle Cloud Infrastructure Object Storage. Configuration can be set via Laravel's `config/filesystems.php`, the package config, and environment variables.

---

## Required Configuration Options

| Key                | Description                                      | Example Value                      |
|--------------------|--------------------------------------------------|------------------------------------|
| `driver`           | Filesystem driver name (must be `oci`)           | `oci`                              |
| `namespace`        | OCI Object Storage namespace                     | `my-namespace`                     |
| `region`           | OCI region code                                  | `us-phoenix-1`                     |
| `bucket`           | OCI bucket name                                  | `my-bucket`                        |
| `tenancy_id`       | OCI Tenancy OCID                                 | `ocid1.tenancy.oc1..xxxx`          |
| `user_id`          | OCI User OCID                                    | `ocid1.user.oc1..xxxx`             |
| `storage_tier`     | Storage tier (`Standard`, `InfrequentAccess`, `Archive`) | `Standard`                  |
| `key_fingerprint`  | API key fingerprint                              | `aa:bb:cc:...:pp`                  |
| `key_path`         | Path to private key file or env var content       | `.oci/api_key.pem`                 |

---

## Optional Configuration Options

| Key                | Description                                      | Example Value                      |
|--------------------|--------------------------------------------------|------------------------------------|
| `prefix`           | Prefix for all stored files                      | `backups/`                         |
| `url`              | Custom endpoint URL                              |                                    |
| `passphrase`       | Passphrase for private key (if encrypted)        |                                    |
| `options`          | Advanced options (timeouts, retries, etc.)       | See below                          |
| `debug`            | Enable debug logging                             | `true`                             |
| `log_level`        | Log verbosity                                    | `info`                             |
| `log_channel`      | Laravel log channel                              | `default`                          |

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
OCI_TENANCY_OCID=ocid1.tenancy.oc1..xxxx
OCI_USER_OCID=ocid1.user.oc1..xxxx
OCI_STORAGE_TIER=Standard
OCI_FINGERPRINT=aa:bb:cc:...:pp
OCI_PRIVATE_KEY_PATH=.oci/api_key.pem
OCI_DEBUG=false
OCI_LOG_LEVEL=info
```

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