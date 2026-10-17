# opsfour S3 Server Documentation

opsfour S3 Server is a project of [twopeaks digital GmbH](https://twopeaks.digital).
Related ops4 information is available at [ops4.com](https://ops4.com).

The [MIT License](../LICENSE.md) includes the warranty disclaimer and limitation
of liability for the open-source package.
Paid support, integration work, production hardening, and custom development are
available on request under separately agreed terms.

## Getting Started

- [Quick Start](quickstart.md) — Get a working S3 server in 5 minutes
- [Installation](installation.md) — Standalone, Laravel, and Symfony installation guides

## Configuration

- [Configuration Reference](configuration.md) — All environment variables, defaults, and validation rules
- [Laravel Integration](laravel-integration.md) — Service provider, Artisan commands, publishing config
- [Symfony Integration](symfony-integration.md) — Bundle registration, console commands, config, and production setup

## Core Concepts

- [Authentication](authentication.md) — SigV4, presigned URLs, credential providers
- [Storage Backends](storage-backends.md) — Filesystem, Flysystem, in-memory
- [Metadata Backends](metadata-backends.md) — SQLite, PostgreSQL, MySQL
- [Encryption](encryption.md) — SSE-S3 with key rotation, SSE-C

## S3 API

- [API Operations](api-operations.md) — All 69 supported S3 operations
- [Versioning & Object Lock](versioning.md) — Bucket versioning, retention, legal holds
- [Notifications](notifications.md) — Event notifications and webhook delivery
- [Lifecycle Rules](lifecycle.md) — Object expiration and automated cleanup
- [Production Feature Roadmap](production-feature-roadmap.md) — Status definitions, current production target, next profiles, and deferred scope
- [Policy Compatibility Matrix](policy-compatibility.md) — Supported bucket policy principals, actions, resources, conditions, and fail-closed behavior
- [S3 Select](s3-select.md) — SQL queries over unencrypted CSV and JSON objects

## Operations

- [Production Deployment](deployment.md) — TLS, scaling, monitoring, health checks, backups
- [Release Checklist](release-checklist.md) — Universal and profile-specific gates before tagging
- [Changelog](../CHANGELOG.md) — Versioned release history and upgrade scope
- [Architecture](architecture.md) — Internals, middleware stack, async design
- [Development](development.md) — Running tests, extending handlers, contributing
