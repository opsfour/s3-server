# Architecture

## Overview

opsfour S3 Server is built on [Amp v3](https://amphp.org/), a non-blocking
concurrency framework for PHP. It uses PHP 8.4 Fibers for cooperative
multitasking. The API event loop may also use bounded child-process pools for
blocking SQLite, encryption, checksum, S3 Select, temporary-file, or
experimental Flysystem work.

```
┌─────────────────────────────────────────────────────────┐
│                    Amp HTTP Server                      │
│                                                         │
│  ┌──────────────────────────────────────────────────┐   │
│  │              Middleware Stack                    │   │
│  │                                                  │   │
│  │  RequestId → ErrorHandling → Logging →           │   │
│  │  ExpectContinue → RateLimit → S3Attribute →      │   │
│  │  WebsiteHosting → CORS → [Auth] →                │   │
│  │  Policy → ACL → ContentMD5 → Checksum            │   │
│  │                                                  │   │
│  └──────────────────┬───────────────────────────────┘   │
│                     │                                   │
│  ┌──────────────────▼───────────────────────────────┐   │
│  │           S3DispatchHandler                      │   │
│  │  OperationResolver → HandlerRegistry → Handler   │   │
│  └──────────────────┬───────────────────────────────┘   │
│                     │                                   │
│  ┌─────────┐  ┌─────▼────┐  ┌────────────────────┐      │
│  │ Storage │  │ Metadata │  │ Background Fibers  │      │
│  │ Backend │  │  Store   │  │ - Lifecycle runner │      │
│  │         │  │          │  │ - Notif processor  │      │
│  │ - FS    │  │ - SQLite │  │ - Cleanup (60s)    │      │
│  │ - Fly   │  │ - PG     │  │                    │      │
│  │ - Mem   │  │ - MySQL  │  │                    │      │
│  └─────────┘  └──────────┘  └────────────────────┘      │
└─────────────────────────────────────────────────────────┘
```

The current production target uses one API process, a persistent local
filesystem, and SQLite, PostgreSQL, or MySQL metadata. The Flysystem and memory
drivers shown in the component model are experimental or test-only deployment
profiles; see [Storage Backends](storage-backends.md).

## Request Lifecycle

1. **TCP connection** accepted by Amp's `SocketHttpServer`
2. **HTTP parsing** by Amp's HTTP driver (supports HTTP/1.1, chunked encoding, Expect: 100-continue)
3. **Router** matches the path: `/.health`, `/`, `/{bucket}`, or `/{bucket}/{key:.+}`
4. **Middleware stack** processes the request top-to-bottom:
   - `RequestIdMiddleware` — assigns a UUID
   - `ErrorHandlingMiddleware` — catches S3Exceptions, returns XML errors
   - `LoggingMiddleware` — logs request/response with timing
   - `ExpectContinueMiddleware` — removes a handled `Expect: 100-continue`
     header after Amp's HTTP driver has emitted the interim response
   - `RateLimitMiddleware` — per-IP token bucket check (database-backed)
   - `S3AttributeMiddleware` — parses bucket/key from path, resolves S3 operation name
   - `WebsiteHostingMiddleware` — authorizes and serves anonymous static
     website content before credential authentication
   - `CorsMiddleware` — handles CORS preflight and response headers
   - **[Auth middleware]** — SigV4 verification, sets ownerId
   - `PolicyEnforcementMiddleware` — evaluates IAM-style bucket policies
   - `AclEnforcementMiddleware` — evaluates S3 ACLs
   - `BufferedReadAuthorizationMiddleware` — rechecks current policies, ACLs,
     Public Access Block settings, and bucket generation under shared owner
     locks before returning buffered service, bucket, listing, and multipart
     listing responses
   - `CurrentObjectReadAuthorizationMiddleware` — binds object reads to the
     authorized object snapshot and rechecks authorization before response
     release
   - `ContentMd5Middleware` — validates Content-MD5 header
   - `ChecksumValidationMiddleware` — validates x-amz-checksum-* headers
5. **S3DispatchHandler** resolves the operation name and dispatches to the correct handler
6. **Handler** executes the operation (interacts with metadata store and storage backend)
7. **Response** flows back up through the middleware stack

## Two-Tier Routing

S3 uses an unusual routing scheme where the same path can map to different operations based on query parameters. The server uses two-tier routing:

1. **Amp Router** (3 path patterns):
   - `GET /` → ListBuckets
   - `{METHOD} /{bucket}` → bucket operations
   - `{METHOD} /{bucket}/{key:.+}` → object operations

2. **OperationResolver** (query parameter dispatch):
   - `GET /{bucket}?versioning` → GetBucketVersioning
   - `GET /{bucket}?acl` → GetBucketAcl
   - `PUT /{bucket}?lifecycle` → PutBucketLifecycle
   - etc.

The `S3AttributeMiddleware` sets the resolved operation as a request attribute, which `S3DispatchHandler` uses for handler lookup.

## Async I/O Model

Request-path I/O uses PHP Fibers:

- **HTTP** — Amp HTTP Server (event-loop driven)
- **File I/O** — `amphp/file` uses separate bounded data and metadata pools;
  blocking file and directory synchronization, create-only links, and durable
  deletes run in a dedicated lazy durability worker pool. All owned pools shut
  down explicitly.
- **PostgreSQL** — `amphp/postgres` (non-blocking protocol, connection pool)
- **MySQL** — `amphp/mysql` (non-blocking protocol, connection pool)
- **SQLite** — PDO, offloaded to an `amphp/parallel` worker pool by default for
  file-backed serving; `0` workers and `:memory:` use the blocking mode
- **Database credentials** — PDO, isolated in a bounded worker pool with a
  per-operation deadline for serving; management commands remain synchronous
- **Flysystem** — synchronous adapters; experimental worker mode offloads every
  adapter call to a bounded `amphp/parallel` process pool
- **DNS** — `amphp/dns` (async resolution for webhook SSRF checks)
- **HTTP client** — `amphp/http-client` (for webhook delivery)

Fibers let other requests proceed while supported I/O waits. Capacity depends
on the endpoint, backend, object size, worker limits, PHP configuration, and
host; the package does not make a general throughput claim.

## Storage Architecture

```
Storage Path Structure (filesystem backend):
/var/data/s3/
├── bucket-name/              # Object data by bucket and key hash
│   └── ab/cd/<unique-path>
├── .parts/<upload-id>/       # Multipart upload parts
├── .assemblies/              # Planned multipart completion paths
└── .tmp/                     # Temporary files for durable publication
    └── object-writes/        # Private staging for object and multipart-part writes
```

The backend writes and synchronizes a temporary file, creates a unique final
path with a create-only hard link, synchronizes the target directory, and then
durably removes the temporary name before the handler commits the metadata
reference. Readers follow committed metadata, so they do not see a partial
file. Normal local object writes reserve a durable metadata intent before the
physical path can be published. If a process exits between publication and the
metadata commit, the intent collector removes or seals the exact path on the
next run. Qualification for sudden power loss still requires the exact
filesystem and mount to pass the crash-recovery gate; see
[Storage Backends](storage-backends.md#requirements).

## Encryption Architecture

SSE-S3 uses envelope encryption:

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│  Master Key  │────▶│  Data Key    │────▶│  Object Data │
│  (config)    │ enc │  (per-object)│ enc │  (ciphertext)│
└──────────────┘     └──────────────┘     └──────────────┘
```

The encrypted data key blob includes a version byte and key ID, enabling seamless master key rotation without re-encrypting existing objects.

## Notification Architecture

```
Request Fiber                    Background Fiber
     │                                │
  PutObject                    NotificationProcessor
     │                                │
  handler                         loop()
     │                                │
  dispatch()                    dequeueNotifications()
     │                                │
  enqueueNotification()          process()
     │ (INSERT, <1ms)                 │
     │                          resolveAndValidate()
     ▼                                │
  Response                       sendWebhook()
                                      │
                                 updateStatus()
```

This diagram describes configured HTTP(S) webhooks. Their queue insertion is
synchronous; the processor runs as a background Fiber with its own polling
loop. Internal listeners and optional Redis/Kafka/AMQP/NATS broker adapters
run in-process without this persistent queue or its retry schedule.

## Identity, Accounts, and Administration

Every credential has an `ownerId`. The ownership model is enforced at every level:

- **Bucket creation** — assigns `ownerId` to the bucket
- **Bucket access** — verifies `ownerId` matches on every operation
- **ListBuckets** — filters by `ownerId`
- **Object operations** — use bucket and object ownership plus policy and ACL
  evaluation

Signed cross-account access requires an identity-side Allow and resource-side
consent through a bucket policy or applicable ACL. Explicit Deny takes
precedence. See [Policy Compatibility](policy-compatibility.md) for the exact
surface.

The S3 data plane is separate from the optional administration plane under
`/.admin/*`. Admin endpoints use their configured bearer or OIDC protection and
manage credentials, policies, and quotas; they do not bypass S3 authorization
for object requests. Keep admin endpoints private in production.

## Handler Registry

Handlers are registered in `HandlerRegistrar::registerAll()` and stored in `HandlerRegistry` (a simple map of operation name → handler callable). Custom handlers can be registered before `start()`:

```php
use OpsFour\S3Server\Routing\S3Operation;

$server->getHandlerRegistry()->register(S3Operation::GetObject, new MyCustomGetObjectHandler(...));
```

Use `S3ServerRuntimeFactory` for a complete embedded server. Direct `S3Server`
construction remains a guarded low-level extension point for custom or read-only
graphs. Registering a standard filesystem publication operation (PUT, browser
POST, CopyObject, UploadPart, UploadPartCopy, or CompleteMultipartUpload) requires
the exact factory-owned local writer graph; startup validates the frozen registry
before taking storage locks or binding a listener. The unsafe storage override is
test-only and is rejected by cluster-mode composition.
