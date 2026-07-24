# Storage Backends

The storage backend is responsible for persisting object data (the actual bytes). Metadata (bucket/object records, ACLs, etc.) is handled separately by the [metadata backend](metadata-backends.md).

## Filesystem (Default)

Stores objects as files on the local filesystem. Best for single-server deployments.

```bash
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/var/data/s3
```

Objects are stored with content-addressable paths under the storage root. Atomic writes use temp files with rename to prevent partial reads.

### Requirements

- The storage path must be writable by the server process
- Sufficient disk space for your expected data volume
- Use a filesystem that supports `O_TMPFILE` or atomic rename (ext4, XFS, ZFS)

## Flysystem

Stores objects via the [League\Flysystem](https://flysystem.thephpleague.com/) abstraction. Supports any Flysystem adapter: S3, GCS, Azure Blob, SFTP, and more.

```bash
S3_STORAGE_DRIVER=flysystem
```

### Standalone S3-Compatible Backend

The standalone CLI can construct the bundled serializable S3-compatible worker
factory directly from environment variables:

```bash
S3_STORAGE_DRIVER=flysystem
S3_STORAGE_TEMP_DIR=/var/lib/opsfour-s3/tmp
S3_FLYSYSTEM_WORKERS=8
S3_BACKING_BUCKET=backing-bucket
S3_BACKING_REGION=eu-central-1
S3_BACKING_ACCESS_KEY=change-me
S3_BACKING_SECRET_KEY=change-me
S3_BACKING_ENDPOINT=https://eu-central-1.linodeobjects.com
S3_BACKING_PATH_STYLE=true
S3_BACKING_PREFIX=production
```

Standalone Flysystem mode rejects `S3_FLYSYSTEM_WORKERS=0` because synchronous
remote calls would block the Amp event loop. It never creates
`S3_STORAGE_PATH`; only `S3_STORAGE_TEMP_DIR` and an optional local SQLite
metadata path are created locally.

### Programmatic Setup

For development and low-concurrency use, Flysystem accepts a configured
`FilesystemOperator` instance:

```php
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Aws\S3\S3Client;
use OpsFour\S3Server\Factory\StorageBackendFactory;

$s3Client = new S3Client([...]);
$adapter = new AwsS3V3Adapter($s3Client, 'backing-bucket');
$flysystem = new Filesystem($adapter);

$storage = StorageBackendFactory::create('flysystem', [
    'filesystem' => $flysystem,
]);
```

`FilesystemOperator` is synchronous. Calling it directly blocks the Amp event
loop for the duration of each adapter call. Production remote storage must use
the bounded worker mode instead:

```php
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;

$factory = new AwsS3FlysystemFilesystemFactory(
    remoteBucket: $_ENV['S3_BACKING_BUCKET'],
    region: $_ENV['S3_BACKING_REGION'],
    accessKeyId: $_ENV['S3_BACKING_ACCESS_KEY'],
    secretAccessKey: $_ENV['S3_BACKING_SECRET_KEY'],
    endpoint: $_ENV['S3_BACKING_ENDPOINT'],
    pathStyle: true,
);

$storage = StorageBackendFactory::create('flysystem', [
    'filesystem_factory' => $factory,
    'worker_pool_size' => 8,
    'temp_dir' => '/var/lib/opsfour-s3/tmp',
]);
```

The factory is serialized to local worker processes. Use a dedicated backing
credential and protect process memory and local IPC with normal host-level
controls. `AwsS3FlysystemFilesystemFactory` requires
`aws/aws-sdk-php` and `league/flysystem-aws-s3-v3`.

### Framework Configuration

Laravel users can bind a configured `League\Flysystem\FilesystemOperator` and
pass it through the package config. Symfony users can register the filesystem as
a service and reference it with `filesystem_service`:

```yaml
opsfour_s3_server:
  storage:
    driver: flysystem
    filesystem_factory_service: 'app.s3_backing_filesystem_factory'
    worker_pool_size: 8
    temp_dir: '%kernel.cache_dir%/opsfour-s3-temp'
```

The same service-reference pattern works inside `storage.tiers` for hot/cold
physical tiering.

### Temporary Local Files

Remote Flysystem backends are the durable object store. The server may still use
local temp files for uploads, downloads, multipart assembly, copy/restore
bridges, and streaming transformations. Memory use stays bounded, but local
disk capacity must cover concurrent in-flight transfers. Configure `temp_dir`
on every Flysystem tier, monitor free space, and size the worker pool against
both remote-provider limits and local staging capacity.

### Use Cases

- **S3 → S3 proxy**: Front an existing S3 bucket with custom auth, rate limiting, or policies
- **Multi-cloud**: Store objects on GCS or Azure while exposing an S3-compatible API
- **SFTP bridge**: Serve files from an SFTP server via S3 API

## In-Memory

Stores all objects in RAM. Data is lost on server restart.

```bash
S3_STORAGE_DRIVER=memory
```

### Use Cases

- Unit and integration testing
- Ephemeral development environments
- Benchmarking the metadata layer without disk I/O

## Choosing a Backend

| Backend | Persistence | Speed | Multi-Node | Use Case |
|---------|-------------|-------|------------|----------|
| Filesystem | Disk | Fast | No | Production (single node) |
| Flysystem workers | Depends on adapter | Bounded worker pool | Yes (S3/GCS) | Multi-cloud, proxying |
| Memory | None | Fastest | No | Testing only |

For production single-node deployments, use `filesystem`. For multi-node or
cloud-native deployments, use Flysystem worker mode with a shared storage
adapter. Direct `FilesystemOperator` mode is not suitable for a 24/7 Amp
process because synchronous remote I/O can stall unrelated requests.

## Physical Storage Tiers

Lifecycle transitions and `RestoreObject` can move object data between named
storage tiers. Each tier has its own backend and optional `restore_required`
flag:

```yaml
opsfour_s3_server:
  storage:
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

Reads from restore-required tiers return `InvalidObjectState` until a temporary
hot copy exists through the restore workflow.
