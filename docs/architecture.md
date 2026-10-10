# Architecture

## Overview

opsfour S3 Server is built on [Amp v3](https://amphp.org/), a non-blocking concurrency framework for PHP. It uses PHP 8.4 Fibers for cooperative multitasking — a single process handles thousands of concurrent connections without threads or child processes.

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
- **File I/O** — `amphp/file` with separate bounded pools for open data
  handles and stateless `mkdir`/rename/stat/delete operations; active uploads
  therefore cannot starve atomic finalization, and both pools shut down explicitly
- **PostgreSQL** — `amphp/postgres` (non-blocking protocol, connection pool)
- **MySQL** — `amphp/mysql` (non-blocking protocol, connection pool)
- **SQLite** — PDO (blocking, optionally offloaded to `amphp/parallel` worker pool)
- **Flysystem** — synchronous adapters; production worker mode offloads every
  adapter call to a bounded `amphp/parallel` process pool
- **DNS** — `amphp/dns` (async resolution for webhook SSRF checks)
- **HTTP client** — `amphp/http-client` (for webhook delivery)

A single server process can handle thousands of concurrent requests because Fibers yield during I/O waits, allowing other requests to proceed.

## Storage Architecture

```
Storage Path Structure (filesystem backend):
/var/data/s3/
├── bucket-name/              # Object data by bucket and key hash
│   └── ab/cd/<unique-path>
├── .parts/<upload-id>/       # Multipart upload parts
├── .assemblies/              # Planned multipart completion paths
└── .tmp/                     # Temporary files for atomic rename
```

The backend writes a temporary file and renames it to a unique final path
before the handler commits the metadata reference. Readers follow committed
metadata, so they do not see a partial file. Failed commits require cleanup of
the unreferenced path. Atomic rename alone does not establish sudden-power-loss
durability; see [Storage Backends](storage-backends.md#requirements).

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

## Multi-Tenancy

Every credential has an `ownerId`. The ownership model is enforced at every level:

- **Bucket creation** — assigns `ownerId` to the bucket
- **Bucket access** — verifies `ownerId` matches on every operation
- **ListBuckets** — filters by `ownerId`
- **Object operations** — inherit bucket ownership

There is no global admin concept. Each tenant sees only their own buckets and objects.

## Handler Registry

Handlers are registered in `HandlerRegistrar::registerAll()` and stored in `HandlerRegistry` (a simple map of operation name → handler callable). Custom handlers can be registered before `start()`:

```php
$server->getHandlerRegistry()->register('PutObject', new MyCustomPutObjectHandler(...));
```
