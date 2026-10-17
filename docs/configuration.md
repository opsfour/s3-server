# Configuration Reference

Portable runtime settings can be configured via environment variables. In
Laravel, they map to `config/s3-server.php`. In Symfony, they can be mirrored in
`config/packages/opsfour_s3_server.yaml` with `%env(...)%` processors; Symfony
service references such as Flysystem filesystems, event listeners, and custom
encryption services are configured in YAML rather than environment variables.

## Network

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_HOST` | `0.0.0.0` | Listen address |
| `S3_PORT` | `9000` | Listen port |
| `S3_REGION` | `us-east-1` | AWS region identifier (returned in location responses) |
| `S3_MAX_CONNECTIONS` | `10000` | Maximum concurrent connections |
| `S3_BODY_SIZE_LIMIT` | `5368709120` | Max request body size in bytes (default 5 GiB) |
| `S3_POST_SPOOL_BUDGET` | `10737418240` | Maximum concurrent browser POST temporary bytes among API processes sharing one local temp directory (default 10 GiB); further uploads receive 503 SlowDown until space is released |
| `S3_BASE_DOMAIN` | - | Base domain for virtual-hosted-style requests (e.g., `s3.example.com`) |
| `S3_STRICT_BUCKET_NAMING` | `true` | Reject AWS-reserved bucket name prefixes and suffixes |

The browser POST spool budget uses locked lease files under
`sys_get_temp_dir()/.opsfour-s3-post-budget` to count active forms across API
processes on one node. Configure the same local temp directory and budget for
all API processes on that node under the same service account. A later
reservation recovers leases and files abandoned by a crashed process. The
coordinator rejects a different budget while another process has an active
form; the value can change after all other reservations are released. Provision
a separate temporary volume or filesystem quota with headroom for other temporary writers
and crash files before recovery. A temporary volume shared between nodes also
needs its own volume limit or a cluster-wide coordinator; local file locks alone
do not establish a cluster-wide limit. Do not remove the reservation directory
while API processes are running. The stale-file sweep also reclaims abandoned
browser POST files older than 24 hours during later uploads.

## TLS

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_TLS_CERT` | - | Path to TLS certificate file (PEM) |
| `S3_TLS_KEY` | - | Path to TLS private key file (PEM) |
| `S3_TRUSTED_PROXY_CIDRS` | empty | Comma-separated exact CIDRs of directly connected trusted proxy peers; enables validated `X-Forwarded-Proto`/`X-Forwarded-For` for policy, rate limits and logs. |

When both are set, the server listens on HTTPS. Use HTTPS for presigned URLs in
production, either directly or through a TLS-terminating trusted proxy.
If TLS terminates at a reverse proxy, configure only its direct peer addresses
in `S3_TRUSTED_PROXY_CIDRS` and block direct access to API nodes. Missing or
ambiguous `X-Forwarded-Proto` is insecure even when the backend connection uses
TLS. S3 and admin requests from a trusted peer require a valid
`X-Forwarded-For`; liveness and readiness probes ignore missing or invalid
chains. The default empty
list ignores forwarded headers.

## Storage

