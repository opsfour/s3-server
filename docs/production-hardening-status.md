# Production Hardening Status

This document records the remediation work from the full server audit completed
on 2026-07-24. Historical feature scope remains in
`production-feature-roadmap.md`; exact release commands and measured results are
in `release-checklist.md`.

## Release Decision

Status: **P0, P1, and P2 complete; all configured release gates pass**

Runtime hardening was released as `v1.3.0` on 2026-07-26. The
runtime-identical `v1.3.1` patch includes the completed release documentation.

The default suite, PostgreSQL 16, MySQL 8.0, large-file, high-concurrency, and
five-minute soak profiles pass on the current source. The external Linode
Path-Style profile also passes against the configured remote bucket. AWS STS
Query API compatibility remains optional and unsupported. The first detached
12-hour attempt was invalidated by a 9-hour-21-minute host suspension after
4 hours 19 minutes of continuous workload; continuity detection and a macOS
awake guard now prevent that pause from being reported as a successful gate.
The uninterrupted repeat passed against Linode Flysystem and PostgreSQL in
12:00:04 with 209,852 assertions, no OOM, and successful remote cleanup.

## Priority Closure

- [x] P0: fail-closed encryption/KMS behavior, source-side authorization for
  copy operations, version-scoped ACL/tag/Object Lock state, bounded control
  bodies/metadata/tags, atomic conditionals, and transactional notification
  outbox.
- [x] P1: durable physical deletion retries, multipart quotas and stale cleanup,
  lifecycle lease renewal, transfer-time race revalidation, bounded signed and
  unsigned AWS chunk trailers, and storage/backend failure-path cleanup.
- [x] P2: authenticated metrics, access logging, Redis/Vault key providers,
  graceful listener drain, complete-package static analysis, and CI quality
  gates including MySQL/PostgreSQL parity and migration jobs.

## 1. Authorization

Status: **Complete**

- [x] Unmapped ACL operations fail closed for non-owners unless identity or
  bucket policy explicitly allows access.
- [x] Bucket creation requires authentication and cannot create anonymous
  ownership records.
- [x] All 66 routed operations have explicit IAM action and ACL behavior,
  including `PostObject`.
- [x] Public Access Block is enforced for ACL writes and public access.
- [x] `CopyObject` and `UploadPartCopy` authorize the exact source bucket,
  object version, account scope, identity policies, bucket policy, ACL, and
  Public Access Block state before reading source bytes.
- [x] Owner, foreign-account, anonymous, explicit-allow, and explicit-deny
  authorization matrices enumerate the complete operation enum.

## 2. POST Object

Status: **Complete**

- [x] Form authentication applies only to `PostObject`.
- [x] Multipart forms and file bodies are streamed through bounded temporary
  storage.
- [x] Form credentials are authenticated before IAM and ACL authorization.
- [x] Form writes share versioning, quota, encryption, ACL, tag, notification,
  and replacement cleanup behavior with normal object writes.
- [x] Browser-form, malformed, anonymous, signed, quota, encryption,
  versioning, concurrency, and parity tests pass.

## 3. Runtime and Shutdown

Status: **Complete**

- [x] TLS certificate and key configuration reaches the listening server.
- [x] Connection, read, write, and idle timeouts are configurable and applied.
- [x] Lifecycle, cleanup, tiering, restore, notification, request-spool, and
  storage worker activity is cancelled during shutdown.
- [x] Background tasks and worker pools stop in deterministic order.
- [x] Startup failures and stop-before-start paths also release storage,
  metadata, select, encryption, and request-spool worker resources.
- [x] Runtime shutdown is idempotent across standalone, Laravel, Symfony, and
  programmatic entry points.
- [x] TLS, timeout, graceful-shutdown, repeated start/stop, and soak tests pass.

## 4. Network Destinations

Status: **Complete**

- [x] Every webhook redirect destination is revalidated against the SSRF
  policy.
- [x] HTTPS hostname verification and SNI are preserved.
- [x] HTTPS-only delivery is configurable for production.
- [x] Redirects and resolution to loopback, private, link-local, and blocked
  destinations are covered.
- [x] The outbound network trust boundary is documented.

## 5. Tier-Aware Object Operations

Status: **Complete**

- [x] Website reads, copy, delete, bulk delete, and lifecycle deletion use the
  object's actual storage tier.
