# Installation

## Requirements

- **PHP 8.4+** with the following extensions:
  - `ext-dom`, `ext-libxml`, and `ext-simplexml` (required for S3 XML requests and responses)
  - `ext-mbstring` (required for UTF-8 S3 Select string functions)
  - `ext-openssl` (always required for encrypted request-body temporary spools)
  - `ext-pdo_sqlite` (default metadata backend)
  - `ext-pdo_pgsql` (optional, for PostgreSQL metadata)
  - `ext-pdo_mysql` (optional, for MySQL metadata)
  - `ext-pcntl` (required for Linux process control and signal handling)
  - `ext-posix` (required for process and credential-file ownership verification)
- PHP's `proc_open` function must be enabled for the package's local helper
  worker processes.
- **PostgreSQL API nodes:** `pecl-pq` and its `pecl-raphf` dependency are
  required; `ext-pdo_pgsql` alone does not satisfy the server startup check.
- **Composer 2.x**
- **Symfony 7.4 LTS or 8.1+** when using the Symfony Bundle
- **Laravel 12 or 13** when using the Laravel service provider
- **aws/aws-sdk-php** when selecting the explicit `aws-s3` provider profile

## Install via Composer

```bash
composer require opsfour/s3-server
```

## Standalone Usage

Composer exports the package CLI as `vendor/bin/s3-server`. No framework is
required. `php vendor/bin/s3-server --version` reports the installed package
version.

```bash
export S3_ACCESS_KEY=myAccessKey
export S3_SECRET_KEY=mySecretKey

php vendor/bin/s3-server \
  --host=0.0.0.0 \
  --port=9000 \
  --storage-path=/var/data/s3
```

Load credentials from a protected environment file or the process
supervisor's secret store. Passing a secret key on the command line can expose
it through shell history or process listings.

### CLI Options

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `0.0.0.0` | Listen address |
| `--port` | `9000` | Listen port |
| `--storage-path` | (required) | Root directory for object data |
| `--storage-driver` | `filesystem` | `filesystem`, `aws-s3`, `flysystem`, or `memory` |
| `--storage-temp-dir` | `sys_get_temp_dir()/opsfour-s3-server-temp` | Canonical node-local coordinator root shared by request spools and every storage staging writer |
| `--storage-temp-budget` | `10737418240` | Aggregate node-local temporary byte budget shared by all API and worker processes |
| `--storage-temp-reserve` | `1073741824` | Physical free-space safety reserve retained on every involved staging filesystem |
| `--post-spool-budget` | unset | Deprecated alias for `--storage-temp-budget`; alone it supplies the canonical value, and when both are set they must match |
| `--flysystem-workers` | `0` | Bounded remote-storage workers; standalone Flysystem requires at least 1 |
| `--backing-bucket` | - | Backing S3 bucket for standalone Flysystem |
| `--backing-endpoint` | AWS default | HTTPS backing S3-compatible endpoint |
| `--backing-allow-insecure-http-for-tests` | `false` | Unsafe test-only opt-in for an HTTP backing endpoint; never use in production |
| `--backing-path-style` | `false` | Use path-style backing requests |
| `--aws-s3-bucket` | - | General Purpose Bucket for the explicit AWS profile |
| `--aws-s3-region` | - | Known commercial AWS region for the explicit AWS profile |
| `--aws-s3-expected-owner-id` | - | Expected 12-digit bucket-owner account ID |
| `--aws-s3-data-prefix` | - | Isolated data prefix |
| `--aws-s3-reserved-prefix` | - | Non-overlapping future write-attempt prefix |
| `--aws-s3-connect-timeout` | `5` | Per-request provider-admission connection timeout in seconds |
| `--aws-s3-read-timeout` | `15` | Per-request provider-admission read timeout in seconds |
| `--aws-s3-request-timeout` | `20` | Timeout for each provider-admission request, not the whole admission sequence |
| `--region` | `us-east-1` | AWS region identifier |
| `--metadata-driver` | `sqlite` | `sqlite`, `postgres`, or `mysql` |
| `--metadata-dsn` | (auto) | Database DSN for postgres/mysql |
| `--metadata-path` | (auto for filesystem storage) | Standalone SQLite database path; required with non-filesystem storage (`:memory:` is an explicit test-only choice) |
| `--cluster-roles` | - | Required cluster process roles, comma-separated |
| `--cluster-required-roles` | all roles | Shared required-role coverage policy, comma-separated |
| `--credentials-driver` | `memory` | `memory`, `database`, `file` |
| `--access-key` | - | Access key (memory driver) |
| `--secret-key` | - | Secret key override for isolated local use; prefer a protected environment or secret store |
| `--owner-id` | `default-owner` | Owner ID (memory driver) |
| `--display-name` | `Default User` | Display name (memory driver) |
| `--max-connections` | `10000` | Max concurrent connections |
| `--tls-cert` | - | Path to TLS certificate |
| `--tls-key` | - | Path to TLS private key |