`filesystem` is the storage driver for the current production target.
`flysystem`, remote or shared storage, and `memory` belong to experimental or
test-only profiles until the exact deployment is qualified. See the canonical
[storage support matrix](storage-backends.md).

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_STORAGE_DRIVER` | `filesystem` | Storage backend. `flysystem` and `memory` do not belong to the current production target. |
| `S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS` | `false` | **Unsafe test-only override:** permits API startup with Flysystem or unverified custom storage tiers. Never enable in production; a remote late write may recreate an abandoned path after cleanup. Laravel uses `allow_unfenced_storage_for_tests`; Symfony uses `server.allow_unfenced_storage_for_tests`. |
| `S3_STORAGE_TEMP_DIR` | system temp directory | Local staging directory for Flysystem transfers |
| `S3_STORAGE_TEMP_BUDGET` | `10737418240` (10 GiB) | Maximum concurrent temporary bytes for direct and worker-backed Flysystem staging, including PUT, UploadPart, CopyObject, and multipart assembly, among processes sharing `S3_STORAGE_TEMP_DIR`; writes that exceed the budget receive 503 SlowDown. GET streams directly from a read worker. Worker mode may need space for both its API and worker copy. |
| `S3_FLYSYSTEM_WORKERS` | `0` | Workers per Flysystem pool; remote mode creates separate read and write pools, each with this limit (up to twice this many processes). A positive value is required when evaluating non-blocking remote I/O. It does not qualify that profile for production. |
| `S3_FLYSYSTEM_FIRST_BYTE_TIMEOUT_SECONDS` | `20` | Maximum time for a worker-backed GET to acquire a read worker and produce its first byte; expiry returns 503 SlowDown. At most one extra GET waits per read worker; excess requests receive immediate 503. A third-party adapter blocked in synchronous I/O may retain its worker until that call ends. |
| `S3_FLYSYSTEM_FACTORY_SERVICE` | none | Laravel service ID of a serializable `FlysystemFilesystemFactory` |
| `S3_BACKING_BUCKET` | - | Backing S3 bucket; required by standalone Flysystem mode |
| `S3_BACKING_REGION` | - | Backing S3 region; required by standalone Flysystem mode |
| `S3_BACKING_ACCESS_KEY` | - | Dedicated backing S3 access key; required by standalone Flysystem mode |
| `S3_BACKING_SECRET_KEY` | - | Dedicated backing S3 secret key; required by standalone Flysystem mode |
| `S3_BACKING_ENDPOINT` | AWS default | Optional HTTPS S3-compatible endpoint for Linode, MinIO, R2, and similar providers; malformed endpoints and plaintext HTTP fail startup |
| `S3_BACKING_ALLOW_INSECURE_HTTP_FOR_TESTS` | `false` | **Unsafe test-only override:** permits an HTTP `S3_BACKING_ENDPOINT` for isolated synthetic/local tests. Never enable for production or a non-loopback endpoint. |
| `S3_BACKING_PATH_STYLE` | `false` | Use path-style requests against the backing S3 endpoint |
| `S3_BACKING_PREFIX` | - | Optional key prefix inside the backing bucket |
| `S3_BACKING_CONNECT_TIMEOUT_SECONDS` | `5` | Connection timeout for the built-in backing S3 Flysystem factory |
| `S3_BACKING_READ_TIMEOUT_SECONDS` | `15` | Per-source-read idle timeout for worker-backed S3 GETs; the worker resets it after each chunk. A timeout before API headers returns 503; after headers it truncates the response and releases the read worker. |
| `S3_BACKING_REQUEST_TIMEOUT_SECONDS` | `0` | Optional total backing S3 request timeout; `0` leaves long transfers uncapped. If set, it must be at least the connect timeout. |
| `S3_STORAGE_PATH` | - | Root directory for object data (required for `filesystem`) |
| `S3_REQUEST_BODY_SPOOL_WORKERS` | `8` | Stateless process workers for checksum validation and request-body replay |
| `S3_ENFORCE_MIN_PART_SIZE` | `true` | Require every non-final multipart part to be at least 5 MiB |

See [Storage Backends](storage-backends.md) for driver-specific configuration.
Physical storage tiers are configured as arrays in Laravel config or Symfony
YAML, because each tier needs a named backend and optional service references.

## Metadata

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_METADATA_DRIVER` | `sqlite` | Metadata backend: `sqlite`, `postgres`, or `mysql` |
| `S3_METADATA_DSN` | - | Connection string for postgres/mysql |
| `S3_METADATA_PATH` | `{storagePath}/.opsfour/metadata.sqlite` | Explicit standalone SQLite database path; an existing legacy `{storagePath}/metadata.sqlite` file remains the automatic default, while the presence of both files requires an explicit choice |
| `S3_METADATA_CACHE_TTL` | `5.0` | Finite, non-negative CORS metadata cache TTL in seconds (0 to disable); ownership, policies, Public Access Block, encryption, and versioning are always read from the shared store |

