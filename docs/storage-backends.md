# Storage Backends

The storage backend is responsible for persisting object data (the actual bytes). Metadata (bucket/object records, ACLs, etc.) is handled separately by the [metadata backend](metadata-backends.md).

The status terms below come from the
[production feature roadmap](production-feature-roadmap.md). This is the
canonical storage support matrix:

| Backend | Status | Current use |
|---------|--------|-------------|
| Persistent local filesystem on Linux | Implemented and Supported; exact Linux/XFS/SQLite reference profile Qualified | v2.0.0 one-host, one-process release baseline; other metadata, filesystem, mount, device, and host combinations require their applicable gates |
| Explicit AWS S3 provider (`aws-s3`) | Provider admission plus ordinary-object, multipart, lifecycle-transition, and restore-copy fences implemented; full writer fence incomplete / not production-qualified | Read-only admission, internal PUT/POST/Copy/UploadPart/UploadPartCopy/completion/transition/restore fences, exact current-v2 source reads; API startup always fails closed |
| Flysystem | Implemented; Experimental / test-only | Provider-specific integration and fault testing |
| Memory | Implemented; Experimental / test-only | Unit tests, integration tests, and disposable development |
| NFS, EFS, SMB/CIFS, FUSE, or another shared filesystem | Experimental / test-only | Requires qualification of the exact filesystem, mount, kernel, and topology |
| Custom `StorageBackend` | Implemented extension point; unqualified by default | Qualification belongs to the concrete implementation and deployment profile |

Bucket names can be reused after deletion. The current server therefore never calls the backend's bucket-wide delete from `DeleteBucket`; it removes only recorded object and multipart paths. Custom `StorageBackend` implementations must keep `createBucket()` idempotent and contents-preserving. The name-only `deleteBucket()` method must not be used for automatic cleanup while name reuse is possible. Filesystem and direct/worker-backed Flysystem reject that method with `LogicException`. This changes the public backend contract for callers that invoked it directly; delete only known exact paths. Automatic marker and orphan reclamation requires a separately qualified, generation-scoped implementation.

The S3 AbortMultipartUpload inline path now deletes only the committed part
paths that its metadata transaction recorded as durable garbage jobs. A failed
exact-path delete leaves its job for the collector; an in-flight planned part
keeps its own intent. Direct calls to a backend's legacy
`abortMultipartUpload()` still delete the corresponding part directory and
must not be used as proof of bucket/generation-scoped cleanup in a multi-host
cluster. Those backend calls validate upload IDs: direct and worker-backed
Flysystem and the local filesystem reject path separators or dot-only
segments. Recoverable planned-part writes require a server-generated
32-character hexadecimal ID. Custom backends should apply the same checks
before deriving a physical path from an upload ID.

Custom multipart part writers must return a `StorageWriteResult` with a
nonempty physical path, a size from zero through 5 GiB, and a 32-character
hexadecimal MD5 value. UploadPart and UploadPartCopy validate this result before
publishing part metadata. Valid uppercase MD5 hex is normalized to a lowercase
ETag; an invalid result fails the request, and a nonempty uncommitted result
path is sent through durable exact-path cleanup.

Custom storage backends, decorators, and stream wrappers must propagate
`Amp\CancelledException` unchanged across reads, writes, copies, multipart
assembly, and cleanup. Cleanup should still be attempted after cancellation,
but a cleanup failure must not replace the active cancellation. This lets the
server distinguish a disconnected or stopped request from a backend failure
and preserves orderly worker shutdown.

The optional `RecoverableObjectWriteBackend` can plan a physical object path
from bucket, key, bucket generation, and a unique attempt ID before writing
bytes. Built-in in-memory, filesystem, direct/worker Flysystem, and observed
wrappers implement it. `putObjectAtPath()` validates the planned object's
bucket and key hash; it also preserves the existing multipart completion paths
while binding their bucket and key hash.

For the supported persistent local filesystem profile, ordinary object PUTs,
copies, and background object writes reserve a durable metadata intent before
staging bytes in the private `.tmp/object-writes` directory on the same
filesystem. Publication uses a create-only path, and the intent collector
recovers or removes paths whose request did not finish committing metadata on
its next pass or after restart.

Multipart part uploads and part copies use the same private staging area. They
reserve and arm their part intent only after the complete part and its
checksums have been staged, immediately before create-only publication.
Completion uses an attempt-bound assembly path, and AbortMultipartUpload
stages exact committed part paths for durable cleanup.

