# Authentication Setup

This guide explains how to set up OCI authentication and manage API keys for the Laravel OCI Driver.

---

## Introduction

The Laravel OCI Driver supports multiple authentication methods through key providers. Authentication requires an OCI API signing key pair and proper configuration of your tenancy, user, and key fingerprint.

---

## Key Provider Types

### 1. File-Based Key Provider (Recommended)

Stores the private key in a file on the filesystem.

**Configuration:**
```php
'oci' => [
    'driver' => 'oci',
    'key_provider' => 'file', // or omit (default)
    'key_path' => env('OCI_PRIVATE_KEY_PATH', '.oci/api_key.pem'),
    'tenancy_id' => env('OCI_TENANCY_OCID'),
    'user_id' => env('OCI_USER_OCID'),
    'key_fingerprint' => env('OCI_FINGERPRINT'),
    // ... other config
],
```

**Environment Variables:**
```env
OCI_PRIVATE_KEY_PATH=.oci/api_key.pem
OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99
```

### 2. Environment-Based Key Provider

Stores the private key content directly in environment variables (useful for containers).

**Configuration:**
```php
'oci' => [
    'driver' => 'oci',
    'key_provider' => 'environment',
    'private_key_content' => env('OCI_PRIVATE_KEY_CONTENT'),
    'tenancy_id' => env('OCI_TENANCY_OCID'),
    'user_id' => env('OCI_USER_OCID'),
    'key_fingerprint' => env('OCI_FINGERPRINT'),
    // ... other config
],
```

**Environment Variables:**
```env
OCI_PRIVATE_KEY_CONTENT="-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKB
... (your private key content)
-----END PRIVATE KEY-----"
OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99
```

**Base64 Encoded (Alternative):**
```env
OCI_PRIVATE_KEY_BASE64=LS0tLS1CRUdJTiBQUklWQVRFIEtFWS0tLS0t...
```

### 3. Custom Key Provider

Implement your own key provider for advanced use cases.

**Create Custom Provider:**
```php
<?php

namespace App\OCI;

use LaravelOCI\LaravelOciDriver\KeyProvider\KeyProviderInterface;

class VaultKeyProvider implements KeyProviderInterface
{
    public function __construct(
        private string $vaultPath,
        private string $tenancyId,
        private string $userId,
        private string $fingerprint
    ) {}

    public function getPrivateKey(): string
    {
        // Fetch from HashiCorp Vault, AWS Secrets Manager, etc.
        return $this->fetchFromVault($this->vaultPath);
    }

    public function getKeyId(): string
    {
        return sprintf('%s/%s/%s', $this->tenancyId, $this->userId, $this->fingerprint);
    }

    private function fetchFromVault(string $path): string
    {
        // Implementation to fetch from your secret store
    }
}
```

**Register Custom Provider:**
```php
// In AppServiceProvider
use App\OCI\VaultKeyProvider;

public function register()
{
    $this->app->bind('oci.key_provider', function () {
        return new VaultKeyProvider(
            config('oci.vault_path'),
            config('oci.tenancy_id'),
            config('oci.user_id'),
            config('oci.key_fingerprint')
        );
    });
}
```

---

## Setting Up API Keys

### Step 1: Generate API Key Pair

**Using OCI Setup Command (Recommended):**
```bash
php artisan oci:setup --generate-keys
```

**Manual Generation:**
```bash
# Generate private key
openssl genrsa -out .oci/api_key.pem 2048

# Generate public key
openssl rsa -pubout -in .oci/api_key.pem -out .oci/api_key_public.pem

# Set proper permissions
chmod 600 .oci/api_key.pem
chmod 644 .oci/api_key_public.pem
```

### Step 2: Upload Public Key to OCI Console

1. Log into OCI Console
2. Navigate to Identity & Security → Users
3. Select your user
4. Click "API Keys" in the left menu
5. Click "Add API Key"
6. Upload or paste your public key content
7. Copy the fingerprint provided

### Step 3: Get Required OCIDs