See [Metadata Backends](metadata-backends.md) for driver-specific configuration.

### DSN Formats

```bash
# PostgreSQL
S3_METADATA_DSN="host=localhost port=5432 dbname=s3server user=s3 password=secret"

# MySQL
S3_METADATA_DSN="host=localhost;port=3306;dbname=s3server;user=s3;password=secret"
```

## Credentials

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_CREDENTIALS_DRIVER` | `memory` | Configured provider: `memory`, `database`, or `file`; `chain` is available only through the programmatic factory API |
| `S3_ACCESS_KEY` | - | Access key ID (memory driver) |
| `S3_SECRET_KEY` | - | Secret access key (memory driver) |
| `S3_OWNER_ID` | - | Owner/tenant ID (memory driver) |
| `S3_DISPLAY_NAME` | - | Display name (memory driver) |
| `S3_CREDENTIALS_DSN` | - | PDO DSN (database driver), for example `pgsql:host=db;port=5432;dbname=s3server` |
| `S3_CREDENTIALS_USERNAME` | - | Database username (database driver) |
| `S3_CREDENTIALS_PASSWORD` | - | Database password (database driver) |

When a database credential provider is configured, `/.health` performs a
read-only availability probe against that same provider. Credential database
outages therefore remove the API node from readiness while `/.live` remains
available for process supervision.
| `S3_CREDENTIALS_CACHE_TTL` | `1.0` | Positive lookup cache in seconds; `0` observes cross-node revocations on every request |
| `S3_CREDENTIALS_PATH` | - | JSON file path (file driver) |

See [Authentication](authentication.md) for multi-user setup.

## Admin APIs and External IAM

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_ADMIN_API_TOKEN` | - | Bearer token for protected quota runtime APIs under `/.admin/quotas`; full PUT payloads and paginated listing are documented in [Authentication](authentication.md) |
| `S3_METRICS_BEARER_TOKEN` | - | Optional bearer token required by `/.metrics`; an explicitly empty value fails startup. Strongly recommended unless network policy already isolates the endpoint |
| `S3_EXTERNAL_IAM_ENABLED` | `false` | Enable external OIDC/JWT credential issuing API; explicit values must be boolean `true`/`false` |
| `S3_EXTERNAL_IAM_ADMIN_TOKEN` | - | Bearer token for external credential issuing API; also used as quota admin fallback when `S3_ADMIN_API_TOKEN` is unset |
| `S3_EXTERNAL_IAM_ISSUER` | required when enabled | Expected JWT issuer |
| `S3_EXTERNAL_IAM_AUDIENCE` | required when enabled | Expected JWT audience |
| `S3_EXTERNAL_IAM_JWKS_PATH` | - | Local JWKS file used to verify RS256 JWTs; mutually exclusive with the other single-issuer key sources |
| `S3_EXTERNAL_IAM_PUBLIC_KEY_PATH` | - | PEM public key file used to verify RS256 JWTs; RSA must be at least 2,048 bits |
| `S3_EXTERNAL_IAM_PUBLIC_KEY` | - | Inline PEM public key used to verify RS256 JWTs; RSA must be at least 2,048 bits |
| `S3_EXTERNAL_IAM_OWNER_CLAIM` | `sub` | JWT claim mapped to S3 owner ID |
| `S3_EXTERNAL_IAM_DISPLAY_NAME_CLAIM` | `preferred_username` | JWT claim mapped to credential display name |
| `S3_EXTERNAL_IAM_GROUPS_CLAIM` | `groups` | JWT groups claim |
| `S3_EXTERNAL_IAM_POLICY_NAMES_CLAIM` | - | JWT claim listing policy names to attach |
| `S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM` | - | JWT claim listing allowed object prefixes |
| `S3_EXTERNAL_IAM_OWNER_PREFIX` | - | Prefix prepended to mapped owner IDs |
| `S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS` | `60` | Allowed JWT clock skew; integer from `0` through `3600` |

