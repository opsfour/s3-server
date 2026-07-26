# Changelog

All notable changes to opsfour S3 Server are documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.3.1] - 2026-07-26

### Documentation

- Add the versioned release history, mark the completed `v1.3.0` validation,
  document notification HTTPS enforcement, and update repository version
  references.

## [1.3.0] - 2026-07-26

### Added

- Complete P0, P1, and P2 production re-audit coverage across authorization,
  policy validation, request parsing, encryption, multipart uploads, quotas,
  storage durability, queue leases, shutdown, and health checks.
- PostgreSQL and MySQL concurrency, stale-lease recovery, migration, and
  long-key metadata parity tests.
- Redis and Vault master-key providers for the built-in SSE-S3 envelope
  encryption flow.
- Pinned GitHub Actions quality, framework compatibility, and metadata-backend
  jobs.
- Detached Docker production-soak runner with a 1 GiB no-swap memory limit,
  PostgreSQL backing metadata, continuity checks, OOM detection, and independent
  remote cleanup.

### Changed

- Remote Flysystem copies use adapter-native copy operations with bounded
  streaming verification.
- Physical tier transitions and restores revalidate object identity and
  placement before committing metadata.
- Amp filesystem data handles and stateless filesystem operations use separate
  bounded worker pools to prevent starvation under sustained concurrency.
- Supported framework ranges are Laravel 12 or 13 and Symfony 7.4 or 8.1.
- Every S3 operation exposed by AWS SDK for PHP 3.389 is routed or explicitly
  classified as unsupported.

### Fixed

- Fail-closed behavior for copy-source authorization, ACL and Public Access
  Block handling, KMS configuration, policy conditions, and bounded request
  bodies.
- Cleanup and retry behavior for multipart staging, physical storage garbage,
  notification outbox records, lifecycle work, transitions, and restores.
- Graceful cancellation and shutdown across standalone, Laravel, Symfony, and
  programmatic runtimes.
- Expected Laravel CLI configuration errors no longer emit misleading server
  bootstrap-crash log entries.

### Validation

- The default suite passed with 745 tests, 3,288 recorded assertions, and 9
  expected opt-in skips.
- A 256 MiB streamed PUT/GET, 128 MiB multipart upload, and 200-object workload
  at 100 concurrent requests passed.
- The uninterrupted Linode Flysystem and PostgreSQL production soak passed in
  12:00:04.186 with 209,852 assertions, no OOM, enforced workload continuity,
  and successful remote cleanup.

AWS STS Query API compatibility remains intentionally outside this release.

## [1.2.0] - 2026-07-23

### Added

- Versioning, object management, Object Lock, lifecycle execution, physical
  tiering and restore, notifications, quotas, external IAM integration, and the
  expanded IAM-style policy engine.
- Remote Flysystem storage support validated against a path-style Linode
  bucket.
- Large-file, multipart, concurrency, backend integration, and five-minute
  production-soak profiles.

### Changed

- Streaming and bounded worker behavior was hardened for POST Object,
  checksums, multipart uploads, filesystem I/O, and remote storage.
- Website hosting became private by default and requires normal anonymous
  `s3:GetObject` authorization.
- PostgreSQL and MySQL metadata writes gained owner-scoped locking and quota
  serialization.

## [1.1.0] - 2026-06-15

### Added

- Symfony runtime integration with Symfony 7 and 8 bundle support.
- Full-package PHPStan and PHP CS Fixer configuration.
- MIT license, project and paid-support information, liability disclaimer, and
  structured GitHub bug-report template.

## [1.0.0] - 2026-06-15

- Initial public release.

[Unreleased]: https://github.com/opsfour/s3-server/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/opsfour/s3-server/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/opsfour/s3-server/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/opsfour/s3-server/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/opsfour/s3-server/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/opsfour/s3-server/releases/tag/v1.0.0
