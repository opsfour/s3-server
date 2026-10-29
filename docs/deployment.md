# Deployment

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

The explicit `aws-s3` driver includes the complete registered writer fence,
ambiguous-write sealing, exact garbage deletion, and shared operational state.
It may start with PostgreSQL when provider admission and the independent writer
inventory pass. This is fence-complete, not production-qualified: do not release
the profile until the separate bounded real-AWS qualification run succeeds.

## Current One-Process Deployment

The current release target runs exactly one S3 API process in total. That
process owns every configured local storage root. Use SQLite for a
self-contained deployment, or PostgreSQL or MySQL when that database is already
part of the operating environment.

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

The server acquires an exclusive lock under `.opsfour/runtime.lock` in every
configured local storage root before opening its listener. A second process
using the same root fails startup. The lock file is persistent control state;
do not delete or replace it. This lock enforces the supported process topology.
It does not by itself prove durability after a crash or power loss.

The server also refuses to start unless an `AuthenticationMiddleware` is
registered. The marker prevents accidental omission but cannot validate a
custom implementation, which remains responsible for enforcing its security
contract. This mandatory startup invariant does not disable supported public
access: unsigned bucket/object API requests remain subject to bucket policy,
ACL, Object Ownership, and Public Access Block rules. Website-host `GET`/`HEAD`
requests are anonymous; object and error-document content requires public read
authorization, while redirect-all and prefix-only routing redirects do not read
object content and need no object ACL or policy allow. There is no built-in
global authentication-disabled server mode or built-in default anonymous owner.

Multiple API processes, multiple hosts, shared filesystems, and remote storage
are later experimental profiles. They are not alternative deployment recipes
for the current release target; see the
[production feature roadmap](production-feature-roadmap.md).

## AWS S3 fence-complete operations

Use `deploy/aws-s3-profile.cloudformation.yaml` only for a fresh isolated
profile. The stack creates one new bucket, its policy, and one runtime role; it
does not discover or update existing buckets, roles, keys, lifecycle rules, or
unrelated resources. Review the exact trust principal before deployment. The
runtime role has unconditional bucket-scope `ListBucket` for the SDK
`HeadBucket` admission probe together with the required versioning read; object
reads and mutations remain limited to the two configured
prefixes, including aborting incomplete multipart uploads. The
bucket policy denies plaintext transport, any versioning change, and creation
of an S3 lifecycle configuration; the AWS bucket must have no lifecycle rules
because data, attempt, and seal prefixes are controlled only by PostgreSQL-led
recovery. Configure `expected_owner_id` from the stack's
`ExpectedOwnerAccountId` output; do not copy it into dashboards or support
bundles. Configure the runtime bucket and both prefixes from the corresponding
stack outputs. The template deliberately fixes separate `data` and `.opsfour`
leading namespaces and accepts only canonical ASCII path segments, so equal,
nested, wildcard-bearing, and runtime-invalid prefix inputs cannot render.

The process must obtain short-lived credentials through the AWS SDK default
chain. The `RuntimeRoleArn` output does not make the SDK assume that role. The
IAM role or user named by `RuntimePrincipalArn` is the source principal: it must
have an identity policy permitting `sts:AssumeRole` on `RuntimeRoleArn`, and the
generated target role's trust policy permits that exact source principal. This
template neither modifies the source identity policy nor configures an OIDC or
other federated trust relationship, and the generated role is not directly
attachable as an ECS task, EC2 instance, or EKS web-identity role.