See [Authentication](authentication.md) for credential provider setup, runtime
quota admin APIs, and external IAM/OIDC examples.

## Quotas

Quota values are hard limits enforced before metadata commits. Set a value to
`0` to disable that limit.

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_QUOTA_MAX_BUCKETS_PER_OWNER` | `0` | Maximum buckets per owner/account |
| `S3_QUOTA_MAX_OBJECTS_PER_BUCKET` | `0` | Maximum stored objects and retained versions per bucket; delete markers excluded |
| `S3_QUOTA_MAX_BYTES_PER_BUCKET` | `0` | Maximum stored object and retained-version bytes per bucket |
| `S3_QUOTA_MAX_BYTES_PER_OWNER` | `0` | Maximum stored object bytes owned by one account across all buckets |
| `S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_BUCKET` | `0` | Maximum active multipart uploads per bucket |
| `S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_OWNER` | `0` | Maximum active multipart uploads across all buckets for one owner/account |
| `S3_QUOTA_MAX_MULTIPART_BYTES_PER_BUCKET` | `0` | Maximum staged multipart bytes per bucket |
| `S3_QUOTA_MAX_MULTIPART_BYTES_PER_OWNER` | `0` | Maximum staged multipart bytes across all buckets for one owner/account |

Per-account overrides are stored in metadata and can be managed through the
admin API, Laravel Artisan command, or Symfony console command. Normal bucket
and owner byte limits also include staged multipart data, preventing incomplete
uploads from bypassing storage quotas. Bucket limits use the bucket owner's
configuration and count objects and uploads from every account in that bucket.
Owner limits use the object's or multipart upload's owner, including writes into
another account's bucket. Stored byte totals include retained object versions.

## Encryption

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_ENCRYPTION_MASTER_KEY` | - | Single master key (base64, 32 bytes decoded) |
| `S3_ENCRYPTION_MASTER_KEYS` | - | Multi-key JSON for rotation (see below) |
| `S3_MASTER_KEY_PROVIDER` | `config` | Key provider: `config`, `redis`, or `vault` |
| `S3_MAX_ENCRYPTED_OBJECT_SIZE` | `268435456` | Max direct SSE-S3/SSE-C PutObject/CopyObject size (256 MiB); budget at least 1.5 GiB PHP `memory_limit` per API process at the default cap, or lower this limit. Runtime memory headroom can reject smaller objects on constrained workers. Multipart staging has separate limits. |

### Key Rotation Format

```bash
# Multi-key: first key is active, others decrypt historical data
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"base64encodedKey...","key-2024":"base64encodedOldKey..."}'
```

See [Encryption](encryption.md) for key rotation procedures.

`aws:kms` request headers and bucket defaults fail closed with
`NotImplemented`. A real KMS adapter is not bundled. Redis and Vault are master
key sources for the built-in SSE-S3 envelope encryption and are not AWS KMS API
emulators.

### Redis Key Provider

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_REDIS_MASTER_KEY_DSN` | - | Redis connection string |
| `S3_REDIS_MASTER_KEY_NAME` | `s3:master-key` | Redis key for legacy single-key |

For multi-key mode, store the key map in the `s3:master-keys` hash and the
required active key ID in `s3:active-key-id`.

### Vault Key Provider

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_VAULT_ADDR` | - | Vault server URL (must be HTTPS) |
| `S3_VAULT_TOKEN` | - | Vault authentication token |
| `S3_VAULT_PATH` | `secret/data/s3-server/master-key` | KV v2 secret path |
| `S3_VAULT_KEY_FIELD` | `key` | Field name in secret data |

