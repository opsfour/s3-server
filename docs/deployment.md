# Production Deployment

## Architecture Overview

```
Client ──TLS──▶ reverse proxy (optional) ──▶ one S3 API process
                                                  │
                                  ┌───────────────┴───────────────┐
                                  ▼                               ▼
                        persistent local filesystem      metadata database
                                                        SQLite/PostgreSQL/MySQL
```

This is the current production target: Linux, one host, one API process, and a
persistent local filesystem. The proxy is optional, but if present it must
preserve the signed `Host` header exactly. Additional processes, remote storage,
shared filesystems, and multiple hosts are separate experimental profiles; see
the [roadmap](production-feature-roadmap.md).

## Single-Node Deployment

Simplest production setup. Use SQLite for metadata and local filesystem for storage.

```bash
S3_HOST=0.0.0.0
S3_PORT=9000
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/var/data/s3
S3_METADATA_DRIVER=sqlite
S3_CREDENTIALS_DRIVER=file
S3_CREDENTIALS_PATH=/etc/s3-server/credentials.json
S3_RATE_LIMIT=1000
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"BASE64_KEY_HERE"}'
```

## Experimental: Multiple API Processes on One Host

Multiple API processes on one host are implemented but are outside the initial
qualified profile. They may share a local filesystem and PostgreSQL/MySQL
metadata only after the exact process, lock, temporary-directory, and shutdown
configuration passes the profile-specific release gates. This does not
establish host-level failover or remote-mount semantics. NFS, EFS, SMB, and
FUSE mounts are not qualified for `S3_STORAGE_PATH`; see
[Storage Backends](storage-backends.md#requirements).
PostgreSQL API nodes require `pecl-pq` and its `raphf` dependency. The server
refuses to start with only `ext-pgsql`, because an abrupt database disconnect
can terminate that event-loop backend. Verify both extensions with `php -m`
before starting the API process. The PostgreSQL `S3_CREDENTIALS_DRIVER=database`
example also uses `pdo_pgsql` for credential storage.

```bash
# All API processes share these settings
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"
S3_CREDENTIALS_DRIVER=database
S3_CREDENTIALS_DSN="pgsql:host=db.internal;port=5432;dbname=s3server"
S3_CREDENTIALS_USERNAME=s3
S3_CREDENTIALS_PASSWORD=secret
S3_CREDENTIALS_CACHE_TTL=1
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/srv/s3-data   # Same local filesystem under evaluation
```

When evaluating this profile:
- Give every API process the same credential source, account owner IDs, global quota
  defaults, storage tiers, and encryption keys. Different owner IDs or quota
  defaults can produce inconsistent authorization and limits for the same
  access key across processes.
- An API process refuses to start if persisted objects, lifecycle transitions,
  queued tier work, or storage garbage reference a tier absent from its
  configuration. Keep retired tier backends available until their references
  have been migrated or removed. Dedicated tier workers may use a subset and
  defer jobs they cannot perform.
- Rate limiting is shared across all processes (database-backed)
- Credential revocations and rotations become visible to every process within
  `S3_CREDENTIALS_CACHE_TTL` seconds; set it to `0` for a database read on
  every authenticated request
- Notification workers use `FOR UPDATE SKIP LOCKED` so two processes do not lease
  the same pending row at once. A delivery may still repeat after a worker
  crashes or the receiver times out; consumers should handle duplicate events.
- Stop old API and background-worker processes before a schema upgrade. Run
  one `metadata:migrate` command with a DDL-capable role. If database-backed
  credentials are configured, also run one `credentials:migrate` with the
  credential database's DDL-capable role. Verify both schemas, then start
  serving processes with DML-only roles. PostgreSQL/MySQL metadata startup
  checks the version; credential startup checks the columns and MySQL key type
  without applying DDL. Simultaneous migration commands are not supported;
  there is no cross-process DDL lock.

## TLS

### Direct TLS

```bash
S3_TLS_CERT=/etc/ssl/certs/s3.pem
S3_TLS_KEY=/etc/ssl/private/s3.key
```

### Behind a Load Balancer

Terminate TLS at the load balancer and run the S3 server on HTTP internally:

```bash
S3_HOST=0.0.0.0
S3_PORT=9000
# No TLS_CERT/TLS_KEY — load balancer handles TLS
```

Configure the load balancer to preserve `Host`, including any port. Set
`S3_TRUSTED_PROXY_CIDRS` to its direct peer CIDRs and have the last trusted
proxy overwrite `X-Forwarded-For` and `X-Forwarded-Proto` with authoritative
client values; see [Network Hardening](#network-hardening) below.

## Health Checks

```bash
# Process liveness
curl -f http://localhost:9000/.live
# Dependency readiness (metadata and every storage tier)
curl -f http://localhost:9000/.health
# {"status":"ok"}
```

The readiness handler rechecks persisted storage-tier references at most once
every 30 seconds. If another API node writes a lifecycle rule, object, or job
that needs a tier absent on this node, `/.health` returns 503 after the next
check. Configure the load balancer to remove unready nodes; `/.live` remains a
process-liveness probe.

For Kubernetes:

```yaml
livenessProbe:
  httpGet:
    path: /.live
    port: 9000
  initialDelaySeconds: 5
  periodSeconds: 10
readinessProbe:
  httpGet:
    path: /.health
    port: 9000
  initialDelaySeconds: 2
  periodSeconds: 5
```

## Graceful Shutdown

The server handles `SIGTERM` and `SIGINT` signals:

1. Cancels and drains background processors.
2. Stops accepting new connections and drains in-flight requests.
3. Logs a warning after `S3_SHUTDOWN_DRAIN_TIMEOUT` seconds but keeps runtime dependencies alive until work has stopped.
4. Shuts down storage and worker pools.
5. Exits cleanly.

Set the process supervisor's hard stop deadline above
`S3_SHUTDOWN_DRAIN_TIMEOUT`; the supervisor remains responsible for terminating
a callback or backend operation that never returns.

```bash
S3_SHUTDOWN_DRAIN_TIMEOUT=30   # seconds
```

## Systemd Service

```ini
# /etc/systemd/system/s3-server.service
[Unit]
Description=opsfour S3 Server
After=network.target postgresql.service

[Service]
Type=simple
User=s3server
Group=s3server
WorkingDirectory=/opt/s3-server
ExecStart=/usr/bin/php vendor/bin/s3-server
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60
EnvironmentFile=/etc/s3-server/env

[Install]
WantedBy=multi-user.target
```

## Docker

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl libcurl4-openssl-dev libonig-dev libpq-dev libsqlite3-dev libxml2-dev \
    && docker-php-ext-install curl mbstring pcntl pdo_pgsql pdo_sqlite simplexml sockets \
    && pecl install raphf-2.0.2 \
    && docker-php-ext-enable raphf \
    && pecl install pq-2.2.3 \
    && docker-php-ext-enable pq \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 9000
HEALTHCHECK CMD curl -f http://localhost:9000/.live || exit 1

CMD ["php", "bin/s3-server", "serve", "--host=0.0.0.0", "--port=9000"]
```

```yaml
# docker-compose.yml
services:
  s3:
    build: .
    ports:
      - "9000:9000"
    environment:
      S3_STORAGE_PATH: /data/s3
      S3_METADATA_DRIVER: postgres
      S3_METADATA_DSN: "host=db port=5432 dbname=s3server user=s3 password=secret"
      S3_ACCESS_KEY: myAccessKey
      S3_SECRET_KEY: mySecretKey
    volumes:
      - s3-data:/data/s3
    depends_on:
      - db

  db:
    image: postgres:16
    environment:
      POSTGRES_DB: s3server
      POSTGRES_USER: s3
      POSTGRES_PASSWORD: secret
    volumes:
      - pg-data:/var/lib/postgresql/data

volumes:
  s3-data:
  pg-data:
```

## Performance Tuning

### Connection Limits

```bash
S3_MAX_CONNECTIONS=10000   # Match your expected concurrency
```

With Amp HTTP Server 3.4.6, HTTP/1 uses one inactivity timeout for idle
keep-alive sockets, request reads, and response writes. The package passes the
larger of `S3_READ_TIMEOUT` and `S3_WRITE_TIMEOUT` to that driver, so their
300-second defaults also permit 300 seconds of HTTP/1 keep-alive inactivity.
`S3_CONNECTION_IDLE_TIMEOUT=60` controls HTTP/2 connection idleness only. A
60-second HTTP/1 idle limit cannot currently be enforced independently without
also shortening the permitted pause during an active transfer. Set the read
and write timeouts to 60 seconds only if that active-transfer limit is acceptable;
otherwise account for up to 300 seconds of idle HTTP/1 connections when sizing
`S3_MAX_CONNECTIONS` and any proxy connection pool. The configured values are
described in [configuration.md](configuration.md#timeouts).

### SQLite Worker Pool

For SQLite deployments under load, prevent write-lock contention:

```bash
S3_SQLITE_WORKERS=4
```

### Encryption Workers

For high-throughput SSE-S3 encryption:

```bash
S3_ENCRYPTION_WORKERS=4
S3_ENCRYPTION_THRESHOLD=65536   # Objects > 64 KiB offloaded to workers
```

### S3 Select Workers

Configure S3 Select independently when query concurrency differs from
encryption throughput. When omitted, it inherits `S3_ENCRYPTION_WORKERS`.

```bash
S3_SELECT_WORKERS=4
```

### Rate Limiting

```bash
S3_RATE_LIMIT=1000   # Per IP, per second (0 = unlimited)
```

When enabled, a failed rate-limit database check returns S3 `SlowDown` (503)
with `Retry-After: 1`; requests do not bypass the configured limit. Liveness
(`/.live`) remains available. Readiness (`/.health`) probes the rate-limit write
path at most once every 30 seconds per node and reports 503/degraded while it
fails. Set `S3_RATE_LIMIT=0` only when rate limiting is deliberately disabled.

### Temporary Upload Storage

Browser POST uploads use `sys_get_temp_dir()`. The default
`S3_POST_SPOOL_BUDGET=10737418240` caps concurrent browser POST and request-body
checksum spool bytes across API processes sharing that local temp directory
and returns S3 `SlowDown` (503) when full. Keep the same temp directory and
budget on each process of a node under the same service account. Locked lease
files recover a crashed process's reservations on the next browser POST. A
request carrying both Content-MD5 and literal SigV4 SHA-256 can occupy two
spools; a full 5-GiB body slightly exceeds the default 10-GiB budget after
ciphertext-frame overhead and is rejected with 503. Raise the configured
budget or lower the accepted body size for that case. Set a filesystem quota
or use an isolated temporary volume with headroom for storage staging and
crash files before recovery. A volume shared between nodes still needs its own
quota or cluster-wide coordinator. Do not remove `.opsfour-s3-post-budget` while API
processes are running. The stale-file sweep reclaims abandoned browser POST
files older than 24 hours on a later upload.

Browser POST and request-body checksum spools now write authenticated encrypted
frames, including for SSE requests. Their files are created with `0600` mode;
each ephemeral key remains in the API process and a crashed spool cannot be
resumed. Use a private service-account directory where possible; a shared
writable directory must have the sticky bit so another local user cannot
replace an active file. A sticky directory alone does not rule out hard links
on every filesystem. A node with no later uploads needs operator cleanup of
abandoned ciphertext files; the checksum spool also uses a bounded 24-hour
stale sweep. An active checksum spool holds a file lock, so another process's
sweep preserves it even when its modification time is old. A crashed process
releases its lock and its stale file is removed on a later sweep. For the
strict at-rest guarantee, also disable or encrypt swap and prevent core dumps
that could contain plaintext request buffers or SSE-C keys.
Apply the same host-memory and diagnostic rules to every TLS terminator or
load balancer that decrypts S3 traffic. Its access and error logs must not
retain presigned request queries or SSE-C headers; verify this for the actual
proxy in use before claiming strict at-rest handling.

Worker-backed Flysystem transfers use `S3_STORAGE_TEMP_DIR` and a separate
`S3_STORAGE_TEMP_BUDGET=10737418240` reservation limit for API and worker
staging across processes on one node, including copies and multipart assembly.
A multipart assembly larger than this budget returns 503 until the budget is
raised. The storage lease directory is
`.opsfour-s3-storage-budget`; keep it private and do not remove it while API
processes run. Give every API and Flysystem worker process using the same
temporary directory the same storage budget. Abandoned reservations are
recovered when a later transfer starts. The browser POST and Flysystem budgets
are separate, so a shared temporary volume must accommodate both limits plus
other temporary writers,
or enforce a smaller volume quota and suitable admission limits.
Worker-backed Flysystem GETs stream 64-KiB blocks directly through a separate
read-worker pool, so the staging budget does not cap download size. Each active
GET occupies a read worker until completion or cancellation; a worker waiting
120 seconds for the client to request its next block times out. A separate 20-second
`S3_FLYSYSTEM_FIRST_BYTE_TIMEOUT_SECONDS` limit covers read-worker admission
and the first byte; expiry returns 503 SlowDown. Only one additional GET may
wait per read worker; excess GETs receive immediate 503. A timed-out request
keeps its worker slot reserved until the underlying task ends. The built-in
backing S3 factory uses a 5-second connect timeout and 15-second per-source-read
idle timeout, enforced inside the read worker even for Amp's nonblocking PHP
stream. The source-read deadline resets after every chunk. If it expires before
response headers, GET returns 503 SlowDown. After headers, the HTTP body ends
early and clients must treat the incomplete Content-Length response as failed.
Its total request timeout defaults to zero to preserve long uploads and
multipart assembly; configure a total cap only after sizing the largest
expected transfer.
Custom synchronous adapters need their own I/O timeouts because cancellation
cannot stop a blocked adapter call immediately. Test large reads, slow clients,
disconnects, remote-adapter cancellation, and first-byte faults in the
deployment profile.
`S3_FLYSYSTEM_WORKERS` sizes each pool, so provision memory for up to twice
that many Flysystem worker processes per API process.
The write pool admits at most one waiting task per worker for 20 seconds;
additional tasks receive 503. A running write is not cancelled at this
admission deadline, because it could still commit to backing storage.

## Monitoring

### Key Metrics to Watch

- `s3_server_up`
- `s3_server_requests_total{operation,status}`
- `s3_server_request_duration_seconds_bucket`
- `s3_server_backend_errors_total{backend,driver,operation,exception}`
- `s3_server_backend_slow_operations_total{backend,driver,operation}`
- `s3_server_lifecycle_running`
- `s3_server_lifecycle_last_success_at_seconds`
- `s3_server_lifecycle_actions_total{action}`
- `s3_server_tiering_worker_results_total{worker,status}`
- `s3_server_notification_queue_depth{status}`
- `s3_server_object_write_intents` and
  `s3_server_object_write_capacity_remaining`; also
  `s3_server_multipart_part_write_intents` and
  `s3_server_multipart_part_write_capacity_remaining`. These report retained
  object and multipart-part recovery intents and free slots from the two
  shared `s3_write_intent_capacity` rows. Each kind has a one-million global
  limit. The authorized scrape reads both rows in one O(1) database query;
  unsupported metadata stores omit the samples, and a missing row fails the
  scrape.
- `s3_server_multipart_completion_intents` and
  `s3_server_multipart_completion_capacity_remaining`. These report the
  shared metadata database's retained completion attempts and free slots
  against the one-million global limit. The server reads one counter row per
  authorized metrics scrape; the samples are absent when the metadata store
  does not support this read.
- `s3_server_notification_deliveries_total{status}`
- `s3_server_worker_pool_tasks_total{pool,operation,status}`
- `s3_server_flysystem_read_first_byte_seconds_bucket{le,result}` and its
  `_count`/`_sum` series. This measures worker-backed GET admission through
  the first storage byte; `result` is `ready`, `admission_timeout`,
  `admission_rejected`, `first_byte_timeout`, or `error`. It does not include
  the rest of the response stream. Compare it with request duration and
  backend errors when investigating remote read stalls.
- Database connection count and query latency from the database server
- Storage path or backend capacity, inode exhaustion, and growth rate

### Suggested Alerts

Tune thresholds per installation, but start with these alerts:

- `s3_server_up == 0` for 1 minute.
- HTTP 5xx rate above 1 percent over 5 minutes.
- p95 request latency above the SLO for 10 minutes.
- Any increase in `s3_server_backend_errors_total`.
- `time() - s3_server_lifecycle_last_success_at_seconds` above twice the
  configured lifecycle interval.
- Notification `dead_letter` queue depth above 0.
- Tiering or restore `dead_letter` worker results above 0.
- Storage capacity above 80 percent or projected exhaustion within 7 days.
- Database connections above 80 percent of the configured maximum.

### Access Logging

Configure bucket logging to write S3 access logs to a target bucket owned by
the same account in the same Region. The target cannot have Object Lock
enabled. The package applies explicit SSE-S3 default encryption, versioning,
and configured quotas to generated log objects. Target grants and custom log
key formats are not supported. Access-log records follow the AWS field order,
with `-` for unavailable values. Operation names and generated log keys remain
package-specific; these records are not yet fully AWS-compatible.

```php
$s3->putBucketLogging([
    'Bucket' => 'my-bucket',
    'BucketLoggingStatus' => [
        'LoggingEnabled' => [
            'TargetBucket' => 'log-bucket',
            'TargetPrefix' => 'access-logs/',
        ],
    ],
]);
```

## Backups

### SQLite

```bash
# Hot backup while server is running (WAL mode supports this)
sqlite3 /var/data/s3/metadata.sqlite ".backup /backups/metadata-$(date +%Y%m%d).sqlite"
```

### PostgreSQL

```bash
pg_dump -h db.internal -U s3 s3server > /backups/s3server-$(date +%Y%m%d).sql
```

### Object Storage

Back up the storage path with standard filesystem tools:

```bash
rsync -av /var/data/s3/ /backups/s3-storage/
```

For tiered deployments, back up every configured physical storage tier. Metadata
and object storage must be restored to a mutually consistent point in time. If
that is not possible, restore metadata first, then run an object-integrity audit
before accepting writes.

### Retained encrypted multipart part intents

Use `php bin/s3-server multipart-part-intents OWNER --limit=100 --after-id=0`
to inspect one bounded page of retained part-write intents. The command uses
the configured metadata driver and DSN. It prints a SHA-256 hash of each
physical path, never the path itself, and makes no cleanup changes. For
PostgreSQL or MySQL, run it with a dedicated database account that has only
`SELECT` privileges; the command issues only reads, but its database connection
is not server-enforced read-only. For SQLite, provide `--metadata-path` and
the command opens the database in read-only mode. Continue with the last row
ID as `--after-id` until a page is empty.

Do not delete these rows based on age, an aborted upload, or one successful
garbage-collector pass. A generic remote adapter cannot prove when an in-flight
write has finished; a later write could recreate the ciphertext path after a
tombstone is removed. Retire them only through a provider-qualified write and
cleanup fence.

## Production Preflight

Before exposing a node to client traffic:

```bash
php -v
php -m | grep -E 'pdo|pcntl|openssl'
php vendor/bin/s3-server --help
curl -fsS http://127.0.0.1:9000/.health
curl -fsS -H "Authorization: Bearer ${S3_METRICS_BEARER_TOKEN}" \
  http://127.0.0.1:9000/.metrics | grep s3_server_up
```

Run the test suite for the deployment driver mix before rollout:

```bash
vendor/bin/phpunit
```

For an experimental external S3-compatible storage profile, run the opt-in
integration tests with real credentials in a non-production bucket. Use a
dedicated bucket and short object retention so failed test data is easy to
clean up. Passing those tests does not qualify the profile; complete the
provider-specific gates in the [release checklist](release-checklist.md).

## Network Hardening

Recommended layout:

- Expose only the S3 data-plane host publicly.
- Terminate TLS at a load balancer or reverse proxy.
- Keep `/.metrics`, `/.admin/*`, and direct node ports private.
- Allow `/.live` and `/.health` only from the load balancer, orchestrator, or monitoring
  network.
- Preserve the client `Host` header exactly, including any port: SigV4 signs
  this header. Set `S3_TRUSTED_PROXY_CIDRS` to the exact proxy-to-node peer
  CIDRs when TLS ends at the proxy. Without that opt-in, forwarded headers are
  ignored and `aws:SecureTransport` describes only the direct connection.
  Restrict direct node access so clients cannot bypass the proxy. Do not trust
  client networks or `0.0.0.0/0` as proxy peers.
- Have the last trusted proxy overwrite `X-Forwarded-Proto` with one value
  (`https` or `http`) and `X-Forwarded-For` with the observed client address.
  Missing or ambiguous proto values count as insecure; invalid client-address
  chains and missing `X-Forwarded-For` are denied for S3 and admin requests.
  The `/.live` and `/.health` probes ignore missing or invalid address chains.
  Policy `aws:SourceIp`, rate limits, access logs, and S3
  event source IP then use the rightmost untrusted hop. An upstream chain can
  be forwarded if every proxy hop is explicitly trusted.

Example Nginx policy for admin surfaces:

```nginx
proxy_set_header Host $http_host;
proxy_set_header X-Forwarded-For $remote_addr;
proxy_set_header X-Forwarded-Proto $scheme;

location = /.health {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_nodes;
}

location = /.live {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_nodes;
}

location = /.metrics {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_nodes;
}

location ^~ /.admin/ {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_nodes;
}

location / {
    proxy_request_buffering off;
    proxy_buffering off;
    client_max_body_size 0;
    proxy_pass http://s3_nodes;
}
```

For an AWS Application Load Balancer, enable **Preserve host header**. Its
default behavior can remove or add a port in `Host`, invalidating a SigV4
signature. Use `GET /.health` with success code `200` for target-group
readiness. Keep at least one healthy target while injecting a node failure:
an ALB with *all* targets unhealthy can route requests to those unhealthy
targets. Test signed path-style and virtual-hosted requests through the public
listener, including a non-default port if used, before relying on the proxy
configuration. Keep the API target port reachable only from the load balancer
security group. Configure `S3_TRUSTED_PROXY_CIDRS` with only the ALB target-side
source CIDRs if policy and access logs should describe the client connection.
The proxy must supply a single, authoritative `X-Forwarded-Proto`; test the
actual ALB header behavior before enabling the trust list. Access-log TLS
cipher/version fields still describe the proxy-to-node TLS connection.

See [AWS ALB host-header preservation](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/edit-load-balancer-attributes.html),
[target health and fail-open behavior](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/target-group-health-checks.html),
and [Nginx Host forwarding](https://nginx.org/en/docs/http/ngx_http_proxy_module.html).

## Filesystem and Secret Permissions

Use a dedicated service user. The storage path must not be inside a web root.

```bash
install -d -o s3server -g s3server -m 0750 /var/data/s3
install -d -o s3server -g s3server -m 0750 /etc/s3-server
chmod 0640 /etc/s3-server/env
chmod 0600 /etc/s3-server/credentials.json
```

Keep encryption master keys and admin tokens in the process environment, a
secret manager, or root-owned service environment files. Do not store plaintext
keys in a repository.

## Database Least Privilege

Use a DML-only database role for PostgreSQL/MySQL runtime access. Serving
nodes check the schema version and cannot migrate it on startup.

For production deployments:

- Run migrations with a separate migration role during deploy.
- Run serving nodes with a runtime role limited to `SELECT`, `INSERT`,
  `UPDATE`, and `DELETE` on the application tables. For PostgreSQL, also grant
  `USAGE` on the application's `BIGSERIAL` sequences: inserts call `nextval()`
  and otherwise fail even though the role has table `INSERT`. Recheck table
  and sequence grants after each migration, including when the credential
  store uses a separate database or schema.
- Deny public network access to the database.
- Enable database TLS if traffic crosses hosts or networks you do not fully
  control.

## Rollout and Rollback

For a package version with **no metadata schema change**, roll API processes
only after verifying that old and new code can share the current schema:

1. Start one new process outside load-balancer traffic.
2. Check `/.health` and `/.metrics`, then send a signed S3 request to it.
3. Add it to traffic, drain one old process, and repeat.

For a **schema upgrade** such as PostgreSQL v29 or MySQL v31, do not use that
rolling sequence. Drain and stop all old API and background workers; back up
metadata; run exactly one `php bin/s3-server metadata:migrate` with a
DDL-capable role and the matching package version. If
`S3_CREDENTIALS_DRIVER=database`, also run one `php bin/s3-server
credentials:migrate` with its DDL-capable credential role, which may use a
separate database. Set both metadata and credential DSNs and passwords through
the bootstrap environment rather than placing secrets in shell arguments.
Verify the metadata version and expected indexes/column types, and verify
the credential table and columns. Then start the new API and workers with
DML-only roles and restore traffic after readiness and signed-request checks.
Serving nodes check the PostgreSQL/MySQL version without applying DDL and
refuse an old or unverifiable schema; database credentials likewise refuse
missing or legacy columns. Measure index-build/ALTER time and locks on a
representative disposable snapshot before scheduling the maintenance window.
The migrator has no cross-process DDL lock: run exactly one bootstrap job;
concurrent migration commands may repeat or race DDL.

Before rolling back after a schema migration, verify that the previous package
version understands the current metadata schema. If not, restore metadata from a
pre-migration backup or roll forward with a hotfix.

## Security Checklist

- [ ] TLS enabled (direct or via load balancer)
- [ ] Encryption master key configured (`S3_ENCRYPTION_MASTER_KEYS`)
- [ ] Strong access key and secret key (32+ random characters)
- [ ] Rate limiting enabled (`S3_RATE_LIMIT > 0`)
- [ ] Storage path not web-accessible
- [ ] Database credentials use least-privilege access
- [ ] Credential file permissions restricted (`chmod 600`)
- [ ] Graceful shutdown timeout set (`S3_SHUTDOWN_DRAIN_TIMEOUT`)
- [ ] Health check endpoint accessible to load balancer only
- [ ] Metrics and admin endpoints restricted to trusted networks
- [ ] Metrics bearer token configured (`S3_METRICS_BEARER_TOKEN`) unless an
  equivalent private-network control is enforced
- [ ] Backups cover metadata and every configured storage tier
- [ ] Restore procedure tested in a non-production environment
- [ ] Alerts configured for request errors, backend errors, lifecycle staleness,
  notification dead letters, tiering/restore dead letters, DB saturation, and
  storage capacity
- [ ] Notification webhook destinations validated (SSRF protection is automatic)