Finish or abort active multipart uploads before changing from one local default
storage tier to another. Uploads pinned to a remote tier remain usable while a
local tier is the default; an upload pinned to a different local tier is
rejected instead of weakening the local write fence.

This write fence applies to the documented one-host, one-process profile. The
same capability on a Flysystem adapter or shared filesystem does not establish
a remote or multi-host write fence.

Filesystem reads reject traversal and every symlink component in a stored path,
including links whose target remains inside the configured storage root.
Exact-path deletes apply the same root confinement in the durable filesystem
task. Keep the storage root private to the API process; path validation and
file opening are separate operations and do not protect against an attacker
who can mutate directories concurrently.

Direct and worker-backed Flysystem reject absolute, traversal, and other paths
that Flysystem would normalize before a stored-path read or exact-path delete.
Exact-path deletes also require the named bucket's object namespace or its
multipart assembly namespace. Legacy `.parts/<uploadId>/...` paths remain
deletable because they predate a bucket-scoped part layout. These part paths
do not encode a bucket, and `getObjectByPath()` receives no expected bucket;
the backend therefore cannot independently prove their ownership or a read's
bucket identity. Metadata and the caller must supply the correct path. An
arbitrary custom Flysystem adapter may have further path semantics, including
local symlinks; this validation does not qualify it for remote production.

Bucket arguments used to create or address physical namespaces must be one
safe path segment in both direct and worker-backed Flysystem. This blocks a
programmatic caller from turning `safe/../other` into another bucket's
namespace before an object write, copy, assembly, or bucket operation. The
internal `__health_check__` name remains valid for a storage probe. Planned
multipart assemblies also require their path's bucket and key hash to match
the call. S3 bucket naming remains checked separately at the API boundary.

## Filesystem (Default)

Treat each canonical absolute tier root as part of the persisted storage
layout. A matched backup restore must recreate the same roots; changing roots
is not a supported relocation procedure.

Stores objects as files on the local filesystem for the current one-host,
one-process deployment target.

```bash
S3_STORAGE_DRIVER=filesystem
S3_STORAGE_PATH=/var/data/s3
```

Objects use unique paths sharded by the SHA-256 hash of their key under the
storage root. Writes use synchronized temp files and create-only hard links so
readers cannot observe partial data or replace an already published path.

### Requirements

- The storage path must be writable by the server process
- Sufficient disk space for your expected data volume
- Use a local filesystem that supports hard links plus file and directory
  `fsync` (for example ext4, XFS, or ZFS)

On Linux, the startup write-fence gate inspects the canonical storage path's
mount and admits only known local filesystem types. NFS/EFS,
SMB/CIFS, FUSE, volatile tmpfs/ramfs, unknown types, and unreadable mount
data fail closed outside the explicit unsafe test override. This check cannot
prove persistence of an underlying overlay or qualify a shared mount; do not
use a remote mount for default serving. NFS/EFS multi-host filesystem operation
is a later, currently unqualified profile and does not gate the current local
filesystem release.

Before opening its listener, the server acquires a non-blocking exclusive lock
at `.opsfour/runtime.lock` under every configured local filesystem root. A
second S3 API process using any of those roots fails startup. Keep this regular
file private with the storage root and do not delete, replace, or symlink it.
The lock is retained until orderly shutdown has stopped the server's internal
writers; if shutdown cannot prove that, it remains held until process exit.
It enforces the one-process topology and is not evidence of crash or
power-loss durability.

Before reporting a local publication as successful, the backend synchronizes
the staged file, creates the final path without replacement, synchronizes the
target directory, removes the temporary name, and synchronizes the temporary
directory. Exact-path deletion similarly synchronizes the nearest existing
parent directory before reporting success. These guarantees cover the current
one-process local-filesystem implementation. The exact Linux filesystem,
mount, storage device, and host used by the v2.0.0 Linux/XFS/SQLite reference
profile passed the release crash-recovery gate. Any other deployment profile
needs its applicable gates; process-termination tests do not simulate a sudden
loss of host power.

## Explicit AWS S3 Provider Profile

The `aws-s3` driver is a provider-specific identity and is never routed through
Flysystem. Install the optional SDK and configure the profile with no inline
credentials:

