<?php

use OpsFour\S3Server\Laravel\EnvironmentNumber;
use OpsFour\S3Server\Laravel\EnvironmentBoolean;

return [
    /*
    |--------------------------------------------------------------------------
    | S3 Server Configuration
    |--------------------------------------------------------------------------
    */

    // Listen address.
    'host' => env('S3_HOST', '0.0.0.0'),

    // Listen port.
    'port' => EnvironmentNumber::integer('S3_PORT', 9000),

    // AWS region identifier.
    'region' => env('S3_REGION', 'us-east-1'),

    // Setting a cluster ID enables PostgreSQL shared-capability admission.
    'cluster_id' => env('S3_CLUSTER_ID'),
    'cluster_node_id' => env('S3_CLUSTER_NODE_ID'),
    'cluster_database_id' => env('S3_CLUSTER_DATABASE_ID'),
    'cluster_roles' => env('S3_CLUSTER_ROLES'),
    'cluster_required_roles' => env('S3_CLUSTER_REQUIRED_ROLES'),

    // UNSAFE TEST ONLY: bypass the storage late-write fence startup gate.
    'allow_unfenced_storage_for_tests' => EnvironmentBoolean::boolean('S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS', false),

    // TLS certificate path (null to disable).
    'tls_cert_path' => env('S3_TLS_CERT'),

    // TLS private key path (null to disable).
    'tls_key_path' => env('S3_TLS_KEY'),

    // Maximum concurrent connections.
    'max_connections' => EnvironmentNumber::integer('S3_MAX_CONNECTIONS', 10000),

    // Request body size limit in bytes (default 5 GiB).
    'body_size_limit' => EnvironmentNumber::integer('S3_BODY_SIZE_LIMIT', 5_368_709_120),

    // Concurrent browser POST temporary bytes shared by API processes using one local temp directory (default 10 GiB).
    'post_spool_budget' => EnvironmentNumber::integer('S3_POST_SPOOL_BUDGET', 10_737_418_240),

    // Maximum seconds to await a dispatched local object or multipart write worker.
    'local_write_worker_timeout_seconds' => EnvironmentNumber::finiteFloat('S3_LOCAL_WRITE_WORKER_TIMEOUT_SECONDS', 300.0),

    // Base domain for virtual-hosted-style bucket addressing.
    'base_domain' => env('S3_BASE_DOMAIN'),

    // Reject AWS-reserved bucket name prefixes and suffixes.
    'strict_bucket_naming' => EnvironmentBoolean::boolean('S3_STRICT_BUCKET_NAMING', true),

    // Per-client rate limit (requests per second, 0 = unlimited).
    'rate_limit' => EnvironmentNumber::integer('S3_RATE_LIMIT', 1000),

    // Only these directly connected proxy peers may supply X-Forwarded-Proto/For.
    'trusted_proxy_cidrs' => env('S3_TRUSTED_PROXY_CIDRS', ''),

    // Website hosting host pattern (e.g., '*.s3-website.example.com').
    'website_host_pattern' => env('S3_WEBSITE_HOST_PATTERN'),

    // Maximum object size for SSE encryption (bytes, default 256 MiB).
    'max_encrypted_object_size' => EnvironmentNumber::integer('S3_MAX_ENCRYPTED_OBJECT_SIZE', 268_435_456),

    // Maximum object size for buffered S3 Select queries (bytes, default 64 MiB).
    'max_select_object_size' => EnvironmentNumber::integer('S3_MAX_SELECT_OBJECT_SIZE', 67_108_864),

    // Maximum seconds to drain active work before shutdown fails safely.
    'shutdown_drain_timeout' => EnvironmentNumber::integer('S3_SHUTDOWN_DRAIN_TIMEOUT', 30),

    // Connection idle timeout in seconds (0 = no timeout).
    'connection_idle_timeout' => EnvironmentNumber::integer('S3_CONNECTION_IDLE_TIMEOUT', 60),

    // Read timeout in seconds.
    'read_timeout' => EnvironmentNumber::integer('S3_READ_TIMEOUT', 300),

    // Write timeout in seconds.
    'write_timeout' => EnvironmentNumber::integer('S3_WRITE_TIMEOUT', 300),

    // Enforce the S3 5 MiB minimum for every non-final multipart part.
    'enforce_min_part_size' => EnvironmentBoolean::boolean('S3_ENFORCE_MIN_PART_SIZE', true),

    'notifications' => [
        // Reject plaintext HTTP webhook destinations by default.
        'require_https' => EnvironmentBoolean::boolean('S3_NOTIFICATION_REQUIRE_HTTPS', true),
        // Let this server process drain queued webhook deliveries.
        'worker_enabled' => EnvironmentBoolean::boolean('S3_NOTIFICATION_WORKER', true),
        // Optionally dispatch internal S3 events through Laravel's event dispatcher.
        'laravel_event_dispatcher' => [
            'enabled' => false,
            'pattern' => 's3:*',
            'event_name' => null,
        ],
    ],

    'metrics_bearer_token' => env('S3_METRICS_BEARER_TOKEN'),

    // Master key provider for SSE-S3: 'config', 'redis', or 'vault'.
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
        'sqlite_workers' => EnvironmentNumber::integer('S3_SQLITE_WORKERS', 4),

        // Number of workers isolating PDO credential operations from HTTP.
        'credential_workers' => EnvironmentNumber::integer('S3_CREDENTIALS_WORKERS', 4),

        // Number of worker processes for encryption/decryption.
        'encryption_workers' => EnvironmentNumber::integer('S3_ENCRYPTION_WORKERS', 0),

        // Number of worker processes for S3 Select. One isolates buffered query processing by default.
        'select_workers' => EnvironmentNumber::integer('S3_SELECT_WORKERS', 1),

        // Stateless workers for request-body checksum spooling.
        'request_body_spool_workers' => EnvironmentNumber::integer('S3_REQUEST_BODY_SPOOL_WORKERS', 8),

        // Objects smaller than this (bytes) are encrypted inline.
        'encryption_threshold' => EnvironmentNumber::integer('S3_ENCRYPTION_THRESHOLD', 65536),
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
        'max_buckets_per_owner' => EnvironmentNumber::integer('S3_QUOTA_MAX_BUCKETS_PER_OWNER', 0),
        'max_objects_per_bucket' => EnvironmentNumber::integer('S3_QUOTA_MAX_OBJECTS_PER_BUCKET', 0),
        'max_bytes_per_bucket' => EnvironmentNumber::integer('S3_QUOTA_MAX_BYTES_PER_BUCKET', 0),
        'max_bytes_per_owner' => EnvironmentNumber::integer('S3_QUOTA_MAX_BYTES_PER_OWNER', 0),
        'max_multipart_uploads_per_bucket' => EnvironmentNumber::integer('S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_BUCKET', 0),
        'max_multipart_uploads_per_owner' => EnvironmentNumber::integer('S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_OWNER', 0),
        'max_multipart_bytes_per_bucket' => EnvironmentNumber::integer('S3_QUOTA_MAX_MULTIPART_BYTES_PER_BUCKET', 0),
        'max_multipart_bytes_per_owner' => EnvironmentNumber::integer('S3_QUOTA_MAX_MULTIPART_BYTES_PER_OWNER', 0),
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
        'interval_seconds' => EnvironmentNumber::finiteFloat('S3_LIFECYCLE_INTERVAL_SECONDS', 60.0),
        'batch_size' => EnvironmentNumber::integer('S3_LIFECYCLE_BATCH_SIZE', 1000),
        'max_actions_per_run' => EnvironmentNumber::integer('S3_LIFECYCLE_MAX_ACTIONS_PER_RUN', 1000),
        'lock_ttl_seconds' => EnvironmentNumber::integer('S3_LIFECYCLE_LOCK_TTL_SECONDS', 300),
        'multipart_max_age_seconds' => EnvironmentNumber::integer('S3_MULTIPART_MAX_AGE_SECONDS', 604800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Backend
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "filesystem", "flysystem", "aws-s3", "memory"
    |
    */
    'storage' => [
        'driver' => env('S3_STORAGE_DRIVER', 'filesystem'),
        'path' => env('S3_STORAGE_PATH', function_exists('storage_path') ? storage_path('s3') : ''),
        'temp_dir' => env('S3_STORAGE_TEMP_DIR', sys_get_temp_dir()),
        'temp_budget_bytes' => env('S3_STORAGE_TEMP_BUDGET', 10_737_418_240),
        // Remote Flysystem adapters should use a serializable factory and workers.
        'worker_pool_size' => EnvironmentNumber::integer('S3_FLYSYSTEM_WORKERS', 0),
        'read_first_byte_timeout_seconds' => EnvironmentNumber::integer('S3_FLYSYSTEM_FIRST_BYTE_TIMEOUT_SECONDS', 20),
        'filesystem_factory_service' => env('S3_FLYSYSTEM_FACTORY_SERVICE', null),
        // Explicit AWS profile. Credentials come only from the AWS SDK default chain.
        'bucket' => env('S3_AWS_BUCKET'),
        'region' => env('S3_AWS_REGION'),
        'expected_owner_id' => env('S3_AWS_EXPECTED_OWNER_ID'),
        'data_prefix' => env('S3_AWS_DATA_PREFIX'),
        'reserved_prefix' => env('S3_AWS_RESERVED_PREFIX'),
        'connect_timeout_seconds' => EnvironmentNumber::integer('S3_AWS_CONNECT_TIMEOUT_SECONDS', 5),
        'read_timeout_seconds' => EnvironmentNumber::integer('S3_AWS_READ_TIMEOUT_SECONDS', 15),
        'request_timeout_seconds' => EnvironmentNumber::integer('S3_AWS_REQUEST_TIMEOUT_SECONDS', 20),
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
        'path' => env('S3_METADATA_PATH'),
        // TTL in seconds for bucket-level metadata cache (0 = disabled).
        'cache_ttl' => EnvironmentNumber::finiteFloat('S3_METADATA_CACHE_TTL', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Supported configured drivers: "memory", "database", "file".
    | A chain is available through the programmatic factory API.
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
        'username' => env('S3_CREDENTIALS_USERNAME'),
        'password' => env('S3_CREDENTIALS_PASSWORD'),
        // Positive credential lookups are refreshed after this many seconds.
        // Set to 0 to disable caching and observe revocations on every request.
        'cache_ttl' => EnvironmentNumber::finiteFloat('S3_CREDENTIALS_CACHE_TTL', 1.0),
        // Maximum runtime of one credential database operation in a serving process.
        'operation_timeout_seconds' => EnvironmentNumber::finiteFloat('S3_CREDENTIALS_OPERATION_TIMEOUT_SECONDS', 15.0),
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
        // Keep the raw value so the runtime factory can reject malformed booleans.
        'enabled' => env('S3_EXTERNAL_IAM_ENABLED', false),
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
        'clock_skew_seconds' => env('S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS', 60),
    ],
];
