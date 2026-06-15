# Configuration Reference

Portable runtime settings can be configured via environment variables. In
Laravel, they map to `config/s3-server.php`. In Symfony, they can be mirrored in
`config/packages/opsfour_s3_server.yaml` with `%env(...)%` processors; Symfony
service references such as Flysystem filesystems, event listeners, and custom
encryption services are configured in YAML rather than environment variables.

## Network

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_HOST` | `0.0.0.0` | Listen address |
| `S3_PORT` | `9000` | Listen port |
| `S3_REGION` | `us-east-1` | AWS region identifier (returned in location responses) |
| `S3_MAX_CONNECTIONS` | `10000` | Maximum concurrent connections |
| `S3_BODY_SIZE_LIMIT` | `5368709120` | Max request body size in bytes (default 5 GiB) |
| `S3_BASE_DOMAIN` | - | Base domain for virtual-hosted-style requests (e.g., `s3.example.com`) |
| `S3_STRICT_BUCKET_NAMING` | `true` | Enforce S3 bucket naming rules |

## TLS

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_TLS_CERT` | - | Path to TLS certificate file (PEM) |
| `S3_TLS_KEY` | - | Path to TLS private key file (PEM) |

When both are set, the server listens on HTTPS. Required for presigned URLs in production.

## Storage

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_STORAGE_DRIVER` | `filesystem` | Storage backend: `filesystem`, `flysystem`, or `memory` |
| `S3_STORAGE_PATH` | - | Root directory for object data (required for `filesystem`) |

See [Storage Backends](storage-backends.md) for driver-specific configuration.
Physical storage tiers are configured as arrays in Laravel config or Symfony
YAML, because each tier needs a named backend and optional service references.

## Metadata

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_METADATA_DRIVER` | `sqlite` | Metadata backend: `sqlite`, `postgres`, or `mysql` |
| `S3_METADATA_DSN` | - | Connection string for postgres/mysql |
| `S3_METADATA_CACHE_TTL` | `5.0` | Metadata read cache TTL in seconds (0 to disable) |

See [Metadata Backends](metadata-backends.md) for driver-specific configuration.

### DSN Formats

```bash
# PostgreSQL
S3_METADATA_DSN="host=localhost port=5432 dbname=s3server user=s3 password=secret"

# MySQL
S3_METADATA_DSN="host=localhost;port=3306;dbname=s3server;user=s3;password=secret"
```

## Credentials

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_CREDENTIALS_DRIVER` | `memory` | Provider: `memory`, `database`, `file`, or `chain` |
| `S3_ACCESS_KEY` | - | Access key ID (memory driver) |
| `S3_SECRET_KEY` | - | Secret access key (memory driver) |
| `S3_OWNER_ID` | - | Owner/tenant ID (memory driver) |
| `S3_DISPLAY_NAME` | - | Display name (memory driver) |
| `S3_CREDENTIALS_DSN` | - | Database DSN (database driver) |
| `S3_CREDENTIALS_PATH` | - | JSON file path (file driver) |

See [Authentication](authentication.md) for multi-user setup.

## Admin APIs and External IAM

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_ADMIN_API_TOKEN` | - | Bearer token for protected quota runtime APIs under `/.admin/quotas` |
| `S3_EXTERNAL_IAM_ENABLED` | `false` | Enable external OIDC/JWT credential issuing API |
| `S3_EXTERNAL_IAM_ADMIN_TOKEN` | - | Bearer token for external credential issuing API; also used as quota admin fallback when `S3_ADMIN_API_TOKEN` is unset |
| `S3_EXTERNAL_IAM_ISSUER` | - | Expected JWT issuer |
| `S3_EXTERNAL_IAM_AUDIENCE` | - | Expected JWT audience |
| `S3_EXTERNAL_IAM_JWKS_PATH` | - | Local JWKS file used to verify RS256 JWTs |
| `S3_EXTERNAL_IAM_PUBLIC_KEY_PATH` | - | PEM public key file used to verify RS256 JWTs |
| `S3_EXTERNAL_IAM_PUBLIC_KEY` | - | Inline PEM public key used to verify RS256 JWTs |
| `S3_EXTERNAL_IAM_OWNER_CLAIM` | `sub` | JWT claim mapped to S3 owner ID |
| `S3_EXTERNAL_IAM_DISPLAY_NAME_CLAIM` | `preferred_username` | JWT claim mapped to credential display name |
| `S3_EXTERNAL_IAM_GROUPS_CLAIM` | `groups` | JWT groups claim |
| `S3_EXTERNAL_IAM_POLICY_NAMES_CLAIM` | - | JWT claim listing policy names to attach |
| `S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM` | - | JWT claim listing allowed object prefixes |
| `S3_EXTERNAL_IAM_OWNER_PREFIX` | - | Prefix prepended to mapped owner IDs |
| `S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS` | `60` | Allowed JWT clock skew |

See [Authentication](authentication.md) for credential provider setup, runtime
quota admin APIs, and external IAM/OIDC examples.

## Quotas

