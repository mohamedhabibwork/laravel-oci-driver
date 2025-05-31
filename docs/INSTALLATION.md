# Installation Guide

This guide provides detailed installation instructions for different environments and use cases.

## Table of Contents

-   [System Requirements](#system-requirements)
-   [Quick Installation](#quick-installation)
-   [Environment-Specific Installation](#environment-specific-installation)
-   [Docker Installation](#docker-installation)
-   [Production Deployment](#production-deployment)
-   [Troubleshooting](#troubleshooting)

## System Requirements

### Minimum Requirements

| Component    | Version | Required | Notes                    |
| ------------ | ------- | -------- | ------------------------ |
| **PHP**      | 8.2+    | ✅       | Modern language features |
| **Laravel**  | 11.0+   | ✅       | Filesystem compatibility |
| **Composer** | 2.0+    | ✅       | Package management       |
| **OpenSSL**  | Latest  | ✅       | Key generation and SSL   |
| **cURL**     | Latest  | ✅       | HTTP operations          |

### Recommended Requirements

| Component      | Version | Recommended | Benefits                        |
| -------------- | ------- | ----------- | ------------------------------- |
| **PHP**        | 8.3+    | ⭐          | Latest performance improvements |
| **Memory**     | 512MB+  | ⭐          | Large file operations           |
| **Disk Space** | 100MB+  | ⭐          | Temporary file storage          |

### PHP Extensions

**Required Extensions:**

```bash
# Check required extensions
php -m | grep -E "(openssl|curl|json|mbstring)"
```

**Optional Extensions (Recommended):**

```bash
# Enhanced functionality
php -m | grep -E "(fileinfo|intl|redis)"
```

## Quick Installation

### 1. Install Package

```bash
composer require mohamedhabibwork/laravel-oci-driver
```

### 2. Run Setup Command

```bash
# Interactive setup with key generation
php artisan oci:setup --generate-keys

# Follow the prompts to configure your OCI credentials
```

### 3. Configure Environment

```bash
# Edit your .env file with OCI credentials
nano .env
```

### 4. Test Installation

```bash
# Validate configuration
php artisan oci:config --validate

# Run basic tests
php artisan oci:test
```

## Environment-Specific Installation

### Development Environment

```bash
# 1. Install package
composer require mohamedhabibwork/laravel-oci-driver

# 2. Generate development keys
php artisan oci:setup --generate-keys --auto-config

# 3. Configure for development
cp .env.example .env.development
```

**Development .env Configuration:**

```env
# Development OCI Configuration
OCI_TENANCY_OCID=ocid1.tenancy.oc1..aaaaaaaexample
OCI_USER_OCID=ocid1.user.oc1..aaaaaaaexample
OCI_FINGERPRINT=aa:bb:cc:dd:ee:ff:gg:hh:ii:jj:kk:ll:mm:nn:oo:pp
OCI_PRIVATE_KEY_PATH=.oci/dev_api_key.pem
OCI_REGION=us-phoenix-1
OCI_NAMESPACE=dev-namespace
OCI_BUCKET=dev-bucket
OCI_PREFIX=dev/

# Development options
OCI_DEBUG=true
OCI_LOG_LEVEL=debug
```

### Staging Environment

```bash
# 1. Install package
composer require mohamedhabibwork/laravel-oci-driver --no-dev

# 2. Setup with staging credentials
php artisan oci:setup --connection=staging --auto-config
```

**Staging Configuration:**

```env
# Staging OCI Configuration
OCI_STAGING_TENANCY_OCID=ocid1.tenancy.oc1..stagingexample
OCI_STAGING_USER_OCID=ocid1.user.oc1..stagingexample
OCI_STAGING_FINGERPRINT=bb:cc:dd:ee:ff:gg:hh:ii:jj:kk:ll:mm:nn:oo:pp
OCI_STAGING_PRIVATE_KEY_PATH=.oci/staging_api_key.pem
OCI_STAGING_REGION=us-ashburn-1
OCI_STAGING_NAMESPACE=staging-namespace
OCI_STAGING_BUCKET=staging-bucket
OCI_STAGING_PREFIX=staging/
```

### Production Environment

```bash
# 1. Install package (production only)
composer require mohamedhabibwork/laravel-oci-driver --no-dev --optimize-autoloader

# 2. Setup with production security
php artisan oci:setup --connection=production --path=/secure/.oci
```

**Production Security Checklist:**

-   [ ] Use dedicated OCI user with minimal permissions
-   [ ] Store private keys in secure location (not in web root)
-   [ ] Enable SSL verification
-   [ ] Configure proper logging
-   [ ] Set up monitoring and alerting
-   [ ] Regular key rotation schedule

## Docker Installation

### Dockerfile Configuration

```dockerfile
FROM php:8.3-fpm

# Install required extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libssl-dev \
    && docker-php-ext-install curl \
    && docker-php-ext-enable openssl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application
WORKDIR /var/www
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Create OCI directory with proper permissions
RUN mkdir -p .oci && chmod 700 .oci

# Copy OCI configuration
COPY .oci/config .oci/config
COPY .oci/*.pem .oci/
RUN chmod 600 .oci/*

EXPOSE 9000
CMD ["php-fpm"]
```

### Docker Compose Setup

```yaml
version: "3.8"

services:
    app:
        build: .
        volumes:
            - ./.oci:/var/www/.oci:ro
        environment:
            - OCI_TENANCY_OCID=${OCI_TENANCY_OCID}
            - OCI_USER_OCID=${OCI_USER_OCID}
            - OCI_FINGERPRINT=${OCI_FINGERPRINT}
            - OCI_PRIVATE_KEY_PATH=.oci/oci_api_key.pem
            - OCI_REGION=${OCI_REGION}
            - OCI_NAMESPACE=${OCI_NAMESPACE}
            - OCI_BUCKET=${OCI_BUCKET}
        depends_on:
            - redis
            - mysql

    redis:
        image: redis:alpine

    mysql:
        image: mysql:8.0
        environment:
            MYSQL_ROOT_PASSWORD: secret
            MYSQL_DATABASE: laravel
```

### Docker Secrets (Production)

```yaml
# docker-compose.prod.yml
version: "3.8"

services:
    app:
        build: .
        secrets:
            - oci_private_key
            - oci_config
        environment:
            - OCI_PRIVATE_KEY_PATH=/run/secrets/oci_private_key
            - OCI_CONFIG_PATH=/run/secrets/oci_config

secrets:
    oci_private_key:
        file: ./secrets/oci_private_key.pem
    oci_config:
        file: ./secrets/oci_config
```

## Production Deployment

### Prerequisites Checklist

-   [ ] **OCI Account Setup**

    -   [ ] Valid Oracle Cloud account
    -   [ ] Proper compartment access
    -   [ ] Object Storage bucket created
    -   [ ] IAM user with API keys

-   [ ] **Server Requirements**

    -   [ ] PHP 8.2+ installed
    -   [ ] Required extensions enabled
    -   [ ] Proper file permissions
    -   [ ] SSL certificates configured

-   [ ] **Security Setup**
    -   [ ] Private keys secured
    -   [ ] Environment variables configured
    -   [ ] Firewall rules applied
    -   [ ] Monitoring enabled

### Deployment Steps

#### 1. Prepare Production Server

```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y php8.3 php8.3-fpm php8.3-curl php8.3-openssl php8.3-mbstring

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

#### 2. Deploy Application

```bash
# Clone repository
git clone https://github.com/your-org/your-app.git /var/www/your-app
cd /var/www/your-app

# Install dependencies
composer install --no-dev --optimize-autoloader

# Set permissions
sudo chown -R www-data:www-data /var/www/your-app
sudo chmod -R 755 /var/www/your-app
sudo chmod -R 775 storage bootstrap/cache
```

#### 3. Configure OCI

```bash
# Create secure OCI directory
sudo mkdir -p /etc/oci
sudo chmod 700 /etc/oci

# Copy OCI configuration
sudo cp oci-config /etc/oci/config
sudo cp oci-private-key.pem /etc/oci/key.pem
sudo chmod 600 /etc/oci/*
sudo chown www-data:www-data /etc/oci/*
```

#### 4. Environment Configuration

```bash
# Copy production environment
cp .env.production .env

# Set OCI paths
echo "OCI_PRIVATE_KEY_PATH=/etc/oci/key.pem" >> .env
echo "OCI_CONFIG_PATH=/etc/oci/config" >> .env

# Generate application key
php artisan key:generate
```

#### 5. Validate Deployment

```bash
# Test OCI connection
php artisan oci:config --validate

# Run health checks
php artisan oci:health

# Test file operations
php artisan oci:test --connection=production
```

### Zero-Downtime Deployment

```bash
#!/bin/bash
# deploy.sh - Zero-downtime deployment script

set -e

APP_DIR="/var/www/your-app"
RELEASE_DIR="/var/www/releases/$(date +%Y%m%d_%H%M%S)"
SHARED_DIR="/var/www/shared"

echo "Creating new release directory..."
mkdir -p $RELEASE_DIR

echo "Downloading and extracting release..."
tar -xzf release.tar.gz -C $RELEASE_DIR

echo "Setting up shared resources..."
ln -nfs $SHARED_DIR/.env $RELEASE_DIR/.env
ln -nfs $SHARED_DIR/storage $RELEASE_DIR/storage
ln -nfs $SHARED_DIR/.oci $RELEASE_DIR/.oci

echo "Installing dependencies..."
cd $RELEASE_DIR
composer install --no-dev --optimize-autoloader

echo "Running migrations..."
php artisan migrate --force

echo "Validating OCI connection..."
php artisan oci:config --validate

echo "Switching to new release..."
ln -nfs $RELEASE_DIR $APP_DIR

echo "Reloading PHP-FPM..."
sudo systemctl reload php8.3-fpm

echo "Deployment completed successfully!"

# Cleanup old releases (keep last 5)
ls -t /var/www/releases | tail -n +6 | xargs -r rm -rf
```

## Monitoring and Logging

### Production Logging Setup

```php
// config/logging.php
'channels' => [
    'oci' => [
        'driver' => 'daily',
        'path' => storage_path('logs/oci.log'),
        'level' => env('OCI_LOG_LEVEL', 'info'),
        'days' => 14,
        'permission' => 0644,
    ],

    'oci-errors' => [
        'driver' => 'daily',
        'path' => storage_path('logs/oci-errors.log'),
        'level' => 'error',
        'days' => 30,
    ],
],
```

### Health Check Endpoint

```php
// routes/web.php
Route::get('/health/oci', function () {
    try {
        $oci = Storage::disk('oci');
        $testFile = 'health-check-' . time() . '.txt';

        // Test write
        $oci->put($testFile, 'Health check at ' . now());

        // Test read
        $content = $oci->get($testFile);

        // Test delete
        $oci->delete($testFile);

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now(),
            'operations' => ['write', 'read', 'delete']
        ]);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
            'timestamp' => now()
        ], 503);
    }
});
```

## Troubleshooting

### Common Installation Issues

#### 1. Composer Installation Fails

**Error:** `Package not found`

```bash
# Clear Composer cache
composer clear-cache

# Update Composer
composer self-update

# Retry installation
composer require mohamedhabibwork/laravel-oci-driver
```

#### 2. PHP Extension Missing

**Error:** `openssl extension is required`

```bash
# Ubuntu/Debian
sudo apt install php8.3-openssl

# CentOS/RHEL
sudo yum install php-openssl

# Restart web server
sudo systemctl restart apache2  # or nginx
```

#### 3. Permission Denied

**Error:** `Permission denied when creating .oci directory`

```bash
# Fix directory permissions
sudo chown -R $USER:$USER .
chmod 755 .

# Create OCI directory manually
mkdir .oci
chmod 700 .oci
```

#### 4. Key Generation Fails

**Error:** `OpenSSL not found in PATH`

```bash
# Ubuntu/Debian
sudo apt install openssl

# macOS
brew install openssl

# Windows (use Git Bash or WSL)
# Or download OpenSSL for Windows
```

### Validation Commands

```bash
# Check PHP version and extensions
php -v
php -m | grep -E "(openssl|curl|json)"

# Validate OCI configuration
php artisan oci:config --validate

# Test connection with debug output
php artisan oci:test --debug

# Check file permissions
ls -la .oci/
```

### Getting Help

If you encounter issues not covered here:

1. **Check the main README** for additional troubleshooting
2. **Search existing issues** on GitHub
3. **Create a new issue** with:
    - Environment details
    - Error messages
    - Steps to reproduce
    - Configuration (without sensitive data)

### Support Channels

-   **GitHub Issues**: [Report bugs and feature requests](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
-   **GitHub Discussions**: [Community support and questions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)
-   **Email Support**: [security@mohamedhabib.work](mailto:security@mohamedhabib.work) (security issues only)

---

**Next Steps**: After successful installation, see the [Configuration Guide](CONFIGURATION.md) for detailed setup instructions.