### Environment Variables

All CLI options can be set via environment variables. See [Configuration Reference](configuration.md).

```bash
export S3_HOST=0.0.0.0
export S3_PORT=9000
export S3_STORAGE_PATH=/var/data/s3
export S3_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE
export S3_SECRET_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY

php vendor/bin/s3-server
```

## Laravel Installation

The package is framework-optional and does not require Laravel directly. The
service provider is tested with Laravel 12 and 13. Laravel 11 reached the end
of security support on March 12, 2026 and is not a production-supported target.

```bash
composer require opsfour/s3-server
```

The service provider is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --provider="OpsFour\S3Server\Laravel\S3ServerServiceProvider"
```

This creates `config/s3-server.php`. See [Laravel Integration](laravel-integration.md) for details.

Start the server:

```bash
php artisan s3:serve
```

## Symfony Installation

The Symfony Bundle supports maintained Symfony 7.4 LTS and 8.1+. The package requires
`symfony/console` for the standalone CLI and keeps the bundle-specific Symfony
components optional so non-Symfony users do not install a full framework stack.

```bash
composer require opsfour/s3-server
```

Register `OpsFour\S3Server\Symfony\S3ServerBundle` in `config/bundles.php`,
configure `config/packages/opsfour_s3_server.yaml`, and start the server:

```bash
php bin/console opsfour:s3:serve
```

See [Symfony Integration](symfony-integration.md) for configuration, commands,
and production examples.

## Programmatic Usage

Embed the S3 server in your own PHP application:

```php
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Notification\NotificationDispatcher;

// 1. Configure
$config = new S3ServerConfig(
    host: '0.0.0.0',
    port: 9000,
    region: 'us-east-1',
    storagePath: '/var/data/s3',
);

// 2. Create backends
$storage  = StorageBackendFactory::create('filesystem', ['path' => '/var/data/s3']);
$metadata = MetadataStoreFactory::create('sqlite', [
    'path' => '/var/data/s3/.opsfour/metadata.sqlite',
]);
$metadata->initialize();

// 3. Create server
$server = new S3Server($config, $metadata, $storage);

// 4. Add authentication
$credentials = new InMemoryCredentialProvider(
    accessKeyId: 'myAccessKey',
    secretAccessKey: 'mySecretKey',
    ownerId: 'owner-1',
    displayName: 'Admin',
);
$server->addMiddleware(new AuthMiddleware(
    credentialProvider: $credentials,
    region: $config->region,
    requestBodySizeLimit: $config->requestBodySizeLimit,
    metadata: $metadata,
    postSpoolBudgetBytes: $config->postSpoolBudgetBytes,
    postSpoolCoordinator: $server->preparePostObjectSpoolCoordinator(),
));

// 5. Register S3 handlers
$notifications = new NotificationDispatcher($metadata);
HandlerRegistrar::registerAll(
    $server->getHandlerRegistry(),
    $metadata,
    $storage,
    $config,
    notifications: $notifications,
);

// 6. Start
$server->start();

// Wait for shutdown signal
\Amp\trapSignal([\SIGINT, \SIGTERM]);
$server->stop();
```

## Verify Installation

```bash
# Health check
curl http://localhost:9000/.health
# {"status":"ok"}

# List buckets (should return empty list)
aws --endpoint-url http://localhost:9000 s3 ls
```
