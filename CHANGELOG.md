# Changelog

All notable changes to opsfour S3 Server are documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Add an offline `storage:audit` command for bounded, non-repairing reference
  existence and size checks across explicitly mapped local filesystem tiers.
- Add exclusive `.opsfour/runtime.lock` ownership for local storage roots so a
  second server process is rejected before its listener starts.
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

- Clarify the production target, shared support-status definitions, and the
  separation between universal release gates and optional deployment profiles.
- Align the production soak with the supported single-process local-filesystem
  profile, with selectable SQLite, PostgreSQL, or MySQL metadata and no remote
  object-storage credentials.
- Export `bin/s3-server` as the Composer package binary, report the installed
  package version from `--version`, and declare the DOM, libxml, and SimpleXML
  PHP extensions required by the public runtime.
- Require `ext-mbstring` for the documented UTF-8 S3 Select string functions.
- Set the buffered S3 Select input default and hard ceiling to 64 MiB; larger
  configured values remain accepted but cannot raise the safe runtime limit.
- Run buffered S3 Select query processing in one dedicated worker by default;
  setting `S3_SELECT_WORKERS=0` keeps the explicit inline mode available.
- Document the existing `proc_open` runtime requirement for local helper
  workers.
- Normalize the user-facing product name to the lowercase `opsfour` spelling.
- Cross-account signed access now requires an explicit identity-policy Allow
  plus resource-side consent from a bucket policy or applicable ACL.
- PostgreSQL API nodes require `pecl-pq` and `pecl-raphf`; migration commands
  may continue to use PDO/`ext-pgsql`.
- PostgreSQL and MySQL serving roles no longer apply schema DDL automatically.
  Run one metadata and, when used, credential migration before starting nodes.
- Remote Flysystem and unqualified shared filesystem backends fail startup by
  default. The unfenced-storage override is restricted to isolated tests.
- Treat NFS/EFS multi-host filesystem deployments as an optional, currently
  unqualified profile. They do not gate releases for the package's qualified
  local-filesystem or provider-specific storage profiles.
- Broker adapters remain Best Effort in process; the durable database queue and
  retry contract applies to HTTP(S) webhooks.
- New objects remain unencrypted unless SSE is requested or bucket default
  encryption is configured.
- SQLite metadata schema is version 39, PostgreSQL is 42, and MySQL is 46.
- Raise the completed multipart object limit to S3's 50-TB limit (50,000 GiB)
  while retaining the 5-GiB per-part limit. A full GetObject above 5 TiB now
  returns HTTP 405; ranged GetObject requests and HeadObject remain available.
- Lifecycle action limits now apply across the complete sweep and resume fairly
  from a persisted bucket cursor.
- API documentation now describes 69 routed S3 operations.
- Raise the minimum Revolt event-loop version used by asynchronous shutdown
  coordination, and require AWS Flysystem adapter 3.35.2 or newer when that
  optional integration is installed.
- Require Symfony HttpFoundation 7.4.21 or 8.1.9 or newer within their
  respective supported lines for framework integrations, excluding
  CVE-2026-108082 and the whitespace-separator regression in its first fixes.

### Fixed

- Initialize every package-managed MySQL metadata connection in UTC so server
  session time zones cannot shift S3 timestamps.
- Reject invalid ports in stored website redirect hosts instead of emitting
  unusable `Location` responses.
- Reject explicitly present non-integer OIDC `nbf` and `iat` claims while
  keeping those optional claims optional.
- Return version-specific Object Lock retention and legal-hold headers from
  GET and HEAD when the caller has the corresponding metadata permission.
- Allow anonymous object options when their additional actions are explicitly
  granted by bucket policy while preserving two-sided cross-account checks.
- Normalize object and version listing timestamps to UTC before emitting the
  literal S3 `Z` suffix.
- Default CopyObject destinations to the S3 `STANDARD` storage class when the
  request does not specify `x-amz-storage-class`, including self-copy checks.
- Reject reentrant CreateBucket requests whose location differs from the
  existing bucket instead of reporting a false success.
- Enforce `x-amz-mp-object-size` before multipart assembly and keep the upload
  available when the declared size is invalid or does not match.
- Normalize PostgreSQL multipart timestamps to valid UTC S3 response values.
- Count lifecycle rule ID limits in Unicode characters and widen MySQL tier-job
  rule IDs to preserve accepted 255-character values.
- Preserve JSON `null` in S3 Select projections, reject wrong table aliases in
  `WHERE`, return SQL `NULL` for empty non-count aggregates, apply UTF-8 string
  and `LIKE` wildcard semantics consistently, and fail closed for unsupported
  `CAST` targets or invalid UTF-8 operands.