Configure a named shared AWS profile with `role_arn = <RuntimeRoleArn>` and
either `source_profile = <profile-containing-the-source-principal>` or an
applicable `credential_source` such as `Ec2InstanceMetadata` or
`EcsContainer`. Set `AWS_PROFILE` to the assuming profile (and enable shared SDK
configuration where the platform requires it) before startup. Confirm the
assumed target identity with a read-only STS check outside the server. Do not
set static keys in the server configuration. For rotation, drain
new traffic, rotate the upstream role/session source, wait for in-flight
requests to finish, restart nodes one at a time, and require the same opaque
profile fingerprint plus `mutation_ready=true` before restoring traffic.
Expired or revoked credentials leave `/.live` at 200, change `/.health` to 503,
and close provider access while durable rows remain. Recovery retries resume as
soon as provider admission returns. Recovery pressure, collector failure, or
an integrity conflict closes new foreground/background publication but does
not gate the reconciler or claimed-garbage cleanup from repairing that state.
Abandoned AWS multipart-completion tombstones remain observable in multipart
backlog, per-owner, and global-capacity gauges. Their retained age does not
degrade the actionable recovery-age gauge. Until an exact
proof-safe AWS retirement capability is qualified, their expected retention is
not classified as a collector failure; capacity and backlog alerts still
require operator attention.

Provision the process temporary volume for the configured maximum object size
times admitted concurrent foreground transformations, plus browser POST spool
and operating-system headroom. Staging contains package ciphertext for SSE
paths, is mode 0700, and must not be backed up. Start with conservative request,
staging, and worker concurrency. The profile uses zero blind SDK retries and
bounded connect/read/request timeouts; increase a bound only with evidence that
the resulting intent backlog and temporary-space cost remain acceptable.

Scrape `/.metrics` behind its bearer-token boundary. Alert on any non-healthy
AWS reason, integrity conflict, collector retry growth, oldest recovery age at
15 minutes (three normal 300-second claim leases), 80% global capacity, 80%
maximum-per-owner capacity, and hard-capacity rejection. The series contain
only fixed categories, aggregates, maximum-per-owner values, and the opaque
profile fingerprint. They never contain bucket, prefix, key, path, owner,
account, credential, or attempt labels. Budget for S3 request charges from
readiness revalidation, write attempts, same-key seal verification, and cleanup;
also budget retained seals and PostgreSQL recovery rows as permanent control
state.

For planned maintenance, stop admission at the load balancer, wait for active
requests and staging to drain, then let collectors reduce open and ambiguous
rows. Do not wait for permanent seals to reach zero. Stop API/worker processes
before taking a matched backup. While the profile is stopped, create and verify
a provider copy/export that preserves every exact key, byte, and
`x-amz-meta-*` value under both configured prefixes, including data objects,
attempts, and seals. Retain an immutable manifest that binds that provider
export to the PostgreSQL snapshot. For replacement-bucket recovery, restore and
verify those provider objects first, then restore the matching PostgreSQL
snapshot into an isolated environment, restore the exact profile configuration
and IAM role, keep traffic closed, run metadata migration/audit, and allow
reconciliation to settle before readiness and traffic are enabled. A
PostgreSQL snapshot alone is not restorable. If the operator has no exact-copy
mechanism with metadata preservation, disaster restore to a replacement bucket
is not qualified and the original retained bucket is a required dependency.
Versioning cannot supply point-in-time recovery because this profile prohibits
it.

After a crash or provider outage, keep the profile drained while credentials,
region, ownership, versioning, and fingerprint are checked. Resume collectors;
do not replay armed writes or delete ambiguous attempts. Reconciliation either
proves absence or installs/verifies the deterministic same-key seal. If an
integrity conflict remains, preserve the database row, provider object, logs,
and immutable artifact identifiers for investigation.

Seal removal is a manual audited break-glass action only. It requires an
approved incident, a stopped/drained profile, two-person review, proof that no
delayed create-only request can still arrive, a retained export of the exact SQL
row and provider evidence, and a rollback plan. Remove the provider seal first
only under that evidence, then update metadata in one reviewed PostgreSQL
transaction, record the actor/reason/time/artifact hashes outside the public
repository, and rerun admission, audit, and reconciliation before reopening.
There is deliberately no automatic or server API seal-deletion path.

## TLS

### Direct TLS

```bash
S3_TLS_CERT=/etc/ssl/certs/s3.pem
S3_TLS_KEY=/etc/ssl/private/s3.key
```