```bash
composer require aws/aws-sdk-php

S3_STORAGE_DRIVER=aws-s3
S3_AWS_BUCKET=private-provider-bucket
S3_AWS_REGION=us-east-1
S3_AWS_EXPECTED_OWNER_ID=123456789012
S3_AWS_DATA_PREFIX=objects
S3_AWS_RESERVED_PREFIX=.opsfour/write-attempts
S3_AWS_CONNECT_TIMEOUT_SECONDS=5
S3_AWS_READ_TIMEOUT_SECONDS=15
S3_AWS_REQUEST_TIMEOUT_SECONDS=20
```

Credentials come only from the AWS SDK default credential provider chain. The
client uses normal TLS endpoint resolution and virtual-host addressing; custom
endpoints, path-style mode, inline credentials, unsupported partitions, and
retry overrides are not profile options. The profile ignores endpoint URLs from
AWS environment/shared configuration, selects the regional `us-east-1`
endpoint, and explicitly disables ARN-region routing, MRAP, accelerate, FIPS,
dual-stack, endpoint-discovery, and S3 Express behavior.

Admission sends only `HeadBucket` and `GetBucketVersioning`, both with
`ExpectedBucketOwner`. It requires the exact configured region, General Purpose
Bucket evidence, and absent/empty versioning status. Enabled, suspended,
unknown, inaccessible, ownership-mismatched, and wrong-region states are denied.
The immutable admission report contains only provider type, configured region,
owner-check result, versioning state, and a stable reason; it never includes the
bucket, account ID, credentials, request material, or SDK exception text.

The package also contains an internal AWS publication primitive. It validates
one exact profile-scoped physical attempt path, commits its metadata reservation
and one-shot dispatch identity before network I/O, and issues a create-only PUT
or multipart completion with `ExpectedBucketOwner` and immutable attempt marker
metadata. Its result is limited to confirmed success with a verified quoted
ETag, exact conditional conflict, confirmed terminal rejection, or ambiguity.
The primitive never deletes, aborts, retries, consumes, or retires the armed intent.

The ordinary PUT and delegated browser POST handlers reserve capacity before
final physical-body staging after authenticated POST spooling, then stage those
bytes in private budgeted scratch.
The scratch budget is private to this capability and does not reuse the public
browser POST spool budget. Long staging renews only the exact undispatched
reservation on a bounded cadence and aborts before provider dispatch if renewal
authority is lost. That same renewable reservation covers SSE body ingestion,
encryption, and encrypted Copy source acquisition and transforms, including an
independent heartbeat while a yielding encryption worker is active. SSE payloads
are staged as ciphertext. Complete staged size and checksum evidence is checked
against the request or authorized Copy source before the sole provider request.
CopyObject transforms a verified source before its one destination attempt;
same-tier AWS copies use an exact owner-bound and attempt-marker-verified
provider read. A confirmed attempt is
consumed only in the same metadata transaction that publishes its logical
object/version and immutable placement. Handler composition requires the exact
admitted backend, provider profile, tier, and metadata-store identity, including
through observability wrappers. Provider ETags are publication evidence,
not logical MD5 values. Exact terminal refusal may retire by CAS; conflict,
ambiguity, post-dispatch cancellation, and failed or uncertain commits retain
recovery evidence. Exact safely-undispatched cancellation may retire its reservation.
Request handlers never delete an AWS attempt path, and scratch-cleanup failure after
metadata commit cannot replace the successful response.

Multipart PUT and UploadPartCopy use one object-write attempt for every immutable
part. Capacity is reserved before request or source ingestion, SSE parts stage
only their authenticated ciphertext, and Copy sources on the same AWS tier use
the same expected-owner and marker verification as ordinary CopyObject, including
bounded ranged reads. Part metadata and the exact armed dispatch are consumed in
one transaction; replacement queues the previous exact path and never performs
an inline AWS delete.

Completion retains the existing upload-level intent as the selected-part fence
and adds one exact final-object attempt. Plain completion preflights the immutable
parts, then streams them sequentially into the final provider attempt without
whole-object scratch or in-memory assembly. SSE-S3 and SSE-C completion authenticate
each part within `maxEncryptedObjectSize`, re-encrypt once, and stage only final
ciphertext. The upload intent, final provider dispatch, object/version metadata,
and immutable placement commit atomically. Abort and post-completion part cleanup
only persist exact garbage paths; generic collectors and request handlers do not
mutate qualified AWS keys.

