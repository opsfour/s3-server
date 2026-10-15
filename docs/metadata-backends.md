# Metadata Backends

The metadata backend stores all non-data state: bucket records, object metadata, ACLs, policies, tags, lifecycle rules, notification configs, versioning state, multipart upload tracking, rate limit tokens, and notification queue items.

## SQLite (Default)

Best for development and single-node production deployments.

```bash
S3_METADATA_DRIVER=sqlite
S3_STORAGE_PATH=/var/data/s3   # metadata.sqlite is created here
```

The database file is created at `{storagePath}/metadata.sqlite` on first start. Schema migrations run automatically.

### SQLite Configuration

SQLite is configured with these PRAGMAs for production performance:

- `journal_mode = WAL` — Write-ahead logging for concurrent reads
- `synchronous = FULL` — Durability guarantee
- `foreign_keys = ON` — Referential integrity
- `busy_timeout = 5000` — Wait up to 5s for locks
- `cache_size = -64000` — 64 MB page cache
- `mmap_size = 268435456` — 256 MB memory-mapped I/O

### SQLite Worker Pool

SQLite's single-writer limitation can block the Amp event loop. For high-throughput scenarios, enable the worker pool:

```bash
S3_SQLITE_WORKERS=4
```

This wraps SQLite operations in `amphp/parallel` workers, preventing lock contention from blocking HTTP request handling. Each worker maintains its own PDO connection and prepared statement cache.

### When to Use SQLite

- Development and testing
- Single-node production with < 1000 requests/second
- Archival servers with bursty read patterns

## PostgreSQL

Designed for multi-node production metadata. A shared metadata database does
not by itself qualify the selected object-storage backend for multiple hosts.

```bash
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"
```

PostgreSQL API processes require the PHP `pq` extension (`pecl-pq`, which
depends on `raphf`). The server refuses to start with only `ext-pgsql`.
The server uses this driver because abrupt database disconnects are not safely
recoverable through the event-loop backend selected by `ext-pgsql` alone.
Direct metadata migration tools do not start the API and may still use
`ext-pgsql`.

### Connection Pool

The Postgres backend uses `amphp/postgres` connection pooling. All queries are
non-blocking and executed via PHP Fibers. `amphp/postgres` selects `pecl-pq`
when it is loaded.

### HA Setup

The supported baseline is PostgreSQL 14 or newer with the default
`READ COMMITTED` isolation level. Use PostgreSQL streaming replication or a
managed service (RDS, Cloud SQL, etc.):

```bash
# Primary + read replicas behind a connection pooler (PgBouncer)
S3_METADATA_DSN="host=pgbouncer.internal port=6432 dbname=s3server user=s3 password=secret"
```

### When to Use PostgreSQL

- Multi-node deployments
- High-availability requirements
- > 1000 requests/second sustained
- Shared rate limiting across nodes using metadata-database time for refill and cleanup
- A failed rate-limit database check returns S3 `SlowDown` (503) when the limit is enabled; readiness probes that write path every 30 seconds
- Notification queue with multi-node processing

## MySQL

Alternative to PostgreSQL for teams with MySQL expertise.

```bash
S3_METADATA_DRIVER=mysql
S3_METADATA_DSN="host=db.internal;port=3306;dbname=s3server;user=s3;password=secret"
```

Uses `amphp/mysql` connection pooling with the same non-blocking Fiber-based
execution as Postgres. The supported baseline is MySQL 8.0 or newer with
InnoDB. The default `REPEATABLE READ` isolation level is supported; owner-scoped
write-lock rows serialize quota-sensitive writes across nodes. MariaDB 10.6 has
disposable baseline coverage, and MariaDB 11.8 passed the latest migration,
notification-lease, multipart-cleanup, and two-node profiles. Production
rollout and distributed MySQL variants remain untested.