### Behind a Reverse Proxy or Load Balancer

Terminate TLS at the proxy and run the S3 server on HTTP internally:

```bash
S3_HOST=0.0.0.0
S3_PORT=9000
# No TLS_CERT/TLS_KEY — the proxy handles TLS
```

Configure the proxy to preserve `Host`, including any port. Set
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
every 30 seconds. If metadata references a tier absent from this process,
`/.health` returns 503 after the next check. Configure the proxy to stop routing
to an unready process; `/.live` remains a process-liveness probe. At most once
every 30 seconds, readiness also executes a no-row DML statement on a built-in
metadata backend and creates, synchronizes, then removes a private sentinel in
each local filesystem tier. Concurrent requests share the same in-flight probe.
With the standard asynchronous metadata and credential providers, the HTTP
response waits at most five seconds. A timed-out local filesystem probe is
terminated in its isolated worker. Explicit synchronous provider modes must
return promptly themselves. These probes change no S3 metadata or object data.

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
3. Waits up to `S3_SHUTDOWN_DRAIN_TIMEOUT` seconds for active HTTP requests.
4. Shuts down storage and worker pools after the requests and background work stop.
5. Fails the shutdown attempt without releasing dependencies if the deadline is exceeded.

`S3_SHUTDOWN_DRAIN_TIMEOUT` applies separately to each sequential bounded
shutdown phase; it is not a total process-stop deadline. Set the process
supervisor's hard stop deadline above the combined budget of the enabled HTTP,
background, and listener phases. A timed-out stop returns a failure to the
serve command, retains dependencies that active work may still use, and lets
the supervisor terminate a callback or backend operation that never returns.

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
    && docker-php-ext-install curl mbstring pcntl pdo_pgsql pdo_sqlite posix simplexml sockets \
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

File-backed SQLite uses four metadata workers by default to keep lock waits out
of the API event loop:

```bash
S3_SQLITE_WORKERS=4
```

Set `S3_SQLITE_WORKERS=0` only when blocking SQLite access is intentional.

### Encryption Workers

For high-throughput SSE-S3 encryption:

```bash
S3_ENCRYPTION_WORKERS=4
S3_ENCRYPTION_THRESHOLD=65536   # Objects > 64 KiB offloaded to workers
```

### S3 Select Workers

S3 Select uses one dedicated worker by default so buffered query processing
does not run in the API process. Set it independently from encryption workers.

```bash
S3_SELECT_WORKERS=1
```

The buffered Select admission guard currently allows one Select request per API
process. Additional Select workers do not increase Select concurrency in this
profile.

### Rate Limiting

```bash
S3_RATE_LIMIT=1000   # Per IP, per second (0 = unlimited)
```

When enabled, a failed rate-limit database check returns S3 `SlowDown` (503)
with `Retry-After: 1`; requests do not bypass the configured limit. Liveness
(`/.live`) remains available. Readiness (`/.health`) probes the rate-limit write
path at most once every 30 seconds and reports 503/degraded while it
fails. Set `S3_RATE_LIMIT=0` only when rate limiting is deliberately disabled.

### Temporary Upload Storage

Browser POST uploads use `sys_get_temp_dir()`. The default
`S3_POST_SPOOL_BUDGET=10737418240` caps concurrent browser POST and request-body
checksum spool bytes for the server and its helper processes in that local
directory, and returns S3 `SlowDown` (503) when full. Locked lease files recover
a crashed process's reservations at server startup and every five minutes;
every later spool reservation also recovers them immediately as a fallback. A
request carrying both Content-MD5 and literal SigV4 SHA-256 can occupy two
spools; a full 5-GiB body slightly exceeds the default 10-GiB budget after
ciphertext-frame overhead and is rejected with 503. Raise the configured budget
or lower the accepted body size for that case. Set a filesystem quota or use an
isolated temporary volume with headroom for storage staging and crash files
before recovery. Do not remove `.opsfour-s3-post-budget` while the server is
running. Sharing this temporary directory between API processes belongs to a
later profile and needs its own acceptance evidence. Separately, the prefix
sweep reclaims abandoned browser POST files older than 24 hours on a later
upload.

