# Production Deployment

## Architecture Overview

```
                    ┌──────────────┐
                    │  Load Balancer│
                    │  (TLS term)   │
                    └──────┬───────┘
                           │
              ┌────────────┼───────────┐
              │            │           │
        ┌─────▼──┐  ┌──────▼─┐  ┌──────▼─┐
        │ S3 Node│  │ S3 Node│  │ S3 Node│
        │  :9000 │  │  :9000 │  │  :9000 │
        └────┬───┘  └────┬───┘  └────┬───┘
             │           │           │
             └─────┬─────┘───────────┘
                   │
            ┌──────▼──────┐    ┌──────────┐
            │ PostgreSQL  │    │ Shared   │
            │ (metadata)  │    │ Storage  │
            └─────────────┘    └──────────┘
```

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

## Multi-Node Deployment

For high availability and horizontal scaling, use PostgreSQL/MySQL and shared storage.

```bash
# All nodes share these settings
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=db.internal port=5432 dbname=s3server user=s3 password=secret"
S3_CREDENTIALS_DRIVER=database
S3_CREDENTIALS_DSN="pgsql:host=db.internal;port=5432;dbname=s3server"
S3_CREDENTIALS_USERNAME=s3
S3_CREDENTIALS_PASSWORD=secret
S3_CREDENTIALS_CACHE_TTL=1
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/mnt/shared-storage/s3   # NFS, EFS, or other shared filesystem
```

In multi-node mode:
- Rate limiting is shared across all nodes (database-backed)
- Credential revocations and rotations become visible on every node within
  `S3_CREDENTIALS_CACHE_TTL` seconds; set it to `0` for a database read on
  every authenticated request
- Notification queue is processed by all nodes with `FOR UPDATE SKIP LOCKED` (no duplicates)
- Schema migrations are idempotent and safe to run concurrently

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

Configure the load balancer to forward `Host`, `X-Forwarded-For`, and `X-Forwarded-Proto` headers.

## Health Checks

```bash
# Process liveness
curl -f http://localhost:9000/.live
# Dependency readiness (metadata and every storage tier)
curl -f http://localhost:9000/.health
# {"status":"ok"}
```

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
Description=OpsFour S3 Server
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

RUN apt-get update && apt-get install -y curl libpq-dev \
    && docker-php-ext-install pdo_pgsql pcntl \
    && pecl install ev \
    && docker-php-ext-enable ev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 9000
HEALTHCHECK CMD curl -f http://localhost:9000/.live || exit 1

CMD ["php", "vendor/bin/s3-server"]
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

Rate limiting fails open on database errors — a temporary DB issue won't block all requests.

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
- `s3_server_notification_deliveries_total{status}`
- `s3_server_worker_pool_tasks_total{pool,operation,status}`
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

Configure bucket logging to write S3 access logs to a target bucket:

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

For external S3-compatible storage backends, run the opt-in integration tests
with real credentials in a non-production bucket. Use a dedicated bucket and
short object retention so failed test data is easy to clean up.

## Network Hardening

Recommended layout:

- Expose only the S3 data-plane host publicly.
- Terminate TLS at a load balancer or reverse proxy.
- Keep `/.metrics`, `/.admin/*`, and direct node ports private.
- Allow `/.live` and `/.health` only from the load balancer, orchestrator, or monitoring
  network.
- Forward `Host`, `X-Forwarded-For`, and `X-Forwarded-Proto`.

Example Nginx policy for admin surfaces:

```nginx
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
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_request_buffering off;
    proxy_buffering off;
    client_max_body_size 0;
    proxy_pass http://s3_nodes;
}
```

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

Use one database role for normal runtime access. It needs DML on the S3 server
tables and schema migration privileges only if this node is allowed to migrate
on startup.

For stricter deployments:

- Run migrations with a separate migration role during deploy.
- Run serving nodes with a runtime role limited to `SELECT`, `INSERT`,
  `UPDATE`, and `DELETE` on the application tables.
- Deny public network access to the database.
- Enable database TLS if traffic crosses hosts or networks you do not fully
  control.

## Rollout and Rollback

Use rolling deployment:

1. Start a new node with the new package version.
2. Wait for `/.health` and `/.metrics`.
3. Send a small signed S3 request through the load balancer.
4. Add the node to traffic.
5. Drain and stop one old node.
6. Repeat.

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