The `transaction()` convenience method can be called from normal synchronous
CLI code or from an Amp Fiber. Direct `beginTransaction()`, `commit()`, and
`rollback()` calls require an Amp Fiber so the connection remains bound to the
calling request. A second direct `beginTransaction()` fails without changing
the active transaction. Nest with `transaction()` instead: nested callbacks
join the outer transaction without a savepoint, and only the outer owner
commits or rolls back.

### When to Use MySQL

- Teams already operating MySQL infrastructure
- MySQL-managed services that preserve InnoDB transactions and row locks
- Same capabilities as PostgreSQL backend

## Schema Migrations

All backends track a schema version. SQLite can initialize its local schema on
first start. For PostgreSQL and MySQL, run one `php bin/s3-server
metadata:migrate` bootstrap with DDL rights before starting API nodes; serving
nodes only verify the current version. The command reads
`S3_METADATA_DRIVER` and `S3_METADATA_DSN` from its environment. Do not run
multiple migrators concurrently, and stop old API/workers before a schema
upgrade. If database-backed credentials are configured, run the separate
`credentials:migrate` bootstrap before serving; it can use a different PDO
DSN and DDL-capable role. See the [deployment order](deployment.md#rollout-and-rollback).

| Version | Changes |
|---------|---------|
| 1 | Core tables: buckets, objects, multipart uploads, parts, ACLs, tags, policies, CORS, encryption, lifecycle, lock, retention, legal holds, notifications, website, PAB, logging |
| 2 | Lifecycle and noncurrent indexes |
| 3 | Foreign key constraint tracking |
| 4 | Rate limit buckets and notification queue tables |
| 5 | Per-account quotas |
| 6 | Distributed lease locks |
| 7 | Lifecycle checkpoints |
| 8 | Account and named IAM policies |
| 9 | Physical tier placement and restore state |
| 10 | Durable tier-transition jobs |
| 11 | Durable restore jobs |
| 12 | PostgreSQL/MySQL owner-scoped write locks |
| 12 (SQLite) / 13 (PostgreSQL/MySQL) | Version-aware object tags and ACL resource data |
| 13 (SQLite) / 14 (PostgreSQL/MySQL) | Multipart upload-count and staged-byte quotas |
| 14 (SQLite) / 15 (PostgreSQL/MySQL) | Durable physical storage garbage queue |
| 16 (MySQL) | Byte-exact object keys and version IDs; full-key identity for long keys |
| 15 (SQLite) / 16 (PostgreSQL) / 17 (MySQL) | Index object owners for account quotas |
| 16 (SQLite) / 17 (PostgreSQL) / 18 (MySQL) | Index owner bucket names for bounded listing |
| 17 (SQLite) / 18 (PostgreSQL) / 19 (MySQL) | Bucket Object Ownership controls |
| 18 (SQLite) / 19 (PostgreSQL) / 20 (MySQL) | Index object storage tiers and pending transition targets for API startup validation; PostgreSQL/MySQL upgrades also restore the tier-state index missing from fresh schemas |
| 19 (SQLite) / 20 (PostgreSQL) / 21 (MySQL) | Assign an immutable random generation to each bucket; backfill existing buckets so access logs cannot cross same-owner name reuse |
| 20 (SQLite) / 21 (PostgreSQL) / 22 (MySQL) | Add typed, durable multipart-abort work to the storage garbage queue for large `DeleteBucket` backlogs |
| 23 (MySQL) | Make owner, policy-name, lock, and ACL-principal identifiers byte-exact |
| 21 (SQLite) / 22 (PostgreSQL) / 24 (MySQL) | Record tier-job origin, lifecycle rule, and expected current/noncurrent role; old jobs are marked `legacy` and retired safely by the new worker |
| 22 (SQLite) / 23 (PostgreSQL) / 25 (MySQL) | Record generation-scoped multipart assembly write intents so uncommitted final paths can be reclaimed after process failure |
| 23 (SQLite) / 24 (PostgreSQL) / 26 (MySQL) | Index retained multipart-intent storage tiers for startup and readiness inventory |
| 24 (SQLite) / 25 (PostgreSQL) / 27 (MySQL) | Snapshot the owner of each durable multipart-part write intent and index `(operation, owner_id)` for bounded per-owner admission; backfill only intents whose upload ID and generation still match a live upload |
| 25 (SQLite) / 26 (PostgreSQL) / 28 (MySQL) | Add a durable claim generation to notification, tier-transition, restore, and storage-garbage queues so a reclaimed lease cannot share its predecessor's fence token |
| 26 (SQLite) / 27 (PostgreSQL) / 29 (MySQL) | Index multipart completion intents by owner for atomic admission of at most 4,096 retained attempts per owner |
| 27 (SQLite) / 28 (PostgreSQL) / 30 (MySQL) | Add pathwise object-write intents with a unique tier/path digest, owner and due indexes, and retained sealed state for a future provider-specific write fence |
| 29 (PostgreSQL) | Add C-collated listing indexes so S3 object, version, multipart, and lifecycle pagination can use UTF-8 byte order under non-C database collations |
| 31 (MySQL) | Make lifecycle rule, lifecycle checkpoint, and notification configuration IDs byte-exact while preserving existing UTF-8 IDs up to 255 characters |
| 28 (SQLite) / 30 (PostgreSQL) / 32 (MySQL) | Mark pre-upgrade object-write intents as possibly dispatched and add a nullable one-shot dispatch token for new reservations. Expired never-dispatched reservations can be retired without a seal; armed writes require token-bound publication. |
| 29 (SQLite) / 31 (PostgreSQL) / 33 (MySQL) | Pin each new multipart upload to its physical storage tier; legacy uploads need a verified tier or drain before API startup. |
| 30 (SQLite) / 32 (PostgreSQL) / 34 (MySQL) | Persist the physical tier of restored object copies; legacy restored paths need verified mapping before serving. |
| 31 (SQLite) / 33 (PostgreSQL) / 35 (MySQL) | Fence multipart completion collector claims with a durable claim generation. |
| 32 (SQLite) / 34 (PostgreSQL) / 36 (MySQL) | Add one-shot dispatch tokens to local multipart part and completion intents. |
| 33 (SQLite) / 35 (PostgreSQL) / 37 (MySQL) | Add a transactional global retained-completion counter, backfill existing intents, and enforce the one-million-attempt cap on inserts. |
| 34 (SQLite) / 36 (PostgreSQL) / 38 (MySQL) | Add separate transactional object- and multipart-part-intent capacity counters, backfill and validate both, and maintain them through insert, delete and part-operation changes. |
| 35 (SQLite) / 37 (PostgreSQL) / 39 (MySQL) | Enforce the supported bucket default encryption shape (`AES256`, no KMS key ID, Bucket Key disabled) with a database CHECK after a key-free invalid-row preflight. |
| 36 (SQLite) / 38 (PostgreSQL) / 40 (MySQL) | Persist when an object version becomes noncurrent, backfill legacy noncurrent rows with the migration timestamp, and index lifecycle age queries without treating object creation as the start of `NoncurrentDays`. |
| 41 (MySQL) | Widen `restore_expires_at` from `TIMESTAMP` to `DATETIME` so every accepted RestoreObject duration remains representable. |
| 37 (SQLite) / 39 (PostgreSQL) / 42 (MySQL) | Index cleanup operations that are unsafe for local multi-host admission. SQLite and PostgreSQL use partial indexes; MySQL uses a byte-exact virtual classifier plus a secondary index. |
| 38 (SQLite) / 40 (PostgreSQL) / 43 (MySQL) | Persist the verified algorithm and value of each optional UploadPart checksum so retries replace both fields atomically and ListParts can return them. |
| 39 (SQLite) / 41 (PostgreSQL) / 44 (MySQL) | Preserve optional CORS rule IDs and distinguish an omitted `MaxAgeSeconds` from explicit zero. Legacy zero rows retain their previous omitted meaning. MySQL also widens website document/redirect fields and logging prefixes to `MEDIUMTEXT` for parity with the one-MiB control-plane request limit. |
| 42 (PostgreSQL) | Add a C-collated account-quota owner index so cursor pagination keeps UTF-8 byte order under non-C database collations. |
| 45 (MySQL) | Widen stored CORS rule IDs to 1,020 bytes so the public 255-Unicode-character limit is portable across metadata backends. |

SQLite currently uses schema version `39`; PostgreSQL uses `42`, and MySQL
uses `45`. SQLite applies each migration version in a transaction. PostgreSQL DDL is
retry-safe and records the new version only after every statement succeeds.
MySQL DDL auto-commits, so partially applied migrations are retried
idempotently; duplicate schema objects are tolerated, while every other error
stops startup before the version is recorded. Schema upgrades may add tables,
indexes, and columns with defaults, but do not delete application data.
MySQL v31 changes three indexed identifier columns and may wait for metadata
locks or rebuild their indexes on a populated database. The v32 dispatch-token
column also needs a migration-time and lock check on a representative copy.
MySQL v45 widens the CORS rule ID column and can likewise wait for metadata
locks or rebuild the table; measure it on a representative copy.
The completion, object and part counter migrations count retained rows and
create DML triggers. Stop all old API nodes and workers before their backfill;
MySQL DDL auto-commits and does not provide a hot rolling cutover. The
migrations require trigger-creation rights and a durable MySQL trigger
definer. Startup verifies each counter against its table with a full count,
so measure this check on a representative large database. Schedule the first
startup after this upgrade during a maintenance window.
The encryption-constraint migration refuses contradictory legacy rows before
DDL and never prints their key IDs. Repair those rows deliberately, then rerun
the single migrator. PostgreSQL replaces the named CHECK under one transactional
table lock on a partial retry; MySQL tolerates only its matching duplicate-CHECK
error after an auto-committed retry.
The noncurrent-time migration deliberately does not derive missing values from
`created_at`: existing noncurrent rows start their retention clock at the
migration statement time. Validate migration duration, locks, and backfill
results against a representative copy before rollout.
The PostgreSQL serving guard also checks that all five object/part capacity
triggers remain active. MySQL checks them with the DDL-capable migration role
before publishing the schema version. Its DML-only serving role cannot read
`information_schema.TRIGGERS` without the `TRIGGER` privilege, so a later
trigger removal is an open operational detection gap; recheck the trigger
inventory with the migration role after database restores or privilege changes.

Custom metadata stores that implement `MultipartWriteIntentStore` must also
implement `renewMultipartWriteIntent()`. It atomically extends an active
completion intent only when its stored lease matches the expected value and
has not expired according to the database clock. Returning `false` after
abandonment or collector takeover prevents a late assembler from publishing.

Custom metadata stores that accept checksummed UploadPart requests must
implement `MultipartPartChecksumStore`. Its capability method may return
`true` only when `putPartWithChecksum()` persists the verified algorithm and
value atomically with the part row. A missing or disabled capability returns
HTTP 501 before metadata or storage access. Cached and observed decorators
delegate this decision to their wrapped store instead of advertising it on the
store's behalf.

## Metadata Caching

The optional in-process CORS configuration cache is available with every backend:

```bash
S3_METADATA_CACHE_TTL=5.0   # seconds (0 to disable)
```

`CachedMetadataStoreDecorator` caches CORS reads and invalidates them on writes.
Bucket ownership, object metadata, ACLs, policies, Public Access Block,
encryption, versioning, and Object Ownership controls are read from the shared
store for each request so changes on another node are visible immediately.

The built-in stores validate account and named IAM policies before writing
them. Custom `MetadataStore` implementations should apply
`PolicyDocumentValidator::validateIdentityPolicy()` before their corresponding
write methods mutate data. Existing policy rows are not changed automatically;
inventory and repair malformed direct policies before rollout.

Inventory older account and named policy rows against the current validator on
an isolated database copy before rollout. Repair malformed rows through a
reviewed migration or the account-policy API; the package does not silently
rewrite them.

Custom `MetadataStore` implementations must return referenced physical tier
names from `listReferencedStorageTiers()` so API startup can reject a missing
backend before serving requests. Include object placements and pending targets,
lifecycle transitions, unfinished transition/restore jobs, and storage garbage.
The four built-in queue claims return a durable `lease_generation` for
notifications and storage garbage, or `leaseGeneration` for tier-transition
and restore jobs. The generation increments atomically on every claim.
Built-in workers use `FencedQueueStore` and `FencedStorageGarbageStore` to
condition acknowledgement, retry, and renewal on both the generation and
claimed deadline. Queue due times and tier, restore, and notification lease
expiry comparisons use database time, so API-node clock skew does not claim
active work or defer retries.

Every built-in claim/dequeue method clamps a requested batch to 1 through
1,000 rows and must own its transaction. Calling a claim while a direct or
callback transaction is active fails before stale-lease repair or row
selection. Custom metadata stores must preserve the same bounded,
independently committed claim contract.

Physical storage tier names are byte-exact identities. This includes case and
numeric-looking names such as `HOT`, `hot`, and `123`; metadata queries,
registries, readiness checks, and cleanup routing must not fold or coerce them.

`RestoreExecutor::processNext()` and `TierTransitionExecutor::processNext()`
return counters for worker attempts, not a snapshot of durable queue state.
Their `completed` counter includes stale claims and claims whose lease could
not be renewed; it does not prove that the SQL job row is `completed`. A
transaction rollback after an in-transaction lease renewal can leave the row
`processing` until its 300-second lease expires. A later worker reclaims it.
Use the persisted job status and object placement or restore state to determine
the actual result.

Custom `MetadataStore` implementations should implement these additive
interfaces and return the generation in claim rows to provide the same
multi-node fence. The legacy deadline-only methods remain available, but a
reclaimed lease may have the same deadline as its predecessor if database
clock precision is coarse; the built-in workers' compatibility fallback for
custom stores therefore cannot guarantee stale-worker rejection. Physical
cleanup may still run twice after expiry, so backend delete and abort
operations must be safe to repeat.

## Choosing a Backend

| Criteria | SQLite | PostgreSQL | MySQL |
|----------|--------|------------|-------|
| Setup complexity | Zero | Moderate | Moderate |
| Single-node | Excellent | Excellent | Excellent |
| Multi-node | Not supported | Excellent | Excellent |
| Shared rate limiting | Per-process only | Across all nodes | Across all nodes |
| Notification queue | Single consumer | Multi-consumer (SKIP LOCKED) | Multi-consumer (SKIP LOCKED) |
| Throughput | Measure on the target workload and deployment | Measure on the target workload and deployment | Measure on the target workload and deployment |
| Operational overhead | None | Backup, replication | Backup, replication |

## Generation-bound multipart listings

Custom metadata stores that serve `ListMultipartUploads` must implement
`GenerationBoundMultipartListingStore`. The method receives the observed
`BucketInfo` and must exclude uploads from earlier bucket generations before
applying prefix, delimiter, upload markers or page limits. A store without this
capability receives HTTP 501 for this endpoint. If a store includes old uploads
with no generation, it must atomically remove every upload when deleting a
bucket before that name can be reused; otherwise it must omit or migrate those
rows. The built-in SQLite, PostgreSQL and MySQL stores provide this capability.

Custom stores used by `DeleteBucket` must also implement
`GenerationBoundBucketMultipartCleanupStore`. Its method runs inside the owner
transaction and must reject an upload from a prior bucket generation before
staging any part cleanup. Without both capabilities, `DeleteBucket` returns
HTTP 501. Legacy blank-generation uploads may be drained only for the current
bucket owner when bucket deletion atomically removes all uploads.
