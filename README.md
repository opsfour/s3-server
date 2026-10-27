# opsfour S3 Server

An S3-compatible object storage server built as a PHP 8.4+ Composer package.
Powered by [Amp v3](https://amphp.org/) for async I/O with PHP Fibers.

## Features

- **Broad S3 API coverage** — 69 routed S3 operations including multipart uploads, versioning, Object Ownership controls, object lock, lifecycle rules, S3 Select, restore, and website hosting
- **AWS SDK compatible** — Tested with the AWS SDK for PHP; standard S3 clients can use the documented operation subset
- **Multiple metadata backends** — SQLite for one node; PostgreSQL and MySQL
  are also supported for the initial single-host target and provide shared
  metadata for later qualified profiles
- **Multiple storage backends** — Persistent local filesystem for the current
  production target; a distinct AWS S3 provider-admission profile; Flysystem
  and in-memory drivers for experimental and test profiles
- **Optional server-side encryption** — SSE-S3 with key rotation support and SSE-C (customer-provided keys), using AES-256-GCM when configured
- **Authentication** — AWS Signature V4, presigned URLs, signed and unsigned `aws-chunked` checksum trailers
- **Multi-tenant** — Owner IDs isolate buckets by default; explicit grants can allow cross-account object access. Credential providers include memory, database, file, and chain.
- **Framework integrations** — Standalone CLI, Laravel service provider, and Symfony Bundle
- **Quotas and tiering** — Per-owner/per-bucket object and multipart-staging quotas, physical storage tiers, cold-tier restore workflow
- **Event notifications** — Persistent webhook queue with retry, exponential backoff, dead-letter, and SSRF protection; optional in-process broker adapters are Best Effort
- **S3 Select** — SQL queries over unencrypted CSV and JSON objects
- **Lifecycle management** — Expiration, noncurrent version cleanup, abort incomplete uploads
- **Rate limiting** — Per-IP token bucket persisted to database, survives restarts
- **Release checks** — Universal gates for the package plus separate gates for
  every advertised deployment profile; see the
  [release checklist](docs/release-checklist.md)

Signed access across local accounts requires an explicit Allow in the requester's
account or attached credential policy and a bucket-policy or ACL grant from the
resource side. Existing cross-account grants need both permissions when upgrading;
see [Policy Compatibility](docs/policy-compatibility.md).

## Production Target

The v2.0.0 production target is Linux on one host with one API process,
persistent local filesystem storage, and SQLite, PostgreSQL, or MySQL metadata.
It supports the standalone CLI, Laravel, and Symfony, either directly or behind
a reverse proxy that preserves the signed `Host` header exactly.

The qualified v2.0.0 reference profile uses PHP 8.4, SQLite metadata, and one
API process on a Linux host with its exact local XFS mount. Other metadata,
filesystem, mount, device, and host combinations require the applicable
deployment gates. The explicit `aws-s3` profile validates AWS identity, region,
ownership, General Purpose Bucket evidence, and disabled versioning, but remains
fail-closed until its writer fence is complete. A package-internal AWS primitive
can now durably arm an exact create-only attempt and return a closed provider
outcome. Ordinary PUT, browser POST, and CopyObject destinations now use the
exact dispatch fence internally, including a narrow verified CopyObject source
read. The same exact undispatched reservation is renewed across staging, SSE
preprocessing, and encrypted Copy transforms; complete staged evidence is
validated before the sole provider dispatch, with an independent heartbeat while
a yielding encryption worker is active. Handler wiring accepts only the exact
admitted backend/profile binding, and post-commit scratch cleanup cannot replace
a successful response.
API startup still refuses this profile while the remaining writers and
recovery paths are incomplete. Memory storage is test-only. Flysystem, remote and shared
filesystems, NFS/EFS, multiple API processes, and multiple hosts remain
experimental until each exact profile completes its own qualification gates. See the
[roadmap and status definitions](docs/production-feature-roadmap.md).

## Requirements

- PHP 8.4+
- Composer 2.x
- `proc_open` enabled for the package's local helper worker processes
- ext-dom, ext-libxml, and ext-simplexml for S3 XML requests and responses
- ext-mbstring for UTF-8 S3 Select string functions
- ext-openssl (required for all request-body temporary spools)
- ext-pcntl and ext-posix for Linux process control, signal handling, and
  credential-file ownership verification
- ext-pdo_sqlite for the default SQLite metadata backend
- pecl-pq and its pecl-raphf dependency for PostgreSQL API nodes; the server
  refuses to start with only ext-pgsql
- Symfony 7.4 LTS or 8.1+ when using the Symfony Bundle
- Laravel 12 or 13 when using the Laravel service provider

## Quick Start

### Standalone

```bash
composer require opsfour/s3-server

# Set S3_ACCESS_KEY and S3_SECRET_KEY in the environment first.
# Start with SQLite metadata and filesystem storage.
php vendor/bin/s3-server \
  --storage-path=/var/data/s3
```

### Laravel

```bash
composer require opsfour/s3-server

php artisan vendor:publish --provider="OpsFour\S3Server\Laravel\S3ServerServiceProvider"

# Configure in .env
S3_STORAGE_PATH=/var/data/s3
S3_ACCESS_KEY=myAccessKey
S3_SECRET_KEY=mySecretKey
S3_OWNER_ID=local-account
S3_DISPLAY_NAME="Local account"

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
(
export AWS_ACCESS_KEY_ID=myAccessKey
export AWS_SECRET_ACCESS_KEY=mySecretKey
export AWS_DEFAULT_REGION=us-east-1

aws --endpoint-url http://localhost:9000 s3 mb s3://my-bucket
aws --endpoint-url http://localhost:9000 s3 cp file.txt s3://my-bucket/
aws --endpoint-url http://localhost:9000 s3 ls s3://my-bucket/
)
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
| [API Operations](docs/api-operations.md) | All 69 supported S3 operations |
| [Versioning & Object Lock](docs/versioning.md) | Bucket versioning, retention, legal holds |
| [Notifications](docs/notifications.md) | Event notifications and webhook delivery |
| [Lifecycle Rules](docs/lifecycle.md) | Object expiration and cleanup |
| [S3 Select](docs/s3-select.md) | SQL queries over stored objects |
| [Policy Compatibility](docs/policy-compatibility.md) | Supported IAM-style actions and condition keys |
| [Production Feature Roadmap](docs/production-feature-roadmap.md) | Current, later, and out-of-scope work |
| [Release Checklist](docs/release-checklist.md) | Production readiness checks before tagging |
| [Changelog](CHANGELOG.md) | Versioned release history and upgrade scope |
| [Deployment](docs/deployment.md) | Current deployment profile, TLS, monitoring, backups |
| [Architecture](docs/architecture.md) | Internals, middleware stack, design decisions |
| [Development](docs/development.md) | Running tests, contributing, extending |

## Project & Support

opsfour S3 Server is a project of [twopeaks digital GmbH](https://twopeaks.digital).
Related ops4 information is available at [ops4.com](https://ops4.com).

The open-source package is licensed under the [MIT License](LICENSE.md), which
includes its warranty disclaimer and limitation of liability.

Paid support, integration work, production hardening, and custom development are
available on request under separately agreed terms.

## License

[MIT License](LICENSE.md)
