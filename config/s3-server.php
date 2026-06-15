<?php

return [
    /*
    |--------------------------------------------------------------------------
    | S3 Server Configuration
    |--------------------------------------------------------------------------
    */

    // Listen address.
    'host' => env('S3_HOST', '0.0.0.0'),

    // Listen port.
    'port' => (int) env('S3_PORT', 9000),

    // AWS region identifier.
    'region' => env('S3_REGION', 'us-east-1'),

    // TLS certificate path (null to disable).
    'tls_cert_path' => env('S3_TLS_CERT'),

    // TLS private key path (null to disable).
    'tls_key_path' => env('S3_TLS_KEY'),

    // Maximum concurrent connections.
    'max_connections' => (int) env('S3_MAX_CONNECTIONS', 10000),

    // Request body size limit in bytes (default 5 GiB).
    'body_size_limit' => (int) env('S3_BODY_SIZE_LIMIT', 5_368_709_120),

    // Base domain for virtual-hosted-style bucket addressing.
    'base_domain' => env('S3_BASE_DOMAIN'),

    // Enforce strict S3 bucket naming rules.
    'strict_bucket_naming' => (bool) env('S3_STRICT_BUCKET_NAMING', true),

    // Per-client rate limit (requests per second, 0 = unlimited).
    'rate_limit' => (int) env('S3_RATE_LIMIT', 1000),

    // Website hosting host pattern (e.g., '*.s3-website.example.com').
    'website_host_pattern' => env('S3_WEBSITE_HOST_PATTERN'),

    // Maximum object size for SSE encryption (bytes, default 256 MiB).
    'max_encrypted_object_size' => (int) env('S3_MAX_ENCRYPTED_OBJECT_SIZE', 268_435_456),

    // Maximum object size for S3 Select queries (bytes, default 256 MiB).
    'max_select_object_size' => (int) env('S3_MAX_SELECT_OBJECT_SIZE', 268_435_456),

    // Graceful shutdown drain timeout in seconds.
    'shutdown_drain_timeout' => (int) env('S3_SHUTDOWN_DRAIN_TIMEOUT', 30),

    // Connection idle timeout in seconds (0 = no timeout).
    'connection_idle_timeout' => (int) env('S3_CONNECTION_IDLE_TIMEOUT', 60),

    // Read timeout in seconds.
    'read_timeout' => (int) env('S3_READ_TIMEOUT', 300),

    // Write timeout in seconds.
    'write_timeout' => (int) env('S3_WRITE_TIMEOUT', 300),

    // Master key provider for SSE-S3: 'config', 'vault'.
    'master_key_provider' => env('S3_MASTER_KEY_PROVIDER', 'config'),

    /*
    |--------------------------------------------------------------------------
    | Parallel Processing (amphp/parallel)
    |--------------------------------------------------------------------------
    |
    | Offloads blocking operations to worker processes.
    | Set to 0 to disable parallel mode (blocking fallback).
    |
    */
    'parallel' => [
        // Number of worker processes for SQLite metadata operations.
        'sqlite_workers' => (int) env('S3_SQLITE_WORKERS', 0),

        // Number of worker processes for encryption/decryption.
        'encryption_workers' => (int) env('S3_ENCRYPTION_WORKERS', 0),

        // Objects smaller than this (bytes) are encrypted inline.
        'encryption_threshold' => (int) env('S3_ENCRYPTION_THRESHOLD', 65536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotas
    |--------------------------------------------------------------------------
    |
    | Hard limits enforced before metadata commits. Set a value to 0 to disable
    | that quota.
    |
    */
    'quotas' => [
        'max_buckets_per_owner' => (int) env('S3_QUOTA_MAX_BUCKETS_PER_OWNER', 0),
        'max_objects_per_bucket' => (int) env('S3_QUOTA_MAX_OBJECTS_PER_BUCKET', 0),
        'max_bytes_per_bucket' => (int) env('S3_QUOTA_MAX_BYTES_PER_BUCKET', 0),
        'max_bytes_per_owner' => (int) env('S3_QUOTA_MAX_BYTES_PER_OWNER', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Processing
    |--------------------------------------------------------------------------
    |
    | Background lifecycle work is intentionally bounded per sweep so large
    | buckets are cleaned incrementally without monopolizing the event loop.
    |
    */
    'lifecycle' => [
        'interval_seconds' => (float) env('S3_LIFECYCLE_INTERVAL_SECONDS', 60),
        'batch_size' => (int) env('S3_LIFECYCLE_BATCH_SIZE', 1000),
        'max_actions_per_run' => (int) env('S3_LIFECYCLE_MAX_ACTIONS_PER_RUN', 1000),
        'lock_ttl_seconds' => (int) env('S3_LIFECYCLE_LOCK_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Backend
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "filesystem", "flysystem", "memory"
    |
    */
    'storage' => [
        'driver' => env('S3_STORAGE_DRIVER', 'filesystem'),
        'path' => env('S3_STORAGE_PATH', function_exists('storage_path') ? storage_path('s3') : ''),
        /*
        | Optional physical tier registry. When empty, the single backend above is
        | exposed as the STANDARD tier. Restore-required tiers are not read
        | directly by hot object handlers; restore workers will copy temporary hot
        | objects back to a readable tier in the physical-tiering implementation.
        |
        | Example:
        | 'tiers' => [
        |     'STANDARD' => [
        |         'driver' => 'filesystem',
        |         'path' => env('S3_STORAGE_PATH'),
        |         'default' => true,
        |     ],
        |     'GLACIER' => [
        |         'driver' => 'filesystem',
        |         'path' => env('S3_GLACIER_STORAGE_PATH'),
        |         'restore_required' => true,
        |     ],
        | ],
        */
        'tiers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Backend
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "sqlite", "postgres", "mysql"
    |
    */
    'metadata' => [
        'driver' => env('S3_METADATA_DRIVER', 'sqlite'),
        'dsn' => env('S3_METADATA_DSN', ''),
        // TTL in seconds for bucket-level metadata cache (0 = disabled).
        'cache_ttl' => (float) env('S3_METADATA_CACHE_TTL', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "memory", "database", "file", "chain"
    |
    | For "memory" driver: access_key, secret_key, owner_id, display_name are
    | required. No hardcoded defaults — you MUST set these via .env or config.
    |
    */
    'credentials' => [
        'driver' => env('S3_CREDENTIALS_DRIVER', 'memory'),
        'access_key' => env('S3_ACCESS_KEY'),
        'secret_key' => env('S3_SECRET_KEY'),
        'owner_id' => env('S3_OWNER_ID'),
        'display_name' => env('S3_DISPLAY_NAME'),
        // For 'database' driver:
        'dsn' => env('S3_CREDENTIALS_DSN'),
        // For 'file' driver:
        'path' => env('S3_CREDENTIALS_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Runtime APIs
    |--------------------------------------------------------------------------
    |
    | When S3_ADMIN_API_TOKEN is set, exposes protected runtime management APIs:
    |   GET    /.admin/quotas
    |   GET    /.admin/quotas/{ownerId}
    |   PUT    /.admin/quotas/{ownerId}
    |   DELETE /.admin/quotas/{ownerId}
    |
    | The token is sent as Authorization: Bearer <token>. If this value is not
    | set, the quota admin API can fall back to S3_EXTERNAL_IAM_ADMIN_TOKEN.
    |
    */
    'admin' => [
        'token' => env('S3_ADMIN_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | External IAM / OIDC Credential Issuer
    |--------------------------------------------------------------------------
    |
    | When enabled, exposes:
    |   POST   /.admin/credentials
    |   DELETE /.admin/credentials/{accessKeyId}
    |
    | The admin API itself requires S3_EXTERNAL_IAM_ADMIN_TOKEN. The POST body
    | contains an external OIDC access token, which is validated as RS256 JWT
    | against either a JWKS file, a PEM public key file, or an inline PEM key.
    |
    */
    'external_iam' => [
        'enabled' => (bool) env('S3_EXTERNAL_IAM_ENABLED', false),
        'admin_token' => env('S3_EXTERNAL_IAM_ADMIN_TOKEN'),
        'issuer' => env('S3_EXTERNAL_IAM_ISSUER'),
        'audience' => env('S3_EXTERNAL_IAM_AUDIENCE'),
        'jwks_path' => env('S3_EXTERNAL_IAM_JWKS_PATH'),
        'public_key_path' => env('S3_EXTERNAL_IAM_PUBLIC_KEY_PATH'),
        'public_key' => env('S3_EXTERNAL_IAM_PUBLIC_KEY'),
        'owner_claim' => env('S3_EXTERNAL_IAM_OWNER_CLAIM', 'sub'),
        'display_name_claim' => env('S3_EXTERNAL_IAM_DISPLAY_NAME_CLAIM', 'preferred_username'),
        'groups_claim' => env('S3_EXTERNAL_IAM_GROUPS_CLAIM', 'groups'),
        'policy_names_claim' => env('S3_EXTERNAL_IAM_POLICY_NAMES_CLAIM'),
        'allowed_prefixes_claim' => env('S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM'),
        'owner_prefix' => env('S3_EXTERNAL_IAM_OWNER_PREFIX', ''),
        'clock_skew_seconds' => (int) env('S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS', 60),
    ],
];
