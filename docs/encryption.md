# Encryption

OpsFour S3 Server supports server-side encryption with two modes:

- **SSE-S3** — Server-managed keys with key rotation support
- **SSE-C** — Customer-provided keys (per-request)

Both modes use AES-256-GCM with authenticated encryption.

Direct SSE PUT and CopyObject requests now encrypt in bounded process memory
before the first write to the destination object-storage backend. They retain
plaintext ETag and checksum semantics. The limit below also bounds how much
plaintext those handlers read. New SSE multipart uploads now use the
`encrypted-v2` part format: part writes persist ciphertext, and completion
authenticates parts in bounded process memory before writing only final
ciphertext. Historical SSE uploads without this marker are rejected by
UploadPart, UploadPartCopy, and CompleteMultipartUpload; abort remains available
so their plaintext staging can be removed during cutover.

Encrypted multipart completion checks the configured object-size limit before
any physical final write. A legacy SSE upload cannot complete on this code
version. Drain or abort those uploads and verify physical cleanup before a
strict at-rest deployment. The operational requirements are described below.

The direct encryption path holds the source, plaintext and ciphertext in PHP
memory during processing. The server checks available `memory_limit` headroom
before growing its payload buffer and rejects an object that will not fit. With
the default 256 MiB `S3_MAX_ENCRYPTED_OBJECT_SIZE`, configure at least 1.5 GiB
PHP `memory_limit` per API process, or lower the object limit for smaller
processes. The buffered-work slot permits one such operation per process.

Browser POST uploads and request-body Content-MD5 or literal SigV4 SHA-256
validation now stage authenticated ciphertext frames in local temporary files.
The per-file encryption key remains only in the API process; crash recovery
deletes abandoned ciphertext instead of resuming it. The normal and error paths
remove those private files, and the shared budget counts physical ciphertext
bytes. Operators must still apply the host and proxy controls described below.

Unlike current Amazon S3, this package does not encrypt new objects by default.
Without an encryption request or bucket encryption configuration, object data is
stored without the package's server-side encryption. Configure a master key and
enable bucket default encryption before storing objects that require SSE-S3.
`GetBucketEncryption` returns a configuration-not-found error when no bucket
default has been configured.

## SSE-S3 (Server-Managed Keys)

### Setup

Generate a 32-byte master key:

```bash
# Generate a random 256-bit key
openssl rand -base64 32
# Example output: K7gNU3sdo+OL0wNhqoVWhr3g6s1xYv72ol/pe/Unols=
```

Set the environment variable:

```bash
S3_ENCRYPTION_MASTER_KEY=K7gNU3sdo+OL0wNhqoVWhr3g6s1xYv72ol/pe/Unols=
```

### How It Works

1. For each encrypted object, a unique 32-byte data key is generated
2. The object data is encrypted with the data key using AES-256-GCM
3. The data key is encrypted with the master key (envelope encryption)
4. The encrypted data key is stored in object metadata
5. On read, the data key is decrypted with the master key, then used to decrypt the object

### Using SSE-S3

```php
// Upload with SSE-S3
$s3->putObject([
    'Bucket'               => 'my-bucket',
    'Key'                  => 'secret.txt',
    'Body'                 => 'Classified data',
    'ServerSideEncryption' => 'AES256',
]);

// Download (decryption is automatic)
$result = $s3->getObject([
    'Bucket' => 'my-bucket',
    'Key'    => 'secret.txt',
]);
echo $result['Body']; // "Classified data"
```

Or enable default encryption on the bucket:

```php
$s3->putBucketEncryption([
    'Bucket' => 'my-bucket',
    'ServerSideEncryptionConfiguration' => [
        'Rules' => [[
            'ApplyServerSideEncryptionByDefault' => [
                'SSEAlgorithm' => 'AES256',
            ],
        ]],
    ],
]);
```

## Key Rotation

For enterprise archival use cases, master keys can be rotated without re-encrypting existing objects.

### Step 1: Prepare the New Key

```bash
# Generate new key
openssl rand -base64 32
# Example: NewKeyBase64EncodedHere...
```

### Step 2: Switch to Multi-Key Format

Replace `S3_ENCRYPTION_MASTER_KEY` with `S3_ENCRYPTION_MASTER_KEYS`:

```bash
# First entry = active key for new writes
# Subsequent entries = historical keys for reading old objects
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"NewKeyBase64...","key-2024":"OldKeyBase64..."}'
```

### Step 3: Restart the Server

New objects are encrypted with `key-2025`. Old objects encrypted with `key-2024` are still readable because the old key is in the JSON map.

### How Key Rotation Works Internally

- New objects use a V1 encrypted data key blob that embeds the key ID
- On decrypt, the server reads the key ID from the blob and resolves the correct master key
- Legacy blobs have no key ID. The server tries the active key first, then the
  historical `default` key if the active key cannot decrypt the data key.
- When migrating from single-key to multi-key, set the old key's ID to `"default"`:

```bash
# Migration from single-key to multi-key
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"NewKeyBase64...","default":"OldSingleKeyBase64..."}'
```

Keep the old `default` key configured until all legacy objects have been
removed or rewritten. Removing it makes those objects unreadable.

### Key Providers

| Provider | Variable | Use Case |
|----------|----------|----------|
| Config | `S3_ENCRYPTION_MASTER_KEYS` | Simple deployments, env-based rotation |
| Redis | `S3_REDIS_MASTER_KEY_DSN` | Centralized key management with Redis |
| Vault | `S3_VAULT_ADDR` | Enterprise key management with HashiCorp Vault |

#### Redis Provider

