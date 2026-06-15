# Laravel Integration

OpsFour S3 Server integrates with Laravel via a service provider and Artisan command.

## Installation

```bash
composer require opsfour/s3-server
```

The `S3ServerServiceProvider` is auto-discovered via Composer's `extra.laravel.providers`.

## Publish Configuration

```bash
php artisan vendor:publish --provider="OpsFour\S3Server\Laravel\S3ServerServiceProvider"
```

This creates `config/s3-server.php` with all available settings.

## .env Configuration

Add to your `.env`:

```bash
# Required
S3_STORAGE_PATH=/var/data/s3
S3_ACCESS_KEY=your-access-key
S3_SECRET_KEY=your-secret-key

# Optional
S3_PORT=9000
S3_REGION=us-east-1
S3_METADATA_DRIVER=sqlite
S3_RATE_LIMIT=1000
```

See [Configuration Reference](configuration.md) for all variables.

## Start the Server

```bash
php artisan s3:serve
```

### Artisan Command Options

```bash
php artisan s3:serve \
  --host=0.0.0.0 \
  --port=9000 \
  --access-key=myKey \
  --secret-key=mySecret
```

CLI options override `.env` values.

## Service Container Bindings

The service provider registers these singletons:

| Abstract | Concrete | Description |
|----------|----------|-------------|
| `S3ServerConfig` | Config DTO | Resolved from `config('s3-server')` |
| `StorageBackend` | Filesystem/Flysystem/Memory | Based on `S3_STORAGE_DRIVER` |
| `MetadataStore` | SQLite/Postgres/MySQL | Based on `S3_METADATA_DRIVER` |
| `CredentialProvider` | Memory/Database/File | Based on `S3_CREDENTIALS_DRIVER` |

### Resolving in Your Code

```php
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;

// In a controller or service
$metadata = app(MetadataStore::class);
$storage  = app(StorageBackend::class);
```

## Using with Laravel's Filesystem

You can point Laravel's S3 filesystem driver at your local S3 server for development:

```php
// config/filesystems.php
'disks' => [
    'local-s3' => [
        'driver' => 's3',
        'key'    => env('S3_ACCESS_KEY'),
        'secret' => env('S3_SECRET_KEY'),
        'region' => env('S3_REGION', 'us-east-1'),
        'bucket' => env('S3_BUCKET', 'my-bucket'),
        'url'    => env('S3_URL', 'http://localhost:9000'),
        'endpoint' => env('S3_ENDPOINT', 'http://localhost:9000'),
        'use_path_style_endpoint' => true,
    ],
],
```

```php
// Usage
Storage::disk('local-s3')->put('file.txt', 'contents');
Storage::disk('local-s3')->get('file.txt');
```

## Production with Supervisor

```ini
; /etc/supervisor/conf.d/s3-server.conf
[program:s3-server]
command=php /var/www/app/artisan s3:serve
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/s3-server.log
stopwaitsecs=35
stopsignal=SIGTERM
```

## Using with PostgreSQL in Production

```bash
# .env
S3_METADATA_DRIVER=postgres
S3_METADATA_DSN="host=127.0.0.1 port=5432 dbname=s3server user=s3 password=secret"
S3_CREDENTIALS_DRIVER=database
S3_CREDENTIALS_DSN="host=127.0.0.1 port=5432 dbname=s3server user=s3 password=secret"
```

Tables are auto-created on first start via the built-in schema manager.
