# Symfony Integration

OpsFour S3 Server can run as a Symfony Bundle. The bundle wires the same core
runtime used by the standalone and Laravel entry points, so S3 behavior remains
consistent across integrations.

## Installation

```bash
composer require opsfour/s3-server
```

Symfony support uses optional Symfony components. In a normal Symfony
7 or 8 application these are already present. If you embed the bundle into a
custom application, install:

```bash
composer require symfony/http-kernel symfony/dependency-injection symfony/config
```

## Register the Bundle

Add the bundle to `config/bundles.php`:

```php
<?php

return [
    // ...
    OpsFour\S3Server\Symfony\S3ServerBundle::class => ['all' => true],
];
```

## Minimal Configuration

Create `config/packages/opsfour_s3_server.yaml`:

```yaml
opsfour_s3_server:
  server:
    host: '0.0.0.0'
    port: 9000
    region: 'us-east-1'

  storage:
    driver: filesystem
    path: '%kernel.project_dir%/var/s3'

  metadata:
    driver: sqlite
    path: '%kernel.project_dir%/var/s3/metadata.sqlite'
    cache_ttl: 5.0

  credentials:
    driver: file
    path: '%kernel.project_dir%/var/s3/credentials.json'
```

Start the server:

```bash
php bin/console opsfour:s3:serve
```

CLI overrides:

```bash
php bin/console opsfour:s3:serve --host=127.0.0.1 --port=9001
```

## Environment Variables

Symfony can map environment variables through its normal `%env(...)%`
processors:

```yaml
opsfour_s3_server:
  server:
    host: '%env(S3_HOST)%'
    port: '%env(int:S3_PORT)%'
    region: '%env(S3_REGION)%'
    body_size_limit: '%env(int:S3_BODY_SIZE_LIMIT)%'

  storage:
    driver: '%env(S3_STORAGE_DRIVER)%'
    path: '%env(S3_STORAGE_PATH)%'

  metadata:
    driver: '%env(S3_METADATA_DRIVER)%'
    dsn: '%env(S3_METADATA_DSN)%'
    cache_ttl: '%env(float:S3_METADATA_CACHE_TTL)%'

  credentials:
    driver: '%env(S3_CREDENTIALS_DRIVER)%'
    access_key: '%env(S3_ACCESS_KEY)%'
    secret_key: '%env(S3_SECRET_KEY)%'
    owner_id: '%env(S3_OWNER_ID)%'
    display_name: '%env(S3_DISPLAY_NAME)%'
    dsn: '%env(S3_CREDENTIALS_DSN)%'
    path: '%env(S3_CREDENTIALS_PATH)%'
```

For production, prefer explicit Symfony config parameters or secrets for
credentials and DSNs. The environment variable names intentionally match the
standalone and Laravel configuration where possible.

## Commands

### Serve

```bash
php bin/console opsfour:s3:serve
```

Options:

| Option | Description |
|--------|-------------|
| `--host` | Listen address override |
| `--port` | Listen port override |
| `--admin-token` | Admin API token override |

The command handles `SIGINT` and `SIGTERM` and shuts the server down through the
shared runtime drain path.

### Credentials

Use persistent credentials for production. The command intentionally rejects the
`memory` credential provider because changes would be lost on restart.

```bash
php bin/console opsfour:s3:credentials create \
  --owner-id=account-a \
  --display-name="Account A"

php bin/console opsfour:s3:credentials list
php bin/console opsfour:s3:credentials show ACCESS_KEY_ID
php bin/console opsfour:s3:credentials deactivate ACCESS_KEY_ID
php bin/console opsfour:s3:credentials activate ACCESS_KEY_ID
php bin/console opsfour:s3:credentials delete ACCESS_KEY_ID
```

The command operates on the credential provider configured in
`opsfour_s3_server.credentials`.

### Quotas

```bash
php bin/console opsfour:s3:quotas set account-a \
  --max-buckets=10 \
  --max-objects-per-bucket=100000 \
  --max-bytes-per-bucket=107374182400 \
  --max-bytes=536870912000

php bin/console opsfour:s3:quotas show account-a
php bin/console opsfour:s3:quotas list
php bin/console opsfour:s3:quotas delete account-a
```

The command operates on the metadata store configured in
`opsfour_s3_server.metadata`.

## Production Configuration

Use PostgreSQL or MySQL metadata for long-running production deployments.
SQLite is useful for local development and tests.