**Tenancy OCID:**
- Profile menu → Tenancy → Copy OCID

**User OCID:**
- Profile menu → User Settings → Copy OCID

**Region:**
- Check your current region in the console header

**Namespace:**
```bash
# Via OCI CLI
oci os ns get

# Or check in Object Storage → Buckets
```

---

## Key Generation Examples

### Using the OCI Setup Command

```bash
# Interactive setup
php artisan oci:setup

# Auto-generate keys with existing config
php artisan oci:setup --generate-keys --auto-config

# Custom path
php artisan oci:setup --path=/secure/.oci --generate-keys

# Force overwrite existing keys
php artisan oci:setup --generate-keys --force
```

### Manual Key Generation with Specific Parameters

```bash
# Generate 2048-bit RSA key
openssl genrsa -out .oci/api_key.pem 2048

# Generate 4096-bit RSA key (more secure)
openssl genrsa -out .oci/api_key.pem 4096

# Generate key with passphrase
openssl genrsa -aes256 -out .oci/api_key.pem 2048

# Extract public key
openssl rsa -in .oci/api_key.pem -pubout -out .oci/api_key_public.pem

# Calculate fingerprint
openssl rsa -pubout -outform DER -in .oci/api_key.pem | openssl md5 -c
```

---

## Security Best Practices

### File Permissions
```bash
# Secure the .oci directory
chmod 700 .oci/
chmod 600 .oci/api_key.pem
chmod 644 .oci/api_key_public.pem

# Verify permissions
ls -la .oci/
```

### Environment Variables
- Never commit private keys to version control
- Use encrypted environment variables in production
- Rotate keys regularly (every 90 days recommended)
- Use different keys for different environments

### Key Storage
```bash
# Store keys outside web root (production)
mkdir -p /secure/oci-keys
chmod 700 /secure/oci-keys
mv .oci/api_key.pem /secure/oci-keys/
```

### .gitignore
```gitignore
# OCI Keys
.oci/
*.pem
*.key
```

---

## Troubleshooting Authentication Issues

### Invalid API Key or Signature

**Check fingerprint:**
```bash
# Calculate fingerprint from your key
openssl rsa -pubout -outform DER -in .oci/api_key.pem | openssl md5 -c

# Compare with OCI Console
```

**Validate key format:**
```bash
# Check if key is valid
openssl rsa -in .oci/api_key.pem -check -noout

# View key details
openssl rsa -in .oci/api_key.pem -text -noout
```

### Permission Denied

**Fix file permissions:**
```bash
chmod 600 .oci/api_key.pem
chown $USER:$USER .oci/api_key.pem
```

### Key Provider Not Found

**Check configuration:**
```php
// Ensure key_provider is set correctly
'key_provider' => 'file', // or 'environment'
```

### Environment Key Issues

**Test environment loading:**
```bash
# Check if environment variables are loaded
php -r "echo env('OCI_PRIVATE_KEY_CONTENT') ? 'Found' : 'Not found';"
```

---

## Validation Commands

```bash
# Validate configuration
php artisan oci:config --validate

# Test connection
php artisan oci:status

# Debug authentication
php artisan oci:connection test --debug
```

---

## Examples

### Complete File-Based Setup

```bash
# 1. Generate keys
php artisan oci:setup --generate-keys

# 2. Configure environment
cat >> .env << EOF
OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99
OCI_PRIVATE_KEY_PATH=.oci/api_key.pem
OCI_REGION=us-phoenix-1
OCI_NAMESPACE=my-namespace
OCI_BUCKET=my-bucket
EOF

# 3. Test connection
php artisan oci:config --validate
```

### Container-Based Setup (Environment Provider)

```dockerfile
# Dockerfile
FROM php:8.2-fpm

# Copy application
COPY . /var/www/html

# Set environment variables
ENV OCI_PRIVATE_KEY_CONTENT="-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKB\n-----END PRIVATE KEY-----"
ENV OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
ENV OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
ENV OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99
```

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Installation Guide](INSTALLATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Security Guide](SECURITY.md) 