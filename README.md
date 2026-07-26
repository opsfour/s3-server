# opsfour S3 Server

A production-oriented S3-compatible object storage server built as a PHP 8.4+
Composer package. Powered by [Amp v3](https://amphp.org/) for async I/O with
PHP Fibers.

## Features

- **Broad S3 API coverage** — 66 routed S3 operations including multipart uploads, versioning, object lock, lifecycle rules, S3 Select, restore, and website hosting
- **AWS SDK compatible** — Tested with the AWS SDK for PHP; standard S3 clients can use the documented operation subset
- **Multiple metadata backends** — SQLite (single-node), PostgreSQL or MySQL (multi-node HA)
- **Multiple storage backends** — Local filesystem, Flysystem (S3, GCS, Azure, SFTP), or in-memory
- **Server-side encryption** — SSE-S3 with key rotation support, SSE-C (customer-provided keys), AES-256-GCM
- **Authentication** — AWS Signature V4, presigned URLs, signed and unsigned `aws-chunked` checksum trailers
- **Multi-tenant** — Owner ID scopes all operations; multiple credential providers (memory, database, file, chain)
- **Framework integrations** — Standalone CLI, Laravel service provider, and Symfony Bundle
- **Quotas and tiering** — Per-owner/per-bucket object and multipart-staging quotas, physical storage tiers, cold-tier restore workflow
- **Event notifications** — Persistent queue with retry, exponential backoff, dead-letter, and SSRF protection
- **S3 Select** — SQL queries over unencrypted CSV and JSON objects
- **Lifecycle management** — Expiration, noncurrent version cleanup, abort incomplete uploads
- **Rate limiting** — Per-IP token bucket persisted to database, survives restarts
- **Production hardened** — 745-test default suite plus large-object, high-concurrency, remote-storage, and uninterrupted 12-hour soak profiles

## Requirements

- PHP 8.4+
- Composer 2.x
- ext-openssl (encryption)
- ext-pdo_sqlite (default metadata) or ext-pdo_pgsql / ext-pdo_mysql
- Symfony 7.4 LTS or 8.1+ when using the Symfony Bundle
- Laravel 12 or 13 when using the Laravel service provider

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

### Symfony

```bash
composer require opsfour/s3-server

# Register OpsFour\S3Server\Symfony\S3ServerBundle in config/bundles.php
# Configure config/packages/opsfour_s3_server.yaml
php bin/console opsfour:s3:serve
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
| [Installation](docs/installation.md) | Standalone, Laravel, and Symfony installation |
| [Configuration](docs/configuration.md) | All environment variables and options |
| [Laravel Integration](docs/laravel-integration.md) | Service provider, Artisan commands, config |
| [Symfony Integration](docs/symfony-integration.md) | Bundle registration, console commands, services, config |
| [Authentication](docs/authentication.md) | SigV4, presigned URLs, credential providers |
| [Storage Backends](docs/storage-backends.md) | Filesystem, Flysystem, in-memory |
| [Metadata Backends](docs/metadata-backends.md) | SQLite, PostgreSQL, MySQL |
| [Encryption](docs/encryption.md) | SSE-S3 key rotation, SSE-C, configuration |
| [API Operations](docs/api-operations.md) | All 66 supported S3 operations |
| [Versioning & Object Lock](docs/versioning.md) | Bucket versioning, retention, legal holds |
| [Notifications](docs/notifications.md) | Event notifications and webhook delivery |
| [Lifecycle Rules](docs/lifecycle.md) | Object expiration and cleanup |
| [S3 Select](docs/s3-select.md) | SQL queries over stored objects |
| [Policy Compatibility](docs/policy-compatibility.md) | Supported IAM-style actions and condition keys |
| [Production Feature Roadmap](docs/production-feature-roadmap.md) | Implemented production features and deferred AWS scope |
| [Release Checklist](docs/release-checklist.md) | Production readiness checks before tagging |
| [Changelog](CHANGELOG.md) | Versioned release history and upgrade scope |
| [Production Deployment](docs/deployment.md) | TLS, scaling, monitoring, backups |
| [Architecture](docs/architecture.md) | Internals, middleware stack, design decisions |
| [Development](docs/development.md) | Running tests, contributing, extending |

## Project & Support

opsfour S3 Server is a [twopeaks.digital](https://twopeaks.digital) project.
Related ops4 information is available at [ops4.com](https://ops4.com).

This package and repository are provided **as is**, without warranty of any kind.
No guarantee is made for fitness for a particular purpose, uninterrupted
operation, data integrity, security, compatibility, or continued maintenance.
To the maximum extent permitted by law, no liability is accepted for damages,
data loss, business interruption, or other consequences arising from use of this
software. By using this repository or package, you acknowledge and agree to this
disclaimer and limitation of liability.

Paid support, integration work, production hardening, and custom development are
available on request.

## License

MIT