- [x] Transition and restore verification uses physical stream checksums and
  does not confuse encrypted physical bytes with plaintext metadata.
- [x] Failed metadata commits clean target data and retries are idempotent.
- [x] Versioned, encrypted, restored, overwritten, stale-job, and concurrent
  deletion paths are covered.
- [x] Transition, restore, and restore-garbage workers revalidate exact object
  identity and placement under stable owner locks after physical transfers.
- [x] Restore completion emits `s3:ObjectRestore:Completed` after commit.

## 6. Streaming Storage and Integrity

Status: **Complete**

- [x] `Content-MD5` and modern S3 checksums are calculated with bounded
  streaming spools.
- [x] Large range and object responses remain streamed.
- [x] Synchronous Flysystem adapters run in bounded process workers.
- [x] Filesystem data handles and stateless filesystem operations use separate
  bounded pools to prevent recursive-create and rename starvation.
- [x] Flysystem copies use the adapter-native copy operation and verify the
  copied target with bounded streaming checksums.
- [x] `CopyObject` uses native copy for every shared backend, including remote
  Flysystem tiers, instead of downloading and uploading through the server.
- [x] Standalone Flysystem can construct an S3-compatible worker factory from
  environment variables and rejects blocking worker-free remote mode.
- [x] Remote storage does not create a local object-data root; only explicit
  staging and SQLite paths are created locally.
- [x] Worker downloads honor cancellation.
- [x] The production-facing CLI, Laravel, and Symfony configuration defaults
  enforce the S3 minimum multipart part size.
- [x] Large uploads, downloads, multipart assembly, high concurrency,
  backpressure, stale-response behavior, and external Linode storage pass.

The stale-server root cause was worker starvation, not recursive `mkdir`
semantics. Long-lived file handles occupied every Amp worker while recursive
directory creation, rename, or deletion waited for the same pool. Separate
data-handle and stateless-operation pools remove that dependency cycle.

## 7. Metadata and Concurrency

Status: **Complete**

- [x] MySQL stops on the first non-duplicate migration failure and records the
  schema version only after all statements succeed.
- [x] PostgreSQL and MySQL serialize owner-scoped, quota-sensitive writes with
  database row locks.
- [x] Two-process quota race integration coverage exists for both drivers.
- [x] MySQL obtains transition/restore queue IDs from the insert result instead
  of a connection-local `LAST_INSERT_ID()` query through the pool.
- [x] PostgreSQL storage-garbage dequeue uses the Fiber-compatible transaction
  wrapper in synchronous and asynchronous callers.
- [x] PostgreSQL and MySQL reclaim expired storage-garbage processing leases
  after worker crashes.
- [x] Metadata cache invalidation is covered for all mutation categories.
- [x] Supported database versions, migration behavior, and isolation
  requirements are documented.
- [x] Migration, round-trip, observability, and concurrent quota tests pass
  against PostgreSQL 16 and MySQL 8.0 instances.

SQLite uses schema version `14`. PostgreSQL and MySQL use schema version `15`.
The external schemas include owner write locks, version-aware tags, multipart
staging quotas, and durable physical-storage garbage collection. MySQL uses a
generated SHA-256 tag identity so full long keys remain unique without exceeding
InnoDB's `utf8mb4` index limit.

## 8. Release Verification

Status: **Complete**

- [x] README claims are bounded by the documented 66-operation API and policy
  compatibility matrices.
- [x] AWS STS Query API is explicitly optional and unsupported.
- [x] PHPStan passes over the complete package.
- [x] PHP CS Fixer reports no changes across 396 files.
- [x] The complete default suite passes: 745 tests, 3,288 assertions, 9 expected
  skips, 0 failures.
- [x] A 256 MiB streamed PUT/GET and 128 MiB multipart upload pass.
- [x] A 200-object workload at 100 concurrent requests passes.
- [x] The five-minute production soak passes without stale responses, temporary
  file leakage, or RSS threshold breach.
- [x] The external Linode Flysystem Path-Style profile passes a 64 MiB object,
  range, native copy, delete, 3 x 8 MiB multipart assembly, and cleanup.
- [x] Composer validation, dependency audit, and diff whitespace checks pass.
- [x] PostgreSQL 16 and MySQL 8.0 fresh-schema, round-trip, observability,
  long-key tag identity, two-process quota, and v11-to-v15 migration profiles
  pass.
