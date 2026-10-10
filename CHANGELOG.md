# Changelog

All notable changes to opsfour S3 Server are documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Add the three S3 Object Ownership control operations with `ObjectWriter` as
  the compatibility default and explicit `BucketOwnerEnforced` support.
- Add full-object CRC32, CRC32C, SHA1, and SHA256 handling across object writes,
  reads, attributes, copies, and multipart part listings.
- Add explicit metadata and credential migration commands plus startup schema,
  credential, storage-tier, and PostgreSQL-driver admission checks.
- Add bounded browser POST, request-checksum, Flysystem, and multipart staging
  with shared per-directory reservation accounting.
- Add durable multipart, part, object-write, queue, and physical-cleanup fencing
  needed to retain ambiguous writes for later reconciliation.
- Add trusted-proxy CIDR handling for source IP and secure-transport policy
  context.
- Add issuer-scoped owner IDs for multi-issuer OIDC configurations and bounded
  one-to-twelve-hour external credentials.
- Add paginated runtime quota administration and require complete eight-field
  quota replacements.
- Add bounded delimiter-aware listings and generation-bound multipart listings
  for custom metadata backends.
- Add encrypted multipart parts and authenticated encrypted temporary request
  spools.

### Changed

- Cross-account signed access now requires an explicit identity-policy Allow
  plus resource-side consent from a bucket policy or applicable ACL.
- PostgreSQL API nodes require `pecl-pq` and `pecl-raphf`; migration commands
  may continue to use PDO/`ext-pgsql`.
- PostgreSQL and MySQL serving roles no longer apply schema DDL automatically.
  Run one metadata and, when used, credential migration before starting nodes.
- Remote Flysystem and unqualified shared filesystem backends fail startup by
  default. The unfenced-storage override is restricted to isolated tests.
- Broker adapters remain Best Effort in process; the durable database queue and
  retry contract applies to HTTP(S) webhooks.
- New objects remain unencrypted unless SSE is requested or bucket default
  encryption is configured.
- SQLite metadata schema is version 39, PostgreSQL is 41, and MySQL is 44.
- Lifecycle action limits now apply across the complete sweep and resume fairly
  from a persisted bucket cursor.
- API documentation now describes 69 routed S3 operations.

### Fixed

- Reject ambiguous duplicate authorization, query, checksum, and XML mutation
  inputs before selecting or changing resources.
- Preserve checksum, version, storage-tier, object-owner, and bucket-generation
  semantics across copy, suspended versioning, restore, lifecycle, and
  multipart paths.
- Bound S3 Select syntax, records, parser growth, result framing, and worker
  failure handling.
- Prevent bucket-name reuse and stale generation races from deleting or
  returning data belonging to a replacement bucket.
- Fence queue claims and retries with database time and claim generations.
- Fail closed on missing storage tiers, malformed stored policies, unsupported
  encryption rows, rate-limit database failures, and unsafe backing endpoints.
- Improve CORS, website, tagging, logging, notification, Object Lock, ACL, and
  Public Access Block validation and error compatibility.
- Sanitize management, webhook, storage, and worker failures so secrets and
  backend diagnostics are not exposed in responses or logs.

### Upgrade Notes

- Stop old API and worker processes before running PostgreSQL or MySQL schema
  migrations. Measure migration locks and backfills on a representative copy.
- Review existing cross-account grants and add the required requester identity
  policies before rollout.
- Revoke permanent credentials issued by the external OIDC API if they should
  no longer remain valid.
- Abort or migrate legacy encrypted multipart uploads before enabling the new
  code on every serving node.
- Keep every storage tier referenced by objects, uploads, jobs, intents, or
  cleanup rows configured until those references are migrated or removed.
- Review [Authentication](docs/authentication.md),
  [Metadata Backends](docs/metadata-backends.md),
  [Storage Backends](docs/storage-backends.md), and
  [Encryption](docs/encryption.md) before deployment.

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