For multi-key mode, the KV v2 data must contain `keys` and an `activeKeyId`
which names one entry. Vault responses are bounded to 1 MiB.

## Rate Limiting

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_RATE_LIMIT` | `1000` | Max requests per second per IP (0 = unlimited) |

Rate limit state is persisted in the metadata database and survives server
restarts. PostgreSQL and MySQL can share rate-limit state when evaluating an
experimental multi-process or multi-host profile; that capability alone does
not qualify the topology. An enabled limit fails closed with S3 `SlowDown`
(503) when its database check fails; `/.health` reports degraded after its next
rate-limit probe (within 30 seconds), while `/.live` remains available. With
`S3_RATE_LIMIT=0`, no rate-limit database check runs.

Management requests under `/.admin/*` and `/.metrics` also fail closed when
their rate-limit check fails. Downstream handler, backend, and rate-limit
errors retain the management JSON or plain-text format, include
`Cache-Control: no-store`, and do not expose backend details. The shared HTTP
framing and header-contract check runs before management routing and still
returns S3 XML for malformed transport requests. Reserved management paths
return 404 for unknown subpaths or disabled admin APIs and 405 with `Allow`
for unsupported methods; they never fall through to S3 bucket or object
routing.

## Notifications

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_NOTIFICATION_REQUIRE_HTTPS` | `true` | Reject plaintext HTTP webhook configurations and redirects; the worker also blocks legacy stored HTTP destinations |
| `S3_NOTIFICATION_WORKER` | `true` | Process queued webhooks on this server instance; set to `false` only when another worker instance drains the shared queue |

The standalone command reads this environment variable directly. Laravel
reads it through the published `s3-server.php` config. For Symfony, set
`opsfour_s3_server.notifications.worker_enabled` in the bundle configuration.

Keep HTTPS enforcement enabled in production. Setting it to `false` only
relaxes the transport requirement; private, reserved, loopback, and link-local
destinations remain blocked by the notification SSRF policy.

## Timeouts

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_CONNECTION_IDLE_TIMEOUT` | `60` | HTTP/2 idle connection timeout (seconds) |
| `S3_READ_TIMEOUT` | `300` | Request stream inactivity timeout (seconds) |
| `S3_WRITE_TIMEOUT` | `300` | Response stream inactivity timeout (seconds) |
| `S3_SHUTDOWN_DRAIN_TIMEOUT` | `30` | Seconds before warning that graceful shutdown is still draining |

Amp's HTTP/1 driver currently uses one inactivity timeout for keep-alive,
request reads, and response writes. The package sets that value to the larger
of `S3_READ_TIMEOUT` and `S3_WRITE_TIMEOUT`, so the HTTP/1 keep-alive timeout is
300 seconds with these defaults. `S3_CONNECTION_IDLE_TIMEOUT` applies to
HTTP/2 connections. Set both stream timeouts to the desired HTTP/1 inactivity
limit if a shorter keep-alive period is required; this also limits pauses
during active transfers.

## Lifecycle Processing

The lifecycle runner is wired into the runtime for standalone, Laravel, and
Symfony entry points. Work is bounded per sweep so large buckets are cleaned or
transitioned incrementally.

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_LIFECYCLE_INTERVAL_SECONDS` | `60` | Seconds between lifecycle sweeps |
| `S3_LIFECYCLE_BATCH_SIZE` | `1000` | Maximum rows fetched per lifecycle query |
| `S3_LIFECYCLE_MAX_ACTIONS_PER_RUN` | `1000` | Maximum destructive or mutating actions per sweep |
| `S3_LIFECYCLE_LOCK_TTL_SECONDS` | `300` | Metadata-backed lease TTL for multi-node runners |
| `S3_MULTIPART_MAX_AGE_SECONDS` | `604800` | Global maximum age for incomplete multipart uploads; exact seconds, `0` disables global cleanup |

Lifecycle rules can expire objects, clean noncurrent versions, abort incomplete
multipart uploads, and move data between configured physical storage tiers. See
[Lifecycle Rules](lifecycle.md) and [Storage Backends](storage-backends.md).
Each transition `StorageClass` must match a configured physical tier when the
rule is saved; otherwise the S3 API rejects the replacement.

## S3 Select

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_MAX_SELECT_OBJECT_SIZE` | `268435456` | Configured object cap for S3 Select (256 MiB). The buffered-memory guard currently imposes a lower effective input ceiling of 64 MiB or less, depending on PHP memory headroom. Short rows and expanded output may be rejected at smaller sizes; see [S3 Select](s3-select.md). |

## Website Hosting

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_WEBSITE_HOST_PATTERN` | - | Host pattern for website-enabled buckets (e.g., `{bucket}.s3-website.example.com`) |

Website configuration activates routing for the dedicated website host, but it
does not make a private object public. Anonymous website `GET` and `HEAD`
requests require either an `AllUsers` object ACL with `READ` or a matching
bucket-policy `Allow` for `s3:GetObject`. Explicit policy denies and
`IgnorePublicAcls`/`RestrictPublicBuckets` are enforced. This matches the
permission model used by Amazon S3 and Linode website hosting.

## Parallelism

| Variable | Default | Description |
|----------|---------|-------------|
| `S3_SQLITE_WORKERS` | `0` | Number of Amp parallel workers for SQLite (0 = blocking mode) |
| `S3_ENCRYPTION_WORKERS` | `0` | Number of Amp parallel workers for encryption (0 = inline) |
| `S3_SELECT_WORKERS` | `S3_ENCRYPTION_WORKERS` | Number of Amp parallel workers for S3 Select (0 = inline) |
| `S3_ENCRYPTION_THRESHOLD` | `65536` | Object size threshold for offloading encryption to workers (bytes) |

Setting `S3_SQLITE_WORKERS` > 0 wraps SQLite in a `ParallelSqliteMetadataStore` that dispatches queries to a worker pool, preventing SQLite's single-writer lock from blocking the event loop.

## Example .env

```bash
# Network
S3_HOST=0.0.0.0
S3_PORT=9000
S3_REGION=us-east-1

# Storage
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/var/data/s3

# Metadata (production)
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"

# Credentials
S3_CREDENTIALS_DRIVER=database
S3_CREDENTIALS_DSN="pgsql:host=db.internal;port=5432;dbname=s3server"
S3_CREDENTIALS_USERNAME=s3
S3_CREDENTIALS_PASSWORD=secret
S3_CREDENTIALS_CACHE_TTL=1

# Admin APIs
S3_ADMIN_API_TOKEN=change-me
S3_METRICS_BEARER_TOKEN=change-metrics-token

# Quotas
S3_QUOTA_MAX_BUCKETS_PER_OWNER=0
S3_QUOTA_MAX_OBJECTS_PER_BUCKET=0
S3_QUOTA_MAX_BYTES_PER_BUCKET=0
S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_BUCKET=0
S3_QUOTA_MAX_MULTIPART_BYTES_PER_BUCKET=0
S3_QUOTA_MAX_BYTES_PER_OWNER=0

# Lifecycle
S3_LIFECYCLE_INTERVAL_SECONDS=60
S3_LIFECYCLE_BATCH_SIZE=1000
S3_LIFECYCLE_MAX_ACTIONS_PER_RUN=1000
S3_LIFECYCLE_LOCK_TTL_SECONDS=300

# Encryption
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"BASE64_ENCODED_32_BYTE_KEY"}'

# Rate limiting
S3_RATE_LIMIT=1000

# TLS
S3_TLS_CERT=/etc/ssl/certs/s3.pem
S3_TLS_KEY=/etc/ssl/private/s3.key
S3_TRUSTED_PROXY_CIDRS=10.0.1.10/32

# Timeouts
S3_SHUTDOWN_DRAIN_TIMEOUT=30
```