- [x] The detached Docker soak runner enforces a 1 GiB no-swap cgroup for the
  complete PHP/S3 process tree, uses a separate bounded PostgreSQL container,
  and retains an independent remote-cleanup watchdog for OOM failures.
- [x] Sustained-load runs reject workload gaps over 120 seconds and use a
  launchd-managed macOS idle-sleep assertion when available.
- [x] The uninterrupted 12-hour external soak passed before updating `main` or
  creating the next release tag.

## 9. Re-Audit Closure

Status: **Complete**

The 2026-07-24 clean-room re-audit repeated the operation-matrix, P0, P1, P2,
framework, database, remote-storage, large-transfer, concurrency, and soak
checks from the beginning. It found and closed additional gaps that were not
covered by the first pass:

- [x] MySQL/PostgreSQL storage-garbage leases can recover after a worker crash.
- [x] Same-backend remote copies reach the backend-native copy operation.
- [x] Standalone portable environment settings reach `S3ServerConfig`.
- [x] Standalone remote S3/Flysystem worker mode is directly configurable.
- [x] Laravel `--storage-path` updates writer and tier registry atomically and is
  rejected for non-filesystem drivers.
- [x] Remote drivers no longer trigger an object-root `mkdir`.
- [x] Backend metrics wrap the tier backends actually used by handlers and
  background workers, including Symfony and programmatic runtime wiring.
- [x] Failed startup and repeated shutdown release resources exactly once.
- [x] Large-transfer tests use a dedicated configurable timeout while normal
  responsiveness and concurrency tests retain the strict 30-second timeout.
- [x] The AWS SDK guard is versioned and classifies every operation exposed by
  AWS SDK 3.389; the newly exposed object-annotation and metadata-annotation
  table operations are explicitly documented as unsupported.
- [x] Lowest and highest supported dependency sets pass Composer security
  auditing, complete-package PHPStan, and the full PHPUnit suite.
- [x] Direct Amp dependencies have enforceable safe minimums: Amp 3.1.2 avoids
  a connection-close crash, DNS 2.4 provides cancellation-aware resolution,
  and HTTP Client 5.3 removes the supported-stack URI deprecation.
- [x] Laravel and Symfony serve commands report bootstrap/configuration failures
  as clean command failures instead of uncaught exceptions.
- [x] Functional copy/listing tests clean their own objects and pass under both
  the configured dependency order and randomized execution.

## Website Hosting Semantics

Status: **Complete; private by default**

Website configuration activates index, error-document, and routing behavior on
the dedicated website host. It does not publish private bucket content. Every
anonymous website `GET` or `HEAD` must be allowed for the requested object by an
`AllUsers` object ACL or a matching bucket policy for `s3:GetObject`.

This follows the Amazon S3 and Linode permission model. Cloudflare R2 differs by
treating activation of a custom domain or managed public URL as the publication
action:

- Amazon S3 website endpoints require publicly readable content:
  <https://docs.aws.amazon.com/AmazonS3/latest/userguide/WebsiteEndpoints.html>
- Amazon documents the additional public-access permission steps:
  <https://docs.aws.amazon.com/AmazonS3/latest/userguide/WebsiteAccessPermissionsReqd.html>
- Linode documents public-read ACLs for public objects:
  <https://techdocs.akamai.com/cloud-computing/docs/using-cyberduck-with-object-storage>
- Cloudflare R2 treats enabling a custom domain or managed public URL as an
  explicit publication action:
  <https://developers.cloudflare.com/r2/buckets/public-buckets/>

Safeguards:

- [x] Only the dedicated website host pattern activates website routing.
- [x] Only `GET` and `HEAD` receive direct website delivery.
- [x] Missing or deleted website configuration immediately returns a website
  404.
- [x] Private objects return `403 AccessDenied` even when website configuration
  exists.
- [x] Anonymous bucket-policy allows and `AllUsers` object ACLs can publish
  objects.
- [x] Explicit denies, `IgnorePublicAcls`, and `RestrictPublicBuckets` override
  public grants.
- [x] Content is read from the object's configured readable tier; cold objects
  require a valid restore.
- [x] Encrypted normal and error documents are denied because the website path
  has no decryption context.
- [x] Redirect protocols, status codes, hosts, and header characters are
  constrained.
- [x] Tests cover private/public access, host isolation, method restrictions,
  disabled state, HEAD, redirects, error documents, and tiered/restore-aware
  reads.