Quota values are hard limits enforced before metadata commits. Set a value to
`0` to disable that limit.

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_QUOTA_MAX_BUCKETS_PER_OWNER` | `0` | Maximum buckets per owner/account |
| `S3_QUOTA_MAX_OBJECTS_PER_BUCKET` | `0` | Maximum current objects per bucket |
| `S3_QUOTA_MAX_BYTES_PER_BUCKET` | `0` | Maximum current object bytes per bucket |
| `S3_QUOTA_MAX_BYTES_PER_OWNER` | `0` | Maximum current object bytes across all buckets for one owner/account |

Per-account overrides are stored in metadata and can be managed through the
admin API, Laravel Artisan command, or Symfony console command.

## Encryption

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_ENCRYPTION_MASTER_KEY` | - | Single master key (base64, 32 bytes decoded) |
| `S3_ENCRYPTION_MASTER_KEYS` | - | Multi-key JSON for rotation (see below) |
| `S3_MASTER_KEY_PROVIDER` | `config` | Key provider: `config`, `redis`, or `vault` |
| `S3_MAX_ENCRYPTED_OBJECT_SIZE` | `268435456` | Max object size for SSE-S3 encryption (256 MiB) |

### Key Rotation Format

```bash
# Multi-key: first key is active, others decrypt historical data
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"base64encodedKey...","key-2024":"base64encodedOldKey..."}'
```

See [Encryption](encryption.md) for key rotation procedures.

### Redis Key Provider

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_REDIS_MASTER_KEY_DSN` | - | Redis connection string |
| `S3_REDIS_MASTER_KEY_NAME` | `s3:master-key` | Redis key for legacy single-key |

### Vault Key Provider

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_VAULT_ADDR` | - | Vault server URL (must be HTTPS) |
| `S3_VAULT_TOKEN` | - | Vault authentication token |
| `S3_VAULT_PATH` | `secret/data/s3-server/master-key` | KV v2 secret path |
| `S3_VAULT_KEY_FIELD` | `key` | Field name in secret data |

## Rate Limiting

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_RATE_LIMIT` | `1000` | Max requests per second per IP (0 = unlimited) |

Rate limit state is persisted in the metadata database and survives server restarts. On multi-node deployments with Postgres/MySQL, rate limiting is shared across nodes.

## Timeouts

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_CONNECTION_IDLE_TIMEOUT` | `60` | Idle connection timeout (seconds) |
| `S3_READ_TIMEOUT` | `300` | Read timeout (seconds) |
| `S3_WRITE_TIMEOUT` | `300` | Write timeout (seconds) |
| `S3_SHUTDOWN_DRAIN_TIMEOUT` | `30` | Graceful shutdown drain time (seconds) |

## Lifecycle Processing

The lifecycle runner is wired into the runtime for standalone, Laravel, and
Symfony entry points. Work is bounded per sweep so large buckets are cleaned or
transitioned incrementally.

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_LIFECYCLE_INTERVAL_SECONDS` | `60` | Seconds between lifecycle sweeps |
| `S3_LIFECYCLE_BATCH_SIZE` | `1000` | Maximum rows fetched per lifecycle query |
| `S3_LIFECYCLE_MAX_ACTIONS_PER_RUN` | `1000` | Maximum destructive or mutating actions per sweep |
| `S3_LIFECYCLE_LOCK_TTL_SECONDS` | `300` | Metadata-backed lease TTL for multi-node runners |

Lifecycle rules can expire objects, clean noncurrent versions, abort incomplete
multipart uploads, and move data between configured physical storage tiers. See
[Lifecycle Rules](lifecycle.md) and [Storage Backends](storage-backends.md).

## S3 Select

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_MAX_SELECT_OBJECT_SIZE` | `268435456` | Max object size for S3 Select queries (256 MiB) |

## Website Hosting

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_WEBSITE_HOST_PATTERN` | - | Host pattern for website-enabled buckets (e.g., `{bucket}.s3-website.example.com`) |

## Parallelism

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_SQLITE_WORKERS` | `0` | Number of Amp parallel workers for SQLite (0 = blocking mode) |
| `S3_ENCRYPTION_WORKERS` | `0` | Number of Amp parallel workers for encryption (0 = inline) |
| `S3_ENCRYPTION_THRESHOLD` | `65536` | Object size threshold for offloading encryption to workers (bytes) |

Setting `S3_SQLITE_WORKERS` > 0 wraps SQLite in a `ParallelSqliteMetadataStore` that dispatches queries to a worker pool, preventing SQLite's single-writer lock from blocking the event loop.

## Example .env

```bash
# Network
S3_HOST=0.0.0.0
S3_PORT=9000
S3_REGION=us-east-1

# Storage
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/var/data/s3

# Metadata (production)
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"

# Credentials
S3_CREDENTIALS_DRIVER=database
S3_CREDENTIALS_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"

# Admin APIs
S3_ADMIN_API_TOKEN=change-me

# Quotas
S3_QUOTA_MAX_BUCKETS_PER_OWNER=0
S3_QUOTA_MAX_OBJECTS_PER_BUCKET=0
S3_QUOTA_MAX_BYTES_PER_BUCKET=0
S3_QUOTA_MAX_BYTES_PER_OWNER=0

# Lifecycle
S3_LIFECYCLE_INTERVAL_SECONDS=60
S3_LIFECYCLE_BATCH_SIZE=1000
S3_LIFECYCLE_MAX_ACTIONS_PER_RUN=1000
S3_LIFECYCLE_LOCK_TTL_SECONDS=300

# Encryption
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"BASE64_ENCODED_32_BYTE_KEY"}'

# Rate limiting
S3_RATE_LIMIT=1000

# TLS
S3_TLS_CERT=/etc/ssl/certs/s3.pem
S3_TLS_KEY=/etc/ssl/private/s3.key

# Timeouts
S3_SHUTDOWN_DRAIN_TIMEOUT=30
```