- Return controlled S3 Select capacity errors when parser row limits are
  reached and reject objects above the effective input ceiling before reading
  their bytes.
- Reduce buffered S3 Select memory peaks by appending storage chunks directly
  and releasing input rows while filtering instead of retaining duplicate row
  index arrays.

- Store newly auto-configured SQLite metadata in `.opsfour/metadata.sqlite`
  while continuing to use an existing regular legacy `metadata.sqlite` file,
  leaving the valid `metadata.sqlite` bucket name available for object data.
  When both database files exist, require an explicit metadata path instead of
  silently selecting one.
- Apply SQLite `synchronous=FULL`, `cache_size=-64000`, and
  `mmap_size=268435456` to every runtime connection, including when reopening
  a database whose schema is already current.
- Preserve an object's S3 `Last-Modified` timestamp when restore state,
  physical placement, transition metadata, or current-version bookkeeping
  changes across SQLite, PostgreSQL, and MySQL. PostgreSQL and MySQL version
  listings also expose the noncurrent timestamp used by lifecycle rules.
- Retry a failed graceful worker-pool shutdown with forced termination on the
  next server stop attempt. Storage ingress and Select pools remain ahead of
  graph cleanup, completed pools are not closed twice, and each failed close
  attempt is counted once.
- Arm SIGINT and SIGTERM watchers before opening the HTTP listener in the
  standalone, Laravel, and Symfony serve commands, and remove those watchers
  when startup fails.
- Make the encrypted multipart completion post-sync corruption fault
  deterministic so the integrity-fence regression cannot accidentally rewrite
  the original leading byte.
- Return empty bodies for HEAD errors while retaining their status and response
  headers. Method-not-allowed responses now include `Allow` when the valid
  method set is known, and S3 `SlowDown` responses include `Retry-After: 1`.
- Accept S3 Tagging XML in the browser POST `tagging` form field, preserve its
  original value for signed policy conditions, and reject malformed XML or tag
  characters before the uploaded file is spooled or published.
- Enforce the documented S3 tag character set on header and XML writes so XML
  controls, Unicode noncharacters, and unsupported symbols cannot be stored or
  make GetTagging responses unparsable; rejected replacements preserve tags.
  Validate legacy stored tags before GetTagging XML serialization or
  `CopyObject` with `TaggingDirective=COPY`; corrupt rows return `InternalError`
  and a copy leaves its destination unchanged.
- Authorize object creation through the bucket ACL only, and permit replacement
  through a bucket `WRITE` grant only for the bucket or current object owner.
  Object ACL `WRITE` grants are inert, and object `FULL_CONTROL` no longer
  grants PUT, copy, browser POST, or multipart write access to the object's key.
- Attribute anonymous CopyObject results, ACLs, local write intents, quota
  accounting, and versioned delete markers to the bucket owner while retaining
  anonymous request and notification attribution. Assign delete markers to the
  bucket owner while `BucketOwnerEnforced` is active, and reject anonymous
  multipart initiation even when a public write policy exists.
- Allow self-copy when destination encryption attributes change, including
  SSE-S3 defaults and SSE-C key rotation, and reject impossible known-size PUT
  and CopyObject quota requests before body or destination storage I/O.
- Bind multipart completion policy checks to the exact ACL, standard SSE, and
  storage-class headers supplied at initiation; require ambiguous legacy
  uploads to be aborted and initiated again.
- Reauthorize delegated cross-account `AbortMultipartUpload` requests under
  the owner lock immediately before commit. The resource-policy Allow must
  remain valid through that point; revocation preserves the upload and all
  parts and returns `NoSuchUpload` without exposing their existence.
- Validate quota owner IDs consistently across direct writers, the standalone,
  Laravel, and Symfony commands, and the admin API; keep invalid legacy rows
  from breaking JSON pagination, and route percent-encoded `/` characters in
  valid owner IDs through the admin API.
- Keep reserved probe, metrics, and admin paths out of S3 wildcard routing;
  return management 404/405 responses with `Allow` and `no-store`, and keep
  management backend and rate-limit failures in their JSON or text formats.
- Reject object-shaped OIDC list and JWT audience claims before issuing S3
  credentials, compare every credential field during atomic deletion and
  status changes, and return an explicit conflict when admin revocation loses
  a compare-and-delete race to a replacement credential.
- Canonicalize trusted-proxy IPv6 and IPv4-mapped client addresses before
  policy, rate-limit, and access-log use so equivalent addresses share one
  cluster-wide identity.
