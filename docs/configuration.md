# Configuration Reference

All settings can be configured via environment variables. In Laravel, they map to `config/s3-server.php`.

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