```bash
S3_MASTER_KEY_PROVIDER=redis
S3_REDIS_MASTER_KEY_DSN=redis://redis.internal:6379
```

Multi-key: store keys as a Redis hash at `s3:master-keys` (key ID → base64 key). Set `s3:active-key-id` to the active key ID.

#### Vault Provider

```bash
S3_MASTER_KEY_PROVIDER=vault
S3_VAULT_ADDR=https://vault.internal:8200
S3_VAULT_TOKEN=s.xxxxxxxxxxxxxxxx
S3_VAULT_PATH=secret/data/s3-server/master-key
```

Multi-key: store a `keys` field in the Vault secret containing a JSON map
`{"keyId": "base64Key", ...}` and set `activeKeyId` to one of those key IDs.
Redis and Vault multi-key configurations fail closed when the active ID is
missing or unknown; key selection never depends on map/hash iteration order.
Vault responses are limited to 1 MiB and non-success response bodies are not
included in exceptions. The built-in Vault HTTP client does not follow
redirects, so `X-Vault-Token` is sent only to the configured HTTPS endpoint.
The address may include a base path but cannot contain URL credentials, a query,
or a fragment. The secret path cannot contain a query or fragment. Provider
errors omit the configured path and raw client diagnostics.

### Symfony Key Services

Symfony applications can keep the built-in encryption service and provide a
custom `MasterKeyProvider` service:

```yaml
services:
  App\S3\Encryption\SymfonyVaultMasterKeyProvider: ~

opsfour_s3_server:
  encryption:
    master_key_provider_service: 'App\S3\Encryption\SymfonyVaultMasterKeyProvider'
```

They can also replace the complete encryption service with
`encryption.service`. See [Symfony Integration](symfony-integration.md#encryption-services).

## SSE-C (Customer-Provided Keys)

The client provides the encryption key with each request. The server never stores the key.

```php
$customerKey = random_bytes(32);

// Upload with SSE-C
$s3->putObject([
    'Bucket'               => 'my-bucket',
    'Key'                  => 'client-encrypted.dat',
    'Body'                 => 'Secret data',
    'SSECustomerAlgorithm' => 'AES256',
    'SSECustomerKey'       => base64_encode($customerKey),
    'SSECustomerKeyMD5'    => base64_encode(md5($customerKey, true)),
]);

// Download (must provide the same key)
$result = $s3->getObject([
    'Bucket'               => 'my-bucket',
    'Key'                  => 'client-encrypted.dat',
    'SSECustomerAlgorithm' => 'AES256',
    'SSECustomerKey'       => base64_encode($customerKey),
    'SSECustomerKeyMD5'    => base64_encode(md5($customerKey, true)),
]);
```

SSE-C objects cannot be served via website hosting (no customer key available).

## Parallel Encryption

For high-throughput workloads, offload encryption to worker processes:

```bash
S3_ENCRYPTION_WORKERS=4         # Number of worker processes
S3_ENCRYPTION_THRESHOLD=65536   # Objects larger than 64 KiB use workers
```

SSE-S3 worker processes inherit the `config` provider's complete master-key
ring from the parent environment. SSE-S3 offload requires an exact keyring
match; with Redis, Vault, custom, or mismatched explicit Config providers,
SSE-S3 uses the injected provider in the API process. SSE-S3 master keys are
not serialized into worker tasks. Size the API memory limit and encrypted-object
limit together for those inline SSE-S3 operations. Large SSE-C requests still
offload encryption for every provider type: their customer key is serialized
into local worker IPC in memory. It must never be logged or written to a spool.
Disable or encrypt swap, disable core dumps, and prevent diagnostic capture of
worker payloads. Size process memory above the configured encrypted-object
limit with enough headroom for plaintext and ciphertext buffers.

## Size Limits

```bash
S3_MAX_ENCRYPTED_OBJECT_SIZE=268435456   # 256 MiB (default)
```

Objects larger than this limit cannot use the built-in SSE-S3/SSE-C
implementation. Multipart upload does not bypass the encryption limit.

## Multipart Encryption and Upgrades

New SSE multipart uploads use the `encrypted-v2` part representation. Each part
is encrypted before it reaches durable part storage, and completion authenticates
and decrypts the selected parts in bounded process memory before publishing only
the final ciphertext. Plaintext sizes, ETags, quota accounting, and supported
request checksums retain their normal S3 meanings.

Multipart uploads created by an older package version do not carry this format
marker. Legacy SSE-S3 and SSE-C uploads cannot accept new parts or complete on
this version; abort them before a coordinated upgrade. Stop old API writers,
drain in-flight writes, deploy every serving node together, and verify that
legacy part paths and queued cleanup have been removed before claiming a strict
at-rest deployment. Do not delete multipart or write-intent metadata directly:
it can be required to clean up a delayed storage write safely.

Changing a bucket from unencrypted writes to default SSE-S3 also requires a
drain. Requests that began before the change can have staged bytes even when
their later metadata commit is rejected. Inventory open uploads and temporary
storage on every API and worker host before enabling a no-plaintext-at-rest
claim.

## At-Rest Operational Boundary

The package encrypts Browser POST and request-checksum spools as authenticated
frames before writing them to temporary storage. Encrypted multipart parts and
the final object are also written as ciphertext in the new format. The keys for
request spools and SSE-C remain in process memory.

A strict at-rest deployment must therefore disable or encrypt swap, disable core
dumps, keep temporary directories private, and prevent diagnostics from
capturing request or worker memory. Apply the same rules to TLS terminators and
load balancers, and ensure their logs omit presigned query strings and SSE-C
headers. Validate these controls with the actual filesystem, storage adapter,
proxy, and crash behavior used in production.