Lifecycle transitions and temporary restore copies may use one exact admitted
AWS tier binding. They reserve capacity before opening any source stream, copy
stored ciphertext without transformation, and consume the confirmed dispatch in
the same transaction as queue completion and placement or restore metadata. A
stale claim, changed bucket generation, ambiguous provider result, or uncertain
commit acknowledgement never authorizes AWS deletion. A later logical retry
allocates a fresh immutable attempt while prior armed evidence remains available
to reconciliation. AWS sources are read only through exact current-v2 path,
owner-digest, size, expected-owner, and bounded-EOF verification. Multiple
admitted AWS tier bindings fail closed; only a default-tier binding is exposed
to foreground request handlers.

Generic `StorageBackend` AWS operations, provider-side copy, garbage deletion,
and remaining internal writers remain unavailable. API startup refuses the profile even when the
unsafe Flysystem test override is enabled.

Every AWS reservation charges fixed open-attempt and permanent-seal capacity
before body ingestion: 4,096 of each per owner and 1,000,000 of each globally.
Confirmed publication or rejection releases both charges. Ambiguity retains
both until a tier-bound collector proves the exact v2 attempt or a conclusive
absence, installs or adopts the deterministic same-key seal, verifies its
metadata, ETag, exact bounded body and EOF with `ExpectedBucketOwner`, and wins
the current SQL claim generation. A verified seal releases open capacity once;
seal capacity remains charged permanently. Generic 404/409/412 responses,
timeouts, malformed bodies, and foreign markers never retire uncertainty.
This release exposes no seal deletion or break-glass removal operation.

Later qualification stories must connect access logs, garbage cleanup, and every remaining internal writer and
complete cleanup, health, and release gates before this profile
can be described as fence-complete or production-qualified.

## Flysystem