```yaml
opsfour_s3_server:
  server:
    host: '0.0.0.0'
    port: 9000
    region: 'us-east-1'
    max_connections: 10000
    body_size_limit: 5368709120
    connection_idle_timeout: 60
    read_timeout: 300
    write_timeout: 300
    shutdown_drain_timeout: 30
    strict_bucket_naming: true

  storage:
    driver: filesystem
    path: '/var/lib/opsfour-s3/objects'

  metadata:
    driver: postgres
    dsn: 'host=127.0.0.1 port=5432 dbname=s3server user=s3 password=secret'
    cache_ttl: 5.0

  credentials:
    driver: database
    dsn: 'host=127.0.0.1 port=5432 dbname=s3server user=s3 password=secret'

  quotas:
    max_buckets_per_owner: 0
    max_objects_per_bucket: 0
    max_bytes_per_bucket: 0
    max_bytes_per_owner: 0

  lifecycle:
    interval_seconds: 60.0
    batch_size: 1000
    max_actions_per_run: 1000
    lock_ttl_seconds: 300

  parallel:
    sqlite_workers: 0
    encryption_workers: 0
    encryption_threshold: 65536
```

## Storage Tiers

Physical tiering can be configured through named storage tiers:

```yaml
opsfour_s3_server:
  storage:
    driver: filesystem
    path: '/var/lib/opsfour-s3/standard'
    tiers:
      STANDARD:
        driver: filesystem
        path: '/var/lib/opsfour-s3/standard'
        default: true

      GLACIER:
        driver: filesystem
        path: '/var/lib/opsfour-s3/glacier'
        restore_required: true
```

Lifecycle transitions, restore jobs, and cold-tier reads are handled by the core
runtime.

## Remote Storage Backends

For remote storage, register a `League\Flysystem\FilesystemOperator` as a
Symfony service and reference it through `filesystem_service`.

Install the adapter required by your backing storage. For an S3-compatible
backing bucket:

```bash
composer require league/flysystem-aws-s3-v3
```

Example `config/services.yaml`:

```yaml
services:
  app.s3_backing_client:
    class: Aws\S3\S3Client
    arguments:
      -
        version: 'latest'
        region: '%env(S3_BACKING_REGION)%'
        endpoint: '%env(S3_BACKING_ENDPOINT)%'
        use_path_style_endpoint: true
        credentials:
          key: '%env(S3_BACKING_ACCESS_KEY)%'
          secret: '%env(S3_BACKING_SECRET_KEY)%'

  app.s3_backing_adapter:
    class: League\Flysystem\AwsS3V3\AwsS3V3Adapter
    arguments:
      - '@app.s3_backing_client'
      - '%env(S3_BACKING_BUCKET)%'

  app.s3_backing_filesystem:
    class: League\Flysystem\Filesystem
    arguments:
      - '@app.s3_backing_adapter'
```

Then reference the service from `config/packages/opsfour_s3_server.yaml`:

```yaml
opsfour_s3_server:
  storage:
    driver: flysystem
    filesystem_service: 'app.s3_backing_filesystem'
    temp_dir: '%kernel.cache_dir%/opsfour-s3-temp'
```

The same pattern works for physical tiers:

```yaml
opsfour_s3_server:
  storage:
    driver: flysystem
    path: '%kernel.project_dir%/var/s3'
    tiers:
      STANDARD:
        driver: flysystem
        filesystem_service: 'app.hot_filesystem'
        default: true

      GLACIER:
        driver: flysystem
        filesystem_service: 'app.cold_filesystem'
        restore_required: true
```

The server still uses local temporary files or temp streams for multipart
assembly and streaming bridges. Object data is persisted in the configured
remote backend.

## Symfony Event Listeners

Symfony services can subscribe to internal S3 events without using webhook
notification destinations.

Create an invokable listener service:

```php
<?php

namespace App\S3;

use OpsFour\S3Server\Event\S3Event;

final class AuditS3EventListener
{
    public function __invoke(S3Event $event): void
    {
        // Persist audit record, enqueue an application job, or notify another system.
    }
}
```

Register it as a Symfony service and reference it from
`opsfour_s3_server.notifications.listeners`:

```yaml
services:
  App\S3\AuditS3EventListener: ~

opsfour_s3_server:
  notifications:
    listeners:
      - event: 's3:ObjectCreated:*'
        service: 'App\S3\AuditS3EventListener'
      - event: 's3:ObjectRemoved:*'
        service: 'App\S3\AuditS3EventListener'
```

