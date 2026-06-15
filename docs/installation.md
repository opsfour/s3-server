# Installation

## Requirements

- **PHP 8.4+** with the following extensions:
  - `ext-openssl` (required for encryption)
  - `ext-pdo_sqlite` (default metadata backend)
  - `ext-pdo_pgsql` (optional, for PostgreSQL metadata)
  - `ext-pdo_mysql` (optional, for MySQL metadata)
  - `ext-pcntl` (recommended, for signal handling)
- **Composer 2.x**

## Install via Composer

```bash
composer require opsfour/s3-server
```

## Standalone Usage

The package includes a CLI binary at `vendor/bin/s3-server`. No framework required.

```bash
php vendor/bin/s3-server \
  --host=0.0.0.0 \
  --port=9000 \
  --storage-path=/var/data/s3 \
  --access-key=AKIAIOSFODNN7EXAMPLE \
  --secret-key=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

### CLI Options

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `0.0.0.0` | Listen address |
| `--port` | `9000` | Listen port |
| `--storage-path` | (required) | Root directory for object data |
| `--storage-driver` | `filesystem` | `filesystem`, `flysystem`, or `memory` |
| `--region` | `us-east-1` | AWS region identifier |
| `--metadata-driver` | `sqlite` | `sqlite`, `postgres`, or `mysql` |
| `--metadata-dsn` | (auto) | Database DSN for postgres/mysql |
| `--credentials-driver` | `memory` | `memory`, `database`, `file` |
| `--access-key` | - | Access key (memory driver) |
| `--secret-key` | - | Secret key (memory driver) |
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
    'path' => '/var/data/s3/metadata.sqlite',
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
$server->addMiddleware(new AuthMiddleware($credentials, 'us-east-1'));

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