- Refuse External IAM startup with a credential-provider chain that cannot
  atomically revoke every credential it issues.
- Return AWS-compatible `InvalidTargetBucketForLogging` and
  `CrossLocationLoggingProhibited` errors when enabling server access logging
  with a missing, foreign-owned, or cross-region target bucket.
- Reject overlapping physical storage tiers for filesystem roots and built-in
  local or AWS S3 Flysystem factories, even when separate factory instances,
  credentials, timeout settings, symlink aliases, or path case on an
  insensitive volume hide the shared namespace.
- Validate persisted storage-tier references against the server and every
  registered handler tier registry before opening the listener.
- Keep current-object reads bound to the metadata snapshot used during
  authorization, then recheck its bucket generation, identity, and mutable
  authorization under shared owner locks before returning a response. Parallel
  current-object reads may overlap, while writes and mutable reads retain
  exclusive owner locks; failed rechecks close discarded response bodies.
  Apply the same locked recheck before exposing errors derived from current
  object state, replacing stale errors when policy, ACL, or ownership changed.
  The snapshot now includes response-visible object metadata and checksums,
  while version-list bookkeeping does not invalidate an explicitly selected
  version.
- Reauthorize and replay a bodyless HeadObject request once under its shared
  owner lock when the only snapshot change is restore completion from pending
  to restored. Policy, ACL, ownership, or other object drift remains
  fail-closed; GetObject and SelectObjectContent responses are never replayed.
- Revalidate the bucket generation before every streamed GetObject body read
  so a reused bucket name cannot expose bytes from the former bucket.
- Recheck current identity policies, bucket policies, ACLs, Public Access
  Block settings, and bucket generation before returning buffered service,
  bucket-configuration, listing, and multipart-listing reads. Shared metadata
  backends keep concurrent readers parallel while serializing policy writers.
- Revalidate the bucket generation around every website object and
  error-document stream read so a reused bucket name cannot expose the former
  bucket's body.
- Revoke prepared optional shared-filesystem pre-bind admission after a failed
  binding recheck so a later listener attempt must repeat preparation.
- Reject known S3 query selectors and subresources on the wrong method or
  bucket/object scope before they can fall through to a base mutation.
- Route arbitrary S3 request methods through the S3 resolver, normalize DNS
  case and trailing dots for virtual-hosted buckets, and reject decoded control
  characters instead of silently aliasing a different path-style bucket.
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
- Use the AWS `GetBucketObjectLockConfiguration` and
  `PutBucketObjectLockConfiguration` IAM actions for Object Lock bucket
  configuration, and serialize the disabled S3 Bucket Key status explicitly.
- Evaluate repeated list-valued HTTP condition, range, and ACL-grant fields as
  one value, and reject repeated singleton digest, copy, checksum, and
  encryption controls. Reject ambiguous `Content-Length` plus
  `Transfer-Encoding` framing, close the HTTP/1 connection, and reject already
  buffered follow-up requests before routing or dispatch.
- Reject DTDs hidden by alternate XML encodings, schema-unknown attributes,
  unsafe identity text, and out-of-range ACL or multipart XML input before
  changing state or serializing a response.
- Enforce portable control-plane limits before replacement: 100 ACL grants,
  255-byte ACL grantee IDs, 512-byte notification destinations, 988-byte
  logging prefixes, and 1,024-byte DeleteObjects keys. Count CORS and lifecycle
  rule IDs as up to 255 Unicode characters and validate lifecycle filter tags
  with the same required-value and character rules as object tags.
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
- Preserve the `Expires` header from multipart initiation through completion,
  reject explicit delete-marker UploadPartCopy sources, and fail encrypted GET
  or multipart-copy reads whose decrypted size disagrees with metadata.
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
- Use the signed HTTP `Date` value for SigV4 streaming chunk verification when
  `x-amz-date` is absent, and treat an external JWT exactly at its expiration
  boundary as expired.
- Preserve checksum, version, storage-tier, object-owner, and bucket-generation
  semantics across copy, suspended versioning, restore, lifecycle, and
  multipart paths.
- Verify persisted full-object checksums before publishing restore or lifecycle
  tier copies, and bind expired restore cleanup to the original bucket
  generation and object snapshot.
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
- Abort and restart multipart uploads created before exact initiation policy
  header snapshots were introduced; their ACL and encryption condition context
  cannot be reconstructed safely at completion.
- Inventory legacy bucket and object tags for characters, lengths, counts, and
  duplicate keys that violate the current S3 tag rules. Repair or remove
  corrupt rows during the maintenance window; GetTagging and tag-copy requests
  now fail closed with `InternalError` until those rows are corrected.
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