Supported event patterns match the core dispatcher:

- exact names, for example `s3:ObjectCreated:Put`
- wildcard suffixes, for example `s3:ObjectCreated:*`
- global wildcard `s3:*`

You can also bridge every matching S3 event into Symfony's EventDispatcher:

```yaml
opsfour_s3_server:
  notifications:
    symfony_event_dispatcher:
      service: 'event_dispatcher'
      pattern: 's3:*'
```

With this setup, Symfony listeners can listen to S3 event names such as
`s3:ObjectCreated:Put` and receive `OpsFour\S3Server\Event\S3Event`.

```php
use OpsFour\S3Server\Event\S3Event;

$dispatcher->addListener('s3:ObjectCreated:Put', function (S3Event $event): void {
    // ...
});
```

For a fixed Symfony event name, set `event_name`:

```yaml
opsfour_s3_server:
  notifications:
    symfony_event_dispatcher:
      service: 'event_dispatcher'
      pattern: 's3:*'
      event_name: 'opsfour.s3_event'
```

## Encryption Services

Symfony can use the default environment-based encryption setup, or you can
provide encryption services through the container.

To provide a complete encryption service, register a service implementing
`OpsFour\S3Server\Encryption\EncryptionServiceInterface`:

```yaml
services:
  App\S3\Encryption\TenantAwareEncryptionService: ~

opsfour_s3_server:
  encryption:
    service: 'App\S3\Encryption\TenantAwareEncryptionService'
```

To keep the built-in encryption implementation but provide the master keys from
a Symfony service, register a service implementing
`OpsFour\S3Server\Encryption\MasterKeyProvider`:

```yaml
services:
  App\S3\Encryption\SymfonyVaultMasterKeyProvider: ~

opsfour_s3_server:
  encryption:
    master_key_provider_service: 'App\S3\Encryption\SymfonyVaultMasterKeyProvider'
```

When `parallel.encryption_workers` is greater than zero, the bundle builds a
`ParallelEncryptionService` from the configured master key provider. Otherwise
it builds the normal inline `EncryptionService`.

SSE-S3 and SSE-C use the core server limits:

```yaml
opsfour_s3_server:
  server:
    max_encrypted_object_size: 268435456

  parallel:
    encryption_workers: 0
    encryption_threshold: 65536
```

`max_encrypted_object_size` applies to server-side encryption paths that require
buffered encryption. SSE-C request validation remains part of the core S3
handlers; no separate Symfony-only setting is required.

## AWS SDK Client

```php
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'endpoint' => 'http://127.0.0.1:9000',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => $_ENV['S3_ACCESS_KEY'],
        'secret' => $_ENV['S3_SECRET_KEY'],
    ],
]);

$s3->createBucket(['Bucket' => 'example']);
$s3->putObject([
    'Bucket' => 'example',
    'Key' => 'hello.txt',
    'Body' => 'hello',
]);
```

## Supervisor

```ini
[program:opsfour-s3-server]
command=php /var/www/app/bin/console opsfour:s3:serve
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/opsfour-s3-server.log
stopwaitsecs=35
stopsignal=TERM
```

## systemd

```ini
[Unit]
Description=OpsFour S3 Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php /var/www/app/bin/console opsfour:s3:serve
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=35

[Install]
WantedBy=multi-user.target
```

## Current Symfony Scope

Implemented:

- Bundle registration through `S3ServerBundle`
- `opsfour_s3_server` configuration tree
- Core service wiring for config, storage, metadata, credentials, metrics,
  storage tiers, runtime factory
- `opsfour:s3:serve`
- `opsfour:s3:credentials`
- `opsfour:s3:quotas`
- Flysystem storage service references through `filesystem_service`
- Symfony service listeners through `notifications.listeners`
- Symfony EventDispatcher bridge through `notifications.symfony_event_dispatcher`
- Encryption service overrides through `encryption.service`
- Master key provider service overrides through `encryption.master_key_provider_service`
- Metadata cache TTL through `metadata.cache_ttl`
- Container compile tests and command smoke tests

Optional follow-ups:

- Additional convenience commands if needed

See [Symfony Integration Plan](symfony-integration-plan.md) for the completed
checklist and remaining optional ecosystem work.
