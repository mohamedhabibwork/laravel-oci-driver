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
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Production Connection
        |--------------------------------------------------------------------------
        |
        | Example configuration for a production environment connection.
        | Remove or modify this section based on your needs.
        |
        */
        'production' => [
            'tenancy_id' => env('OCI_PROD_TENANCY_ID'),
            'user_id' => env('OCI_PROD_USER_ID'),
            'key_fingerprint' => env('OCI_PROD_KEY_FINGERPRINT'),
            'key_path' => env('OCI_PROD_KEY_PATH'),
            'namespace' => env('OCI_PROD_NAMESPACE'),
            'region' => env('OCI_PROD_REGION', 'us-phoenix-1'), // Use OciRegion enum values
            'bucket' => env('OCI_PROD_BUCKET'),
            'storage_tier' => 'Standard',
            'timeout' => 60, // Longer timeout for production
            'cache' => [
                'enabled' => true,
                'ttl' => 600, // 10 minutes for production
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'warning', // Only warnings and above
                'channel' => 'production',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Development Connection
        |--------------------------------------------------------------------------
        |
        | Example configuration for a development environment connection.
        |
        */
        'development' => [
            'tenancy_id' => env('OCI_DEV_TENANCY_ID'),
            'user_id' => env('OCI_DEV_USER_ID'),
            'key_fingerprint' => env('OCI_DEV_KEY_FINGERPRINT'),
            'key_path' => env('OCI_DEV_KEY_PATH'),
            'namespace' => env('OCI_DEV_NAMESPACE'),
            'region' => env('OCI_DEV_REGION', 'us-ashburn-1'),
            'bucket' => env('OCI_DEV_BUCKET'),
            'storage_tier' => 'InfrequentAccess', // Cheaper for dev
            'cache' => [
                'enabled' => false, // Disable cache for development
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'debug', // Verbose logging for development
                'channel' => 'development',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Example: Backup Connection
        |--------------------------------------------------------------------------
        |
        | Example configuration for backup storage.
        |
        */
        'backup' => [
            'tenancy_id' => env('OCI_BACKUP_TENANCY_ID'),
            'user_id' => env('OCI_BACKUP_USER_ID'),
            'key_fingerprint' => env('OCI_BACKUP_KEY_FINGERPRINT'),
            'key_path' => env('OCI_BACKUP_KEY_PATH'),
            'namespace' => env('OCI_BACKUP_NAMESPACE'),
            'region' => env('OCI_BACKUP_REGION', 'eu-frankfurt-1'), // Different region for geo-redundancy
            'bucket' => env('OCI_BACKUP_BUCKET'),
            'storage_tier' => 'Archive', // Cheapest for long-term storage
            'timeout' => 120, // Longer timeout for archive operations
            'cache' => [
                'enabled' => true,
                'ttl' => 3600, // 1 hour cache for backup metadata
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
