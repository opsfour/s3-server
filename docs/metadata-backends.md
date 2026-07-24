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

Best for multi-node production deployments with high availability.

```bash
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"
```

### Connection Pool

The Postgres backend uses `amphp/postgres` connection pooling. All queries are non-blocking and executed via PHP Fibers. The pool automatically manages connection lifecycle and handles reconnection.

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
- Shared rate limiting across nodes
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
write-lock rows serialize quota-sensitive writes across nodes. MariaDB and
distributed MySQL variants are not part of the tested compatibility matrix.

The `transaction()` convenience method can be called from normal synchronous
CLI code or from an Amp Fiber. Direct `beginTransaction()`, `commit()`, and
`rollback()` calls require an Amp Fiber so the connection remains bound to the
calling request.

### When to Use MySQL

- Teams already operating MySQL infrastructure
- MySQL-managed services that preserve InnoDB transactions and row locks
- Same capabilities as PostgreSQL backend

## Schema Migrations

All backends use automatic schema versioning. Tables are created on first start and migrated on upgrade.

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

SQLite currently uses schema version `14`; PostgreSQL and MySQL use version
`15`. SQLite applies each migration version in a transaction. PostgreSQL DDL is
retry-safe and records the new version only after every statement succeeds.
MySQL DDL auto-commits, so partially applied migrations are retried
idempotently; duplicate schema objects are tolerated, while every other error
stops startup before the version is recorded. Schema upgrades may add tables,
indexes, and columns with defaults, but do not delete application data.

## Metadata Caching

All backends support a TTL read cache:

```bash
S3_METADATA_CACHE_TTL=5.0   # seconds (0 to disable)
```

The `CachedMetadataStoreDecorator` caches read results (getBucket, getObject, getAcl, etc.) in memory. Write operations automatically invalidate relevant cache entries. This reduces database load for read-heavy workloads.

## Choosing a Backend

| Criteria | SQLite | PostgreSQL | MySQL |
|----------|--------|------------|-------|
| Setup complexity | Zero | Moderate | Moderate |
| Single-node | Excellent | Excellent | Excellent |
| Multi-node | Not supported | Excellent | Excellent |
| Shared rate limiting | Per-process only | Across all nodes | Across all nodes |
| Notification queue | Single consumer | Multi-consumer (SKIP LOCKED) | Multi-consumer (SKIP LOCKED) |
| Max throughput | ~1000 req/s | 10,000+ req/s | 10,000+ req/s |
| Operational overhead | None | Backup, replication | Backup, replication |