Browser POST and request-body checksum spools now write authenticated encrypted
frames, including for SSE requests. Their files are created with `0600` mode;
each ephemeral key remains in the API process and a crashed spool cannot be
resumed. Use a private service-account directory where possible; a shared
writable directory must have the sticky bit so another local user cannot
replace an active file. A sticky directory alone does not rule out hard links
on every filesystem. Startup and five-minute recovery remove abandoned
ciphertext files recorded by the shared budget coordinator. Active checksum
spools retain their lease and file locks, so concurrent recovery preserves
them. A crash releases both locks, allowing coordinator recovery to remove the
registered file. Separately, the bounded 24-hour prefix sweep removes unleased
remnants during a later upload. For the strict at-rest guarantee, also disable
or encrypt swap and prevent core dumps that could contain plaintext request
buffers or SSE-C keys.
Apply the same host-memory and diagnostic rules to every TLS terminator or
load balancer that decrypts S3 traffic. Its access and error logs must not
retain presigned request queries or SSE-C headers; verify this for the actual
proxy in use before claiming strict at-rest handling.

### Experimental Flysystem Transfer Limits

The following limits apply only when evaluating the experimental Flysystem
profile. They are not configuration guidance for the current local-filesystem
release target.

Worker-backed Flysystem transfers use `S3_STORAGE_TEMP_DIR` and a separate
`S3_STORAGE_TEMP_BUDGET=10737418240` reservation limit for API and worker
staging within the server's worker pools, including copies and multipart
assembly.
A multipart assembly larger than this budget returns 503 until the budget is
raised. The storage lease directory is
`.opsfour-s3-storage-budget`; keep it private and do not remove it while the
server or its Flysystem workers run. Abandoned reservations are recovered when
a later transfer starts. The browser POST and Flysystem budgets
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
  object, permanent AWS seal, and multipart-part recovery capacity from the
  three shared `s3_write_intent_capacity` rows. Each kind has a one-million
  global limit. The authorized scrape reads all rows in one O(1) database query;
  unsupported metadata stores omit the samples, and a missing row fails the
  scrape. Exhausting either global object-attempt or AWS-seal pool also fails
  readiness closed until capacity is available.
- `s3_server_multipart_completion_intents` and
  `s3_server_multipart_completion_capacity_remaining`. These report the
  shared metadata database's retained completion attempts and free slots
  against the one-million global limit. The server reads one counter row per
  authorized metrics scrape; the samples are absent when the metadata store
  does not support this read.
- `s3_server_storage_cleanup_items` and
  `s3_server_storage_cleanup_capacity_remaining{admission="normal|recovery"}`.
  These gauges read the exact singleton counter without scanning the cleanup
  queue. Normal admission stops at 900,000; the protected failed-delete band
  ends at 1,000,000. Values are fixed safety limits, not sizing guidance.
- `s3_server_storage_cleanup_admission_rejections_total{admission="normal|recovery"}`.
  A recovery rejection means an exact failed-delete path could not be retained
  and a physical orphan may exist.
- `s3_server_aws_write_seals` and
  `s3_server_aws_write_seal_capacity_remaining`. These expose the permanent
  same-key seal pool; verified reconciliation never decrements the retained seal
  count.
- `s3_server_aws_write_reconciliations_total{status="sealed|retry|integrity_conflict"}`.
  Labels are fixed and never contain owners, logical keys, or physical paths.
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
- Warn when normal cleanup capacity remaining is at or below 100,000 or the
  queue rises persistently; alert critically at zero normal capacity.
- Page when recovery cleanup capacity remaining is at or below 10,000, and
  page immediately on any recovery admission rejection.
- Database connections above 80 percent of the configured maximum.

### Access Logging

