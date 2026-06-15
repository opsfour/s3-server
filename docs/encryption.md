# Encryption

OpsFour S3 Server supports server-side encryption with two modes:

- **SSE-S3** — Server-managed keys with key rotation support
- **SSE-C** — Customer-provided keys (per-request)

Both modes use AES-256-GCM with authenticated encryption.

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
- Legacy objects (encrypted before rotation) use the active key for decryption
- When migrating from single-key to multi-key, set the old key's ID to `"default"`:

```bash
# Migration from single-key to multi-key
S3_ENCRYPTION_MASTER_KEYS='{"key-2025":"NewKeyBase64...","default":"OldSingleKeyBase64..."}'
```

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

Multi-key: store a `keys` field in the Vault secret containing a JSON map `{"keyId": "base64Key", ...}`.

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

Worker processes inherit the master key from the parent process's environment variables. No key material crosses IPC boundaries.

## Size Limits

```bash
S3_MAX_ENCRYPTED_OBJECT_SIZE=268435456   # 256 MiB (default)
```

Objects larger than this limit cannot use SSE-S3 encryption (they must be uploaded as multipart).
