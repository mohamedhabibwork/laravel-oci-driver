<?php

declare(strict_types=1);

use LaravelOCI\LaravelOciDriver\Enums\StorageTier;

return [
    /*
    |--------------------------------------------------------------------------
    | Oracle Cloud Infrastructure (OCI) Object Storage Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the Laravel OCI Driver.
    | You can configure multiple OCI connections and set various options for
    | object storage operations.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default OCI Connection
    |--------------------------------------------------------------------------
    |
    | This option controls the default OCI connection that will be used by the
    | storage manager. This connection will be used when no specific connection
    | is specified when using the Storage facade.
    |
    */

    'default' => env('OCI_DEFAULT_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | OCI Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure multiple OCI connections for your application.
    | Each connection can have different credentials, regions, and buckets.
    |
    */

    'connections' => [
        'default' => [
            'tenancy_id' => env('OCI_TENANCY_ID'),
            'user_id' => env('OCI_USER_ID'),
            'key_fingerprint' => env('OCI_KEY_FINGERPRINT'),
            'key_path' => env('OCI_KEY_PATH'),
            'namespace' => env('OCI_NAMESPACE'),
            'region' => env('OCI_REGION'),
            'bucket' => env('OCI_BUCKET'),
            'storage_tier' => env('OCI_STORAGE_TIER', 'Standard'),
            'timeout' => env('OCI_TIMEOUT', 30),
            'connect_timeout' => env('OCI_CONNECT_TIMEOUT', 10),
            'retry_attempts' => env('OCI_RETRY_ATTEMPTS', 3),
            'retry_delay' => env('OCI_RETRY_DELAY', 1000), // milliseconds
            'temporary_url' => [
                'default_expiry' => env('OCI_TEMP_URL_EXPIRY', 3600), // seconds
                'max_expiry' => env('OCI_TEMP_URL_MAX_EXPIRY', 86400), // 24 hours
            ],
            'upload' => [
                'chunk_size' => env('OCI_UPLOAD_CHUNK_SIZE', 8388608), // 8MB
                'multipart_threshold' => env('OCI_MULTIPART_THRESHOLD', 104857600), // 100MB
                'enable_checksum' => env('OCI_ENABLE_CHECKSUM', true),
            ],
            'cache' => [
                'enabled' => env('OCI_CACHE_ENABLED', true),
                'ttl' => env('OCI_CACHE_TTL', 300), // 5 minutes
                'prefix' => env('OCI_CACHE_PREFIX', 'oci_driver'),
            ],
            'logging' => [
                'enabled' => env('OCI_LOGGING_ENABLED', false),
                'level' => env('OCI_LOG_LEVEL', 'info'),
                'channel' => env('OCI_LOG_CHANNEL', 'default'),
            ],
            'url_path_prefix' => env('OCI_URL_PATH_PREFIX', ''), // Optional: Prefix for all object paths

            /*
            |--------------------------------------------------------------------------
            | URL Path Prefix Configuration
            |--------------------------------------------------------------------------
            |
            | The url_path_prefix option allows you to organize all files under a
            | specific prefix in your OCI bucket. This is useful for:
            |
            | - Multi-tenant applications (e.g., 'tenant-123/')
            | - Environment separation (e.g., 'staging/', 'production/')
            | - Application organization (e.g., 'app-name/uploads/')
            | - Version control (e.g., 'v1/', 'v2/')
            |
            | Examples:
            | - 'uploads' -> All files stored under 'uploads/' prefix
            | - 'app/documents' -> Files stored under 'app/documents/' prefix
            | - 'tenant-' . auth()->user()->tenant_id -> Dynamic tenant prefixes
            | - env('APP_NAME') . '/files' -> Environment-based prefixes
            |
            | Note: Leading and trailing slashes are automatically normalized.
            | Empty string or null disables prefixing.
            |
            */
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Production Connection with Prefix
        |--------------------------------------------------------------------------
        |
        | This example shows a production connection with a prefix for organizing
        | files in a structured way.
        |
        */

        'production' => [
            'tenancy_id' => env('OCI_PROD_TENANCY_ID'),
            'user_id' => env('OCI_PROD_USER_ID'),
            'key_fingerprint' => env('OCI_PROD_KEY_FINGERPRINT'),
            'key_path' => env('OCI_PROD_KEY_PATH'),
            'namespace' => env('OCI_PROD_NAMESPACE'),
            'region' => env('OCI_PROD_REGION'),
            'bucket' => env('OCI_PROD_BUCKET'),
            'storage_tier' => env('OCI_PROD_STORAGE_TIER', 'Standard'),
            'url_path_prefix' => env('OCI_PROD_PREFIX', 'production'), // Organize production files
            'timeout' => env('OCI_PROD_TIMEOUT', 60),
            'retry_attempts' => env('OCI_PROD_RETRY_ATTEMPTS', 5),
            'cache' => [
                'enabled' => env('OCI_PROD_CACHE_ENABLED', true),
                'ttl' => env('OCI_PROD_CACHE_TTL', 600), // 10 minutes
                'prefix' => env('OCI_PROD_CACHE_PREFIX', 'oci_prod'),
            ],
            'logging' => [
                'enabled' => env('OCI_PROD_LOGGING_ENABLED', true),
                'level' => env('OCI_PROD_LOG_LEVEL', 'warning'),
                'channel' => env('OCI_PROD_LOG_CHANNEL', 'production'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Multi-Tenant Connection
        |--------------------------------------------------------------------------
        |
        | This example shows how to configure dynamic prefixes for multi-tenant
        | applications where each tenant's files are isolated.
        |
        */

        'tenant' => [
            'tenancy_id' => env('OCI_TENANT_TENANCY_ID'),
            'user_id' => env('OCI_TENANT_USER_ID'),
            'key_fingerprint' => env('OCI_TENANT_KEY_FINGERPRINT'),
            'key_path' => env('OCI_TENANT_KEY_PATH'),
            'namespace' => env('OCI_TENANT_NAMESPACE'),
            'region' => env('OCI_TENANT_REGION'),
            'bucket' => env('OCI_TENANT_BUCKET'),
            'storage_tier' => env('OCI_TENANT_STORAGE_TIER', 'Standard'),
            // Dynamic prefix based on tenant - configure in your service provider
            'url_path_prefix' => env('OCI_TENANT_PREFIX', 'tenants'), // Base prefix for all tenants
            'timeout' => env('OCI_TENANT_TIMEOUT', 30),
            'cache' => [
                'enabled' => env('OCI_TENANT_CACHE_ENABLED', true),
                'ttl' => env('OCI_TENANT_CACHE_TTL', 300),
                'prefix' => env('OCI_TENANT_CACHE_PREFIX', 'oci_tenant'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Development Connection
        |--------------------------------------------------------------------------
        |
        | Development connection with prefix for isolating development files.
        |
        */

        'development' => [
            'tenancy_id' => env('OCI_DEV_TENANCY_ID'),
            'user_id' => env('OCI_DEV_USER_ID'),
            'key_fingerprint' => env('OCI_DEV_KEY_FINGERPRINT'),
            'key_path' => env('OCI_DEV_KEY_PATH'),
            'namespace' => env('OCI_DEV_NAMESPACE'),
            'region' => env('OCI_DEV_REGION'),
            'bucket' => env('OCI_DEV_BUCKET'),
            'storage_tier' => env('OCI_DEV_STORAGE_TIER', 'Standard'),
            'url_path_prefix' => env('OCI_DEV_PREFIX', 'development'), // Isolate dev files
            'timeout' => env('OCI_DEV_TIMEOUT', 15),
            'retry_attempts' => env('OCI_DEV_RETRY_ATTEMPTS', 2),
            'cache' => [
                'enabled' => env('OCI_DEV_CACHE_ENABLED', false), // Disable cache in dev
                'ttl' => env('OCI_DEV_CACHE_TTL', 60),
                'prefix' => env('OCI_DEV_CACHE_PREFIX', 'oci_dev'),
            ],
            'logging' => [
                'enabled' => env('OCI_DEV_LOGGING_ENABLED', true),
                'level' => env('OCI_DEV_LOG_LEVEL', 'debug'),
                'channel' => env('OCI_DEV_LOG_CHANNEL', 'single'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Backup Connection
        |--------------------------------------------------------------------------
        |
        | Backup connection with archive storage tier and organized prefixes.
        |
        */

        'backup' => [
            'tenancy_id' => env('OCI_BACKUP_TENANCY_ID'),
            'user_id' => env('OCI_BACKUP_USER_ID'),
            'key_fingerprint' => env('OCI_BACKUP_KEY_FINGERPRINT'),
            'key_path' => env('OCI_BACKUP_KEY_PATH'),
            'namespace' => env('OCI_BACKUP_NAMESPACE'),
            'region' => env('OCI_BACKUP_REGION'),
            'bucket' => env('OCI_BACKUP_BUCKET'),
            'storage_tier' => env('OCI_BACKUP_STORAGE_TIER', 'Archive'), // Cost-effective for backups
            'url_path_prefix' => env('OCI_BACKUP_PREFIX', 'backups/'.date('Y/m')), // Organize by date
            'timeout' => env('OCI_BACKUP_TIMEOUT', 120), // Longer timeout for large backups
            'retry_attempts' => env('OCI_BACKUP_RETRY_ATTEMPTS', 3),
            'cache' => [
                'enabled' => env('OCI_BACKUP_CACHE_ENABLED', false), // No cache for backups
            ],
            'logging' => [
                'enabled' => env('OCI_BACKUP_LOGGING_ENABLED', true),
                'level' => env('OCI_BACKUP_LOG_LEVEL', 'info'),
                'channel' => env('OCI_BACKUP_LOG_CHANNEL', 'backup'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Settings
    |--------------------------------------------------------------------------
    |
    | These settings apply to all OCI connections unless overridden in the
    | specific connection configuration.
    |
    */

    'global' => [
        /*
        |--------------------------------------------------------------------------
        | Error Handling
        |--------------------------------------------------------------------------
        |
        | Configure how errors are handled across all OCI operations.
        |
        */

        'throw_exceptions' => env('OCI_THROW_EXCEPTIONS', true),
        'log_errors' => env('OCI_LOG_ERRORS', true),

        /*
        |--------------------------------------------------------------------------
        | Performance Settings
        |--------------------------------------------------------------------------
        |
        | Configure performance-related settings.
        |
        */

        'enable_gzip' => env('OCI_ENABLE_GZIP', true),
        'connection_pool_size' => env('OCI_CONNECTION_POOL_SIZE', 10),

        /*
        |--------------------------------------------------------------------------
        | Security Settings
        |--------------------------------------------------------------------------
        |
        | Configure security-related settings.
        |
        */

        'verify_ssl' => env('OCI_VERIFY_SSL', true),
        'user_agent' => env('OCI_USER_AGENT', 'Laravel-OCI-Driver/1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Tier Mappings
    |--------------------------------------------------------------------------
    |
    | Map Laravel visibility settings to OCI storage tiers. This allows you
    | to use Laravel's standard visibility API while leveraging OCI's
    | storage tier features.
    |
    */

    'visibility_mapping' => [
        'public' => StorageTier::STANDARD->value,
        'private' => StorageTier::ARCHIVE->value,
        'infrequent' => StorageTier::INFREQUENT_ACCESS->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Type Detection
    |--------------------------------------------------------------------------
    |
    | Configure how content types are detected for uploaded files.
    |
    */

    'content_type' => [
        'detection_method' => env('OCI_CONTENT_TYPE_DETECTION', 'auto'), // auto, extension, finfo
        'default_type' => env('OCI_DEFAULT_CONTENT_TYPE', 'application/octet-stream'),
        'custom_mappings' => [
            // Add custom file extension to MIME type mappings here
            // 'custom' => 'application/x-custom',
        ],
    ],
];
