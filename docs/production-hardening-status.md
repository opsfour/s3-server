# Production Hardening Status

This document records the remediation work from the full server audit completed
on 2026-07-23. Historical feature scope remains in
`production-feature-roadmap.md`; exact release commands and measured results are
in `release-checklist.md`.

## Release Decision

Status: **Release gates complete**

The default suite, external Linode storage, PostgreSQL 16, MySQL 8.0,
large-file, high-concurrency, and five-minute soak profiles pass. AWS STS Query
API compatibility remains optional and unsupported.

## 1. Authorization

Status: **Complete**

- [x] Unmapped ACL operations fail closed for non-owners unless identity or
  bucket policy explicitly allows access.
- [x] Bucket creation requires authentication and cannot create anonymous
  ownership records.
- [x] All 66 routed operations have explicit IAM action and ACL behavior,
  including `PostObject`.
- [x] Public Access Block is enforced for ACL writes and public access.
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
- [x] Restore completion emits `s3:ObjectRestore:Completed` after commit.

## 6. Streaming Storage and Integrity

Status: **Complete**

- [x] `Content-MD5` and modern S3 checksums are calculated with bounded
  streaming spools.
- [x] Large range and object responses remain streamed.
- [x] Synchronous Flysystem adapters run in bounded process workers.
- [x] Filesystem data handles and stateless filesystem operations use separate
  bounded pools to prevent recursive-create and rename starvation.
- [x] Worker downloads honor cancellation.
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
- [x] Metadata cache invalidation is covered for all mutation categories.
- [x] Supported database versions, migration behavior, and isolation
  requirements are documented.
- [x] Migration, round-trip, observability, and concurrent quota tests pass
  against PostgreSQL 16 and MySQL 8.0 instances.

SQLite uses schema version `11`. PostgreSQL and MySQL use schema version `12`
because their multi-process quota path requires `s3_owner_write_locks`.

## 8. Release Verification

Status: **Complete**

- [x] README claims are bounded by the documented 66-operation API and policy
  compatibility matrices.
- [x] AWS STS Query API is explicitly optional and unsupported.
- [x] PHPStan passes over the complete package.
- [x] PHP CS Fixer reports no changes across 352 files.
- [x] The complete default suite passes: 598 tests, 2,917 assertions, 9 expected
  skips, 0 failures.
- [x] A 256 MiB streamed PUT/GET and 128 MiB multipart upload pass.
- [x] A 200-object workload at 100 concurrent requests passes.
- [x] The five-minute production soak passes without stale responses, temporary
  file leakage, or RSS threshold breach.
- [x] The external Linode Flysystem profile passes.
- [x] Composer validation, dependency audit, and diff whitespace checks pass.
- [x] PostgreSQL 16 and MySQL 8.0 fresh-schema, round-trip, observability,
  two-process quota, and v11-to-v12 migration profiles pass.

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
