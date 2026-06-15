# opsfour S3 Server

A production-grade, fully S3-compatible object storage server built as a PHP 8.4+ Composer package. Powered by [Amp v3](https://amphp.org/) for true async I/O with PHP Fibers.

## Features

- **Full S3 API compatibility** — 65 operations including multipart uploads, versioning, object lock, lifecycle rules, S3 Select, and website hosting
- **AWS SDK compatible** — Works with any S3 client (AWS CLI, aws-sdk-php, boto3, MinIO client, etc.)
- **Multiple metadata backends** — SQLite (single-node), PostgreSQL or MySQL (multi-node HA)
- **Multiple storage backends** — Local filesystem, Flysystem (S3, GCS, Azure, SFTP), or in-memory
- **Server-side encryption** — SSE-S3 with key rotation support, SSE-C (customer-provided keys), AES-256-GCM
- **Authentication** — AWS Signature V4, presigned URLs, chunked streaming signatures
- **Multi-tenant** — Owner ID scopes all operations; multiple credential providers (memory, database, file, chain)
- **Event notifications** — Persistent queue with retry, exponential backoff, dead-letter, and SSRF protection
- **S3 Select** — SQL queries over CSV, JSON, and Parquet objects
- **Lifecycle management** — Expiration, noncurrent version cleanup, abort incomplete uploads
- **Rate limiting** — Per-IP token bucket persisted to database, survives restarts
- **Production hardened** — 330 tests, 854 assertions, 9 review rounds, security audit complete

## Requirements

- PHP 8.4+
- Composer 2.x
- ext-openssl (encryption)
- ext-pdo_sqlite (default metadata) or ext-pdo_pgsql / ext-pdo_mysql

## Quick Start

### Standalone

```bash
composer require opsfour/s3-server

# Start with defaults (SQLite metadata, filesystem storage)
php vendor/bin/s3-server \
  --storage-path=/var/data/s3 \
  --access-key=myAccessKey \
  --secret-key=mySecretKey
```

### Laravel

```bash
composer require opsfour/s3-server

php artisan vendor:publish --provider="OpsFour\S3Server\Laravel\S3ServerServiceProvider"

# Configure in .env
S3_STORAGE_PATH=/var/data/s3
S3_ACCESS_KEY=myAccessKey
S3_SECRET_KEY=mySecretKey

php artisan s3:serve
```

### Connect with AWS CLI

```bash
aws configure set aws_access_key_id myAccessKey
aws configure set aws_secret_access_key mySecretKey

aws --endpoint-url http://localhost:9000 s3 mb s3://my-bucket
aws --endpoint-url http://localhost:9000 s3 cp file.txt s3://my-bucket/
aws --endpoint-url http://localhost:9000 s3 ls s3://my-bucket/
```

## Documentation

Full documentation is in the [docs/](docs/) directory:

| Guide | Description |
|-------|-------------|
| [Quick Start](docs/quickstart.md) | Get running in 5 minutes |
| [Installation](docs/installation.md) | Standalone and Laravel installation |
| [Configuration](docs/configuration.md) | All environment variables and options |
| [Laravel Integration](docs/laravel-integration.md) | Service provider, Artisan commands, config |
| [Authentication](docs/authentication.md) | SigV4, presigned URLs, credential providers |
| [Storage Backends](docs/storage-backends.md) | Filesystem, Flysystem, in-memory |
| [Metadata Backends](docs/metadata-backends.md) | SQLite, PostgreSQL, MySQL |
| [Encryption](docs/encryption.md) | SSE-S3 key rotation, SSE-C, configuration |
| [API Operations](docs/api-operations.md) | All 65 supported S3 operations |
| [Versioning & Object Lock](docs/versioning.md) | Bucket versioning, retention, legal holds |
| [Notifications](docs/notifications.md) | Event notifications and webhook delivery |
| [Lifecycle Rules](docs/lifecycle.md) | Object expiration and cleanup |
| [S3 Select](docs/s3-select.md) | SQL queries over stored objects |
| [Production Deployment](docs/deployment.md) | TLS, scaling, monitoring, backups |
| [Architecture](docs/architecture.md) | Internals, middleware stack, design decisions |
| [Development](docs/development.md) | Running tests, contributing, extending |

## License

MIT
