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

- Normalize the user-facing product name to the lowercase `opsfour` spelling.
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
- SQLite metadata schema is version 39, PostgreSQL is 42, and MySQL is 44.
- Lifecycle action limits now apply across the complete sweep and resume fairly
  from a persisted bucket cursor.
- API documentation now describes 69 routed S3 operations.
- Raise the minimum Revolt event-loop version used by asynchronous shutdown
  coordination, and require AWS Flysystem adapter 3.35.2 or newer when that
  optional integration is installed.

### Fixed

- Validate persisted storage-tier references against the server and every
  registered handler tier registry before opening the listener.
- Keep current-object reads bound to the metadata snapshot used during
  authorization, then recheck its bucket generation, identity, and mutable
  authorization under shared owner locks before returning a response. Parallel
  current-object reads may overlap, while writes and mutable reads retain
  exclusive owner locks; failed rechecks close discarded response bodies.
- Revalidate the bucket generation before every streamed GetObject body read
  so a reused bucket name cannot expose bytes from the former bucket.
- Revalidate the bucket generation around every website object and
  error-document stream read so a reused bucket name cannot expose the former
  bucket's body.
- Revoke prepared EFS pre-bind admission after a failed binding recheck so a
  later listener attempt must repeat the complete preparation.
- Reject known S3 query selectors and subresources on the wrong method or
  bucket/object scope before they can fall through to a base mutation.
- Preserve generation-bound multipart part paths during legacy Flysystem
  directory cleanup, recognize only S3 part numbers 1 through 10,000 as known
  paths, and retain unknown entries for explicit reconciliation.
- Record buffered response and S3 error-body byte counts in access logs, label
  bucket and object ACL updates as `REST.PUT.ACL`, and restore failed flush
  entries in chronological FIFO order so retry buffering does not evict newer
  log records before older ones. Propagate request cancellation without
  recording a fabricated HTTP 500 response.
- Ignore `x-amz-expected-bucket-owner` on CreateBucket, as AWS does, while
  continuing to enforce it on supported bucket and object operations.
- Evaluate repeated list-valued HTTP condition, range, and ACL-grant fields as
  one value, and reject repeated singleton digest, copy, checksum, and
  encryption controls. Reject ambiguous `Content-Length` plus
  `Transfer-Encoding` framing, close the HTTP/1 connection, and reject already
  buffered follow-up requests before routing or dispatch.
- Reject DTDs hidden by alternate XML encodings, schema-unknown attributes,
  unsafe identity text, and out-of-range ACL or multipart XML input before
  changing state or serializing a response.
- Keep PostgreSQL account-quota cursor pagination in deterministic UTF-8 byte
  order under non-C database collations.
- Reject malformed webhook destinations before replacing notification
  configuration, dead-letter unsafe legacy destinations without transport, and
  preserve the original event occurrence time in queued payloads.
- Validate complete stored lifecycle rules before any action, revalidate queued
  lifecycle transitions, and reject storage tiers that share one physical
  backend namespace.
- Resolve Symfony serve dependencies inside the sanitized bootstrap boundary,
  and reject malformed Flysystem first-byte timeout values without truncation.
- Reject invalid multipart backend results before publishing part metadata,
  clean safe uncommitted paths, and normalize valid uppercase MD5 values in
  part ETags.
- Preserve the shared wire order of versions and delete markers, apply full
  RFC 3986 encoding to listing response fields, accept standalone multipart
  upload ID markers, and report optional object attributes only when valid.
- Allow an authorized empty-retention request to remove active `GOVERNANCE`
  retention and permit expired locks to be cleared or replaced, while
  continuing to protect active `COMPLIANCE`, legal holds, disabled or malformed
  stored Object Lock state, and custom metadata-store compatibility.
- Require and validate payload checksums for Object Lock PutObject, browser
  PostObject, and relevant UploadPart requests, require streaming checksum
  trailers to finish verification, and bind UploadPart, UploadPartCopy, and
  completion to the Object Lock configuration used at initiation.
- Propagate request cancellation through middleware, temporary-file workers,
  storage backends, and post-commit cleanup while preserving already staged
  garbage work and attempting exact-path retry queuing when a physical delete
  has an uncertain outcome.
- Restore PHP 8.4/Linux SQLite cutover inventory compatibility and prevent
  PostgreSQL storage-garbage claim workers from issuing updates before their
  locked result set is fully consumed.
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
- Return fixed External IAM authentication failure text instead of exposing
  identity-provider exception messages through the credential admin API.
- Reject invalid Laravel boolean environment values instead of treating every
  nonempty string as enabled, including the unsafe storage-fence test bypass.
- Reject quota CLI values above the platform integer range instead of silently
  storing a smaller saturated value.
- Complete the Laravel quick-start credentials with the required owner ID and
  display name.
- Return `NoSuchVersion` from GET and HEAD when an explicitly selected object
  version does not exist.
- Make the Redis Stream notification adapter compatible with `amphp/redis` v2
  and dynamic clients, and encode every stream field as a scalar value.
- Parse conditional request entity-tags strictly so malformed quotes and lists
  cannot satisfy read, copy, multipart-copy, or conditional-write guards.
- Reject negative storage and SQLite worker counts instead of silently falling
  back to synchronous I/O, and align framework setup documentation with the
  actual startup gates and dependencies.
- Fail fast on invalid broker clients, dead-letter unencodable event payloads,
  permit standard empty AMQP destinations, and document callable bridges as
  the portable native-client integration contract.
- Return the delete-marker response headers and `MethodNotAllowed` from
  GetObjectAttributes when an explicit delete-marker version is selected.

### Upgrade Notes

- Stop old API and worker processes before running PostgreSQL or MySQL schema
  migrations. Measure migration locks and backfills on a representative copy.
- Review existing cross-account grants and add the required requester identity
  policies before rollout.
- Revoke permanent credentials issued by the external OIDC API if they should
  no longer remain valid.
- Abort or migrate legacy encrypted multipart uploads before enabling the new
  code on every serving node.
- Abort and restart legacy multipart uploads that carry Object Lock settings or
  are subject to current default retention; they do not contain the new
  Object Lock configuration binding.
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