Configure bucket logging to write S3 access logs to a target bucket owned by
the same account in the same Region. The target cannot have Object Lock
enabled. The package applies explicit SSE-S3 default encryption, versioning,
and configured quotas to generated log objects. Target grants and custom log
key formats are not supported. Access-log records follow the AWS field order,
with `-` for unavailable values. Operation names and generated log keys remain
package-specific; these records are not yet fully AWS-compatible.

Access-log delivery is Best Effort. Records wait in a bounded in-memory buffer
before a background flush, so an abrupt process termination can lose buffered
records. Do not use bucket access logging as the only security or compliance
audit trail.

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
sqlite3 /var/data/s3/.opsfour/metadata.sqlite ".backup /backups/metadata-$(date +%Y%m%d).sqlite"
```

Use the path configured by `S3_METADATA_PATH` when set. Installations upgraded
from the legacy default continue to use `/var/data/s3/metadata.sqlite`. If both
paths exist, set `S3_METADATA_PATH` explicitly before starting the server.

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
that is not possible, restore metadata first, then stop every API and worker
process and run the local-storage reference audit before accepting writes:

Restore each local tier at the same canonical absolute root it used when the
backup was taken. The local filesystem backend stores absolute physical paths
in metadata so that reads, cleanup, and recovery can reject paths outside the
configured tier; copying a backup to a different root is therefore not a
supported relocation procedure and `storage:audit` will report those references
as `invalid_path`.

```bash
php bin/s3-server storage:audit \
  --storage-tier-root='["STANDARD","/var/data/s3"]' \
  --metadata-driver=sqlite \
  --metadata-path=/var/data/s3/.opsfour/metadata.sqlite
```

Repeat `--storage-tier-root='["TIER","/canonical/root"]'` for every referenced
local filesystem tier. Each value must be a two-string JSON tuple containing
the tier and root in that order; this keeps tier names such as `HOT=ARCHIVE`
byte-exact. `--storage-path=/var/data/s3` remains a shorthand for a single
`STANDARD` tier. An unmapped tier fails the audit; do not map a remote or
Flysystem tier to a local staging directory, because this command does not audit
remote storage.

Use `--metadata-driver=postgres|mysql --metadata-dsn=...` for an external
metadata database, using a dedicated account with only `SELECT` privileges.
The external connection issues only reads but does not enforce server-side
read-only mode. SQLite is opened with `mode=ro` and `query_only`.

The command takes the same exclusive per-root runtime locks as `S3Server` before
opening metadata and retains them through the audit. Any running `S3Server`
process using a mapped root therefore makes the command fail instead of
producing a live, cross-page view. Operators must also stop every separate or
custom writer and worker that does not run inside that lifecycle lock. The
persistent `.opsfour/runtime.lock` coordination file may be created; the audit
leaves object bytes and metadata unchanged.

The audit pages through every object version, committed restored copy, multipart
part, and restore-job path that still exactly matches the referenced object
placement. Stale restore jobs whose tier or path no longer matches the object
are not required to exist. It rejects paths outside their mapped canonical root,
symlinks, non-regular files, missing files, and size mismatches where the stored
format has an exact metadata size. Encrypted multipart envelopes are checked for
reference existence and file type, while their physical size is not compared to
the smaller logical part size.

This is a reference-existence check for restoring a matched backup. It does not
inventory unreferenced files or reconcile garbage rows, write intents,
multipart assembly intents, transition targets, or anomalous delete-marker
paths: those records can legitimately name bytes that are absent or awaiting
cleanup and need their recovery-specific gates. Output contains counts and
SHA-256 identifiers only; it does not print object keys, tier names, or physical
paths. A zero exit status means every enumerated required reference passed; any
finding or inventory error returns a nonzero status.

### Offline orphan inventory

After any recovery-admission rejection, stop every API process and worker for
the local-filesystem profile, then run the read-only inventory with the same
metadata and tier mapping options as `storage:audit`:

```bash
php bin/s3-server storage:inventory \
  --storage-tier-root='["STANDARD","/var/data/s3"]' \
  --default-storage-tier=STANDARD \
  --metadata-driver=sqlite \
  --metadata-path=/var/data/s3/.opsfour/metadata.sqlite \
  --scratch-path=/var/lib/opsfour-s3/inventory \
  --scratch-max-bytes=1073741824