Stores objects via the [League\Flysystem](https://flysystem.thephpleague.com/)
abstraction. The integration accepts adapters for S3, GCS, Azure Blob, SFTP,
and other services, but adapter compatibility does not grant production status.

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
S3_FLYSYSTEM_FIRST_BYTE_TIMEOUT_SECONDS=20
S3_BACKING_BUCKET=backing-bucket
S3_BACKING_REGION=eu-central-1
S3_BACKING_ACCESS_KEY=change-me
S3_BACKING_SECRET_KEY=change-me
S3_BACKING_ENDPOINT=https://eu-central-1.linodeobjects.com
S3_BACKING_PATH_STYLE=true
S3_BACKING_PREFIX=production
S3_BACKING_CONNECT_TIMEOUT_SECONDS=5
S3_BACKING_READ_TIMEOUT_SECONDS=15
S3_BACKING_REQUEST_TIMEOUT_SECONDS=0
```

Flysystem remains available for development and integration testing. A remote
provider can move beyond this status only after a provider-side write fence
proves that an abandoned multipart path cannot reappear after cleanup. A
generic Flysystem adapter does not provide that guarantee; its current
retained-intent cap can eventually stop an owner's new completions.
API startup now refuses every configured direct or worker-backed Flysystem tier,
including tiers wrapped for metrics, and unknown custom storage backends. The
built-in filesystem backend starts normally on recognized persistent local
Linux mounts (such as ext4, XFS, Btrfs, and overlay) and on local macOS paths.
On Linux, startup resolves the storage root against `/proc/self/mountinfo` and
refuses NFS/EFS, SMB/CIFS, FUSE, unknown filesystems, or unreadable mount
information. Volatile tmpfs/ramfs is admitted only with the built-in SQLite
metadata store using `:memory:` or an existing SQLite file on the same
filesystem device as the storage root. The explicit unsafe test override can
admit otherwise rejected mounts for isolated integration tests; it is not
a production approval. Bind mounts
are checked at their most specific mount point. An overlay backed by remote
storage may not be identifiable from its mount type, so deployment still needs
the real two-host fault gate. In-memory object storage
is admitted only with an actual `:memory:` SQLite metadata store. A persistent
SQLite file, a parallel SQLite store, PostgreSQL, or MySQL would retain metadata
after the object bytes disappear on restart, so API startup refuses that pairing.
For controlled integration and fault tests only, set
`S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS=true` (or the matching server config
setting). This disables the storage safety gate and is unsafe for production;
tests using it do not qualify a remote provider for release.
Passing a soak alone does not establish this write fence.

Standalone Flysystem mode rejects `S3_FLYSYSTEM_WORKERS=0` because synchronous
remote calls would block the Amp event loop. It never creates
`S3_STORAGE_PATH`; only `S3_STORAGE_TEMP_DIR` and an optional local SQLite
metadata path are created locally.

The first-byte deadline covers read-worker admission and the first remote
chunk. On expiry, the API responds with 503 SlowDown and cancels the read task.
The admission queue allows at most one waiting GET per read worker and rejects
further GETs immediately with 503. A worker slot remains occupied until its
task actually ends, even after the client request has timed out.
Write, delete, copy, and multipart assembly tasks use a separate bounded
worker pool. It admits at most one waiting task per worker for 20 seconds;
excess or expired waiters receive 503 before submitting a task. Running writes
are allowed to finish so their result can be reconciled with metadata.
The built-in backing S3 client also bounds connection setup and individual
streamed reads. Its total request timeout defaults to zero so large uploads
and multipart assembly can take longer than 15 seconds; set a positive value
only if the corresponding maximum transfer duration is acceptable.
A custom synchronous Flysystem adapter may retain a read worker until its
blocking call returns; configure its own I/O deadlines before production use.

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
loop for the duration of each adapter call. Remote integration and fault tests
use bounded worker mode:

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
Custom backing endpoints must be valid HTTPS URIs. Plain HTTP is rejected in
the factory constructor before an AWS client or network connection is created.
Isolated local fault tests may opt in with
`allowInsecureHttpForTests: true`; this switch is unsafe and test-only. The
standalone equivalent is `S3_BACKING_ALLOW_INSECURE_HTTP_FOR_TESTS=true` or
`--backing-allow-insecure-http-for-tests=true`.

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

Same-tier `CopyObject` uses a bounded source read in the built-in backends.
For a remote Flysystem adapter, the copy streams through a worker instead of
using the adapter's server-side `copy()` operation. This caps bytes staged for
the destination at the recorded object size plus one detection byte, with extra
remote transfer cost and latency. PHP streams and remote adapters may prefetch
more bytes internally than the requested read size; verify their peak memory
and network behavior in the deployment gate. A custom `StorageBackend` without
the optional bounded-copy capability uses a bounded staging stream; its
implementation must also bound the size of each `getObjectByPath()` chunk to
avoid a transient memory spike.

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

Use `filesystem` for the current one-host, one-process release target. The
v2.0.0 Linux/XFS/SQLite reference profile completed its release gates; run the
applicable gates for any other metadata, filesystem, mount, device, and host
profile before deployment. PostgreSQL and MySQL can share metadata, but that
does not change the storage status or qualify multiple hosts. Flysystem worker
mode bounds event-loop blocking during experiments; it does not qualify an
adapter or provider. Direct `FilesystemOperator` mode can stall unrelated
requests in the Amp process.

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
Restore requests support the `Standard` retrieval tier for 1 through 365,000
days. Repeating a restore while its hot copy is valid extends that copy's
expiry without creating another job.
While a restore job is pending, another request returns
`RestoreAlreadyInProgress`. A terminally failed restore does not expose an
`x-amz-restore` header claiming that work is still running.

The tier name is a physical backend identity and is limited to 64 bytes. Two
tier names must not point at the same physical namespace: transition cleanup
deletes the source path after publishing the target. Startup rejects overlapping
filesystem roots, including relative aliases and paths whose last components
do not exist yet. It also rejects overlapping roots from built-in local
Flysystem factories and overlapping bucket/prefix pairs from built-in AWS S3
Flysystem factories at the same endpoint. Credentials and timeouts do not
change that physical identity. When two local roots differ only in path case,
startup creates and removes an empty case probe in each deepest existing
ancestor before deciding whether they overlap. If it cannot create or remove
the probe, startup rejects the ambiguous configuration. DNS/CNAME aliases and an
implicit AWS endpoint versus an explicit URL cannot be resolved as the same
remote namespace by startup. For arbitrary Flysystem operators and custom
factories, startup detects reuse of the same object instance; configure their
physical namespaces separately because their internal roots are not generally
available to the package.
Object writes, multipart uploads, and restored copies persist the exact tier
that received their bytes. The API process and its workers must configure every
tier referenced by objects, open uploads, restore copies, write intents,
lifecycle rules, or cleanup jobs. Startup refuses an unknown tier. A later
multi-process profile must enforce the same invariant in every participant.
Schema upgrades leave older open multipart uploads and restored copies
with no verifiable physical tier unresolved; stop old writers and map each
remaining path from the actual backend or drain it before starting the new
API. Changing the configured default cannot safely backfill that history.
