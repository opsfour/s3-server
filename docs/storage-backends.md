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

### Programmatic Setup

Flysystem requires passing a configured `FilesystemOperator` instance:

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

### Framework Configuration

Laravel users can bind a configured `League\Flysystem\FilesystemOperator` and
pass it through the package config. Symfony users can register the filesystem as
a service and reference it with `filesystem_service`:

```yaml
opsfour_s3_server:
  storage:
    driver: flysystem
    filesystem_service: 'app.s3_backing_filesystem'
    temp_dir: '%kernel.cache_dir%/opsfour-s3-temp'
```

The same service-reference pattern works inside `storage.tiers` for hot/cold
physical tiering.

### Temporary Local Files

Remote Flysystem backends are the durable object store. The server may still use
local temp files or temp streams for multipart assembly, copy/restore bridges,
and streaming transformations. Configure `temp_dir` on Flysystem backends and
tiers for deployments where `/tmp` is small or ephemeral.

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
| Flysystem | Depends on adapter | Varies | Yes (S3/GCS) | Multi-cloud, proxying |
| Memory | None | Fastest | No | Testing only |

For production single-node deployments, use `filesystem`. For multi-node or cloud-native deployments, use `flysystem` with a shared storage adapter.

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