```

The command refuses a root held by a live server, scans only qualified local
filesystems, and compares physical files with object, restored-object,
multipart-part, garbage, write-intent, transition-job, and restore-job
references. It reports SHA-256 path identifiers and leaves object bytes and
metadata unchanged; acquiring the root lock may create the persistent
`.opsfour/runtime.lock` control file. The inventory excludes that lock and the
three exact persistent local-write budget files (`budget`, `lock`, and `total`),
but deliberately reports unexpected lease, state, temporary, and staging files
for operator review. `--reveal-paths` explicitly prints local candidate paths
for a terminal operator who will quarantine or remove them through a separate,
reviewed procedure. The command makes no Flysystem, NFS, EFS, or generic remote
storage reconciliation claim. The runtime locks cover cooperating package
processes; stop any custom writer that does not acquire them before running the
inventory.

Map every local tier exactly once. With multiple mappings,
`--default-storage-tier` is required so legacy metadata and restore-job targets
without a persisted tier resolve to the configured write tier. An unmapped tier,
duplicate tier name, overlapping root, or inode-aliased root fails the command.
The exact reference set is indexed in a private temporary SQLite file with a
bounded page cache and hard byte limit. `--scratch-path` must name an existing
canonical directory outside every storage-tier root with enough free space for
the configured limit plus a 64 MiB safety reserve. `--scratch-max-bytes`
defaults to 1 GiB and accepts values from 1 MiB through 1 TiB; choose a limit
that fits the complete reference and candidate index. The temporary index is
removed on normal command exit. A persistent, empty coordination file in the
scratch directory serializes inventory runs that share its free-space reserve.

Candidate counting remains exact. Candidate output is ordered byte-exactly by
tier and path digest and limited to 500 rows per invocation. When more rows
exist, the command prints an opaque `Next candidate cursor`; pass it unchanged
to `--after-candidate` in a subsequent stopped-profile invocation. Files whose
contents begin with a reserved multipart or seal prefix but do not contain a
valid internal header are reported as `malformed_reserved_prefix` candidates
instead of aborting the inventory.

Before rollout, measure cleanup-counter backfill, first-start exact recount,
database growth, hot-row contention, and collector drain rate on a representative
copy at 900,000, 1,000,000, and a legacy count above 1,000,000. Drain and stop
old writers, run one DDL-capable migrator, verify trigger admission and metrics,
then start DML-only serving nodes. Roll back only to a package version that
understands the new schema, or restore the pre-migration metadata backup.

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

Before exposing the server to client traffic:

```bash
php -v
php -m | grep -E 'pdo|pcntl|posix|openssl'
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
provider-specific acceptance evidence for that Later profile as described in
the [roadmap](production-feature-roadmap.md).

## Network Hardening

Recommended layout:

- Expose only the S3 data-plane host publicly.
- Terminate TLS at a load balancer or reverse proxy.
- Keep `/.metrics`, `/.admin/*`, and the direct server port private.
- Allow `/.live` and `/.health` only from the load balancer, orchestrator, or monitoring
  network.
- Preserve the client `Host` header exactly, including any port: SigV4 signs
  this header. Set `S3_TRUSTED_PROXY_CIDRS` to the exact proxy-to-server peer
  CIDRs when TLS ends at the proxy. Without that opt-in, forwarded headers are
  ignored and `aws:SecureTransport` describes only the direct connection.
  Restrict direct server access so clients cannot bypass the proxy. Do not trust
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
upstream s3_server {
    server 127.0.0.1:9000;
}

proxy_set_header Host $http_host;
proxy_set_header X-Forwarded-For $remote_addr;
proxy_set_header X-Forwarded-Proto $scheme;

location = /.health {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_server;
}

location = /.live {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_server;
}

