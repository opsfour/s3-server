# OpsFour S3 Server Documentation

## Getting Started

- [Quick Start](quickstart.md) — Get a working S3 server in 5 minutes
- [Installation](installation.md) — Standalone and Laravel installation guides

## Configuration

- [Configuration Reference](configuration.md) — All environment variables, defaults, and validation rules
- [Laravel Integration](laravel-integration.md) — Service provider, Artisan commands, publishing config

## Core Concepts

- [Authentication](authentication.md) — SigV4, presigned URLs, credential providers
- [Storage Backends](storage-backends.md) — Filesystem, Flysystem, in-memory
- [Metadata Backends](metadata-backends.md) — SQLite, PostgreSQL, MySQL
- [Encryption](encryption.md) — SSE-S3 with key rotation, SSE-C

## S3 API

- [API Operations](api-operations.md) — All 65 supported S3 operations
- [Versioning & Object Lock](versioning.md) — Bucket versioning, retention, legal holds
- [Notifications](notifications.md) — Event notifications and webhook delivery
- [Lifecycle Rules](lifecycle.md) — Object expiration and automated cleanup
- [Production Feature Roadmap](production-feature-roadmap.md) — Quotas, IAM/OIDC, eventing, lifecycle hardening, and deferred AWS features
- [Policy Compatibility Matrix](policy-compatibility.md) — Supported bucket policy principals, actions, resources, conditions, and fail-closed behavior
- [S3 Select](s3-select.md) — SQL queries over CSV, JSON, and Parquet objects

## Operations

- [Production Deployment](deployment.md) — TLS, scaling, monitoring, health checks, backups
- [Architecture](architecture.md) — Internals, middleware stack, async design
- [Development](development.md) — Running tests, extending handlers, contributing