location = /.metrics {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_server;
}

location ^~ /.admin/ {
    allow 10.0.0.0/8;
    deny all;
    proxy_pass http://s3_server;
}

location / {
    proxy_request_buffering off;
    proxy_buffering off;
    client_max_body_size 0;
    proxy_pass http://s3_server;
}
```

Test the chosen proxy with signed path-style and virtual-hosted requests before
deployment. It must preserve the exact `Host` value, supply one authoritative
`X-Forwarded-Proto` value when trusted forwarding is enabled, and keep the
direct API port private. Use `GET /.health` for readiness. Provider-specific
health-routing and fail-open behavior belongs to the proxy's own deployment
review and does not change the server's one-process storage contract.

## Filesystem and Secret Permissions

Use a dedicated service user. The storage path must not be inside a web root.

```bash
install -d -o s3server -g s3server -m 0750 /var/data/s3
install -d -o s3server -g s3server -m 0750 /etc/s3-server
chmod 0640 /etc/s3-server/env
# Leave credentials.json absent until the credentials command creates valid JSON.
```

Keep encryption master keys and admin tokens in the process environment, a
secret manager, or root-owned service environment files. Do not store plaintext
keys in a repository. An existing credential file must be a non-empty regular
file owned by root or the service user with no group or other permissions. The
server refuses links, unsafe ancestors, unreadable or oversized files, and files
that change identity while being opened.

Local OIDC JWKS and PEM verification-key files follow the same ownership,
regular-file, trusted-ancestor, bounded-read, and inode-stability checks, but
they are public trust anchors rather than secrets: mode `0644` is allowed.
Group and other write bits are forbidden. Unsafe links, replaceable ancestors,
empty or unreadable files, and files larger than 1 MiB make startup fail.

## Database Least Privilege

Use a DML-only database role for PostgreSQL/MySQL runtime access. The serving
process checks the schema version and cannot migrate it on startup.

For production deployments:

- Run migrations with a separate migration role during deploy.
- Run the serving process with a runtime role limited to `SELECT`, `INSERT`,
  `UPDATE`, and `DELETE` on the application tables. For PostgreSQL, also grant
  `USAGE` on the application's `BIGSERIAL` sequences: inserts call `nextval()`
  and otherwise fail even though the role has table `INSERT`. Recheck table
  and sequence grants after each migration, including when the credential
  store uses a separate database or schema. PostgreSQL startup and readiness
  reject a serving role that lacks `USAGE` on a managed metadata sequence.
- For MySQL, keep the migration definer account and its `TRIGGER` privileges
  available. Grant the DML-only role `EXECUTE` only on the narrow
  `s3_recovery_capacity_trigger_count` definer function so it can verify
  capacity triggers at startup and during readiness; it does not need
  `TRIGGER` itself.
- Deny public network access to the database.
- Enable database TLS if traffic crosses hosts or networks you do not fully
  control.

## Rollout and Rollback

Cluster nodes may overlap only when their complete admission fingerprints
match. Compatible application-only restarts can roll behind a load balancer;
each replacement must report `cluster.admission=matched` before receiving S3
traffic. Schema changes, protocol changes, storage/fence changes, tier-map
changes, encryption-identity changes, and incompatible feature changes require
a maintenance window. Startup never replaces the established contract.

To replace the contract, remove all S3 traffic, stop every API and worker
process, take a metadata backup, and run the required migration exactly once
with the matching artifact. After verifying the new schema, use the same
DDL-capable maintenance role to run this transaction before starting any node:

```sql
BEGIN;
DELETE FROM s3_cluster_admission WHERE singleton_id = 1;
COMMIT;
```

Do not delete or modify `s3_cluster_database_identity`; it permanently binds
the metadata database to every replacement admission contract.

Start one node and wait for `cluster.admission=matched`; that node uses the
preserved database identity and atomically creates the new admission contract.
Only then start the remaining matching nodes and restore traffic. Never delete
or update the row while any API or worker process is running. Restoring the
pre-change package also requires restoring the matching metadata backup and its
admission contract.

If runtime drift or PostgreSQL loss closes admission, keep the process running
for bounded `/.live` and `/.health` diagnostics, but do not route S3 traffic or
run background claims. Readiness returns bounded node/cluster identity and
admission state, plus one category only for a persisted mismatch, and reopens
only after a full comparison succeeds; never repair this by editing
`s3_cluster_admission` while nodes are serving.

Cluster bootstrap is maintenance-only. Run `metadata:migrate` with its
DDL-capable role and record the printed `Cluster database identity` as
`S3_CLUSTER_DATABASE_ID` on every node. Run `credentials:migrate` against one
shared PostgreSQL or MySQL credential database and record its printed
credential-store identity for audit. Serving startup only reads these identity
rows; it never creates or replaces them. Memory, file, chain, SQLite, custom
credential providers and custom external-identity providers are rejected in
cluster mode. When external IAM is enabled, every node must use the same
normalized issuer and audience sets, RSA public keys, claim names, owner prefix,
and clock skew. The database credential-cache TTL must also match. Admin bearer
tokens and database credentials are not fingerprinted.

The built-in AWS backend remains unqualified for cluster operation until its
internal production qualification evidence is present, and local filesystem
storage is not cluster-safe. Consequently the public CLI configuration does
not currently provide an operator bypass or a built-in production cluster
example. Framework integrations may supply an explicitly qualified custom
storage capability; that capability, not its provider class or filesystem
type, is what admission evaluates.

The current local-filesystem profile does not support a rolling overlap on the
same storage root. Drain traffic, stop the existing process, deploy the new
package, and then start exactly one replacement process. Check `/.health`,
`/.metrics`, and a signed S3 request before restoring traffic.

For a **schema upgrade** to the current SQLite v42, PostgreSQL v46, or MySQL
v50 schema, drain and stop the
API process, including its background workers; back up
metadata; run exactly one `php bin/s3-server metadata:migrate` with a
DDL-capable role and the matching package version. If
`S3_CREDENTIALS_DRIVER=database`, also run one `php bin/s3-server
credentials:migrate` with its DDL-capable credential role, which may use a
separate database. Set both metadata and credential DSNs and passwords through
the bootstrap environment rather than placing secrets in shell arguments.
Verify the metadata version and expected indexes/column types, and verify
the credential table and columns. Then start the new API process with its
configured workers and DML-only roles, and restore traffic after readiness and
signed-request checks. The serving process checks the PostgreSQL/MySQL version
without applying DDL and refuses an old or unverifiable schema; database credentials likewise refuse
missing or legacy columns. Measure index-build/ALTER time and locks on a
representative disposable snapshot before scheduling the maintenance window.
The migrator has no cross-process DDL lock: run exactly one bootstrap job;
concurrent migration commands may repeat or race DDL.

The object-layout migration records the highest object ID present when it
starts, then scans only through that fixed target in durable 10,000-row keyset
batches. An interrupted run resumes strictly after its last committed ID and
does not publish the current schema version until all rows are classified. If
any writer creates an object beyond the recorded target, migration aborts
instead of publishing a partially classified current schema.
Simple 32-hex legacy ETags become known single-part layouts; strict multipart
ETags retain only their compatibility count; every other non-marker object is
explicitly unknown. Do not run old API nodes or workers during this maintenance
migration, and do not attempt a rolling-schema deployment.

Capacity planning must include `s3_object_parts`: every legacy object classified
as known single-part adds one child row, while unknown layouts and delete markers
add none. Size the database, temporary working space, and PostgreSQL WAL or MySQL
binlog capacity for those child inserts plus parent-row updates, and validate the
resulting volume on a representative snapshot. During the maintenance window,
observe the durable keyset checkpoint with this backend-neutral query:

```sql
SELECT migration_name, last_object_id, target_object_id
FROM s3_migration_checkpoints
WHERE migration_name = 'object_part_layouts';
```

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
