# Authentication

OpsFour S3 Server implements full AWS Signature Version 4 authentication, including presigned URLs and chunked streaming signatures.

## How Authentication Works

Every request is verified by the `AuthMiddleware`, which supports three authentication modes:

1. **Header-based SigV4** — Standard `Authorization: AWS4-HMAC-SHA256 ...` header
2. **Presigned URLs** — Time-limited URLs with `X-Amz-Algorithm` query parameter
3. **Chunked streaming** — `STREAMING-AWS4-HMAC-SHA256-PAYLOAD` for large uploads

On success, the middleware sets request attributes: `credential`, `ownerId`, and `signedHeaders`.

## Credential Providers

### Memory (Single User)

Simplest setup. One access key, one owner.

```bash
S3_CREDENTIALS_DRIVER=memory
S3_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE
S3_SECRET_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
S3_OWNER_ID=owner-1
S3_DISPLAY_NAME=Admin
```

### File (Multi-User)

Store credentials in a JSON file. Supports multiple users with active/inactive status.

```bash
S3_CREDENTIALS_DRIVER=file
S3_CREDENTIALS_PATH=/etc/s3-server/credentials.json
```

```json
[
  {
    "accessKeyId": "AKIAIOSFODNN7EXAMPLE",
    "secretAccessKey": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
    "ownerId": "tenant-alpha",
    "displayName": "Alpha Corp",
    "isActive": true
  },
  {
    "accessKeyId": "AKIAI44QH8DHBEXAMPLE",
    "secretAccessKey": "je7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY",
    "ownerId": "tenant-beta",
    "displayName": "Beta Inc",
    "isActive": true
  }
]
```

### Database (Dynamic Multi-User)

Store credentials in PostgreSQL or MySQL for dynamic management.

```bash
S3_CREDENTIALS_DRIVER=database
S3_CREDENTIALS_DSN="host=localhost port=5432 dbname=s3server user=s3 password=secret"
```

The credentials table is auto-created. Manage credentials by inserting/updating rows directly in the database.

## External IAM / OIDC Credential Issuer

The S3 data plane stays AWS-compatible: SDK clients still authenticate with
SigV4 access key and secret pairs. For Keycloak or another OIDC provider, the
server can expose a small admin API that validates an external RS256 JWT and
issues scoped SigV4 credentials in the configured credential provider.

Enable it with a protected admin token and OIDC verification keys:

```bash
S3_EXTERNAL_IAM_ENABLED=true
S3_EXTERNAL_IAM_ADMIN_TOKEN=change-this-admin-token
S3_EXTERNAL_IAM_ISSUER=https://keycloak.example.test/realms/storage
S3_EXTERNAL_IAM_AUDIENCE=s3-admin
S3_EXTERNAL_IAM_JWKS_PATH=/etc/s3-server/keycloak-jwks.json
S3_EXTERNAL_IAM_OWNER_CLAIM=tenant_id
S3_EXTERNAL_IAM_POLICY_NAMES_CLAIM=s3_policies
S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM=s3_prefixes
S3_EXTERNAL_IAM_OWNER_PREFIX=tenant:
```

Supported key sources are:

- `S3_EXTERNAL_IAM_JWKS_PATH` for a local JWKS file with RSA keys
- `S3_EXTERNAL_IAM_PUBLIC_KEY_PATH` for a PEM public key file
- `S3_EXTERNAL_IAM_PUBLIC_KEY` for an inline PEM public key

Issue credentials:

```bash
curl -X POST http://127.0.0.1:9000/.admin/credentials \
  -H "Authorization: Bearer ${S3_EXTERNAL_IAM_ADMIN_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"token":"OIDC_ACCESS_TOKEN","ttlSeconds":3600}'
```

Response:

```json
{
  "accessKeyId": "AKIA...",
  "secretAccessKey": "...",
  "sessionToken": "...",
  "ownerId": "tenant:acme",
  "displayName": "alice",
  "expiresAt": "2026-06-14T12:00:00+00:00",
  "policyNames": ["uploads"],
  "allowedPrefixes": ["incoming/"]
}
```

Revoke credentials without restarting the server:

```bash
curl -X DELETE http://127.0.0.1:9000/.admin/credentials/AKIA... \
  -H "Authorization: Bearer ${S3_EXTERNAL_IAM_ADMIN_TOKEN}"
```

When `ttlSeconds` is present, the returned credentials include `sessionToken`
and `expiresAt`. SDK clients must pass all three credential fields. Header-based
SigV4, presigned URLs, and POST object uploads reject missing, invalid, or
expired session tokens.

## Runtime Quota Admin API

Set `S3_ADMIN_API_TOKEN` to enable protected runtime quota management without a
server restart. If `S3_ADMIN_API_TOKEN` is not set, the server can reuse
`S3_EXTERNAL_IAM_ADMIN_TOKEN`.

```bash
S3_ADMIN_API_TOKEN=change-this-admin-token
```

Manage per-account quota overrides:

```bash
curl -X PUT http://127.0.0.1:9000/.admin/quotas/tenant%3Aacme \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "maxBucketsPerOwner": 10,
    "maxObjectsPerBucket": 100000,
    "maxBytesPerBucket": 107374182400,
    "maxBytesPerOwner": 1099511627776
  }'
```

List, show, and delete overrides:

```bash
curl http://127.0.0.1:9000/.admin/quotas \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"

curl http://127.0.0.1:9000/.admin/quotas/tenant%3Aacme \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"

curl -X DELETE http://127.0.0.1:9000/.admin/quotas/tenant%3Aacme \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"
```

### Chain (Multiple Providers)

Try multiple providers in order. Useful for combining a local admin credential with a database of user credentials.

```php
use OpsFour\S3Server\Factory\CredentialProviderFactory;

$provider = CredentialProviderFactory::create('chain', [
    'providers' => [
        ['driver' => 'memory', 'access_key' => 'admin', 'secret_key' => '...', 'owner_id' => 'admin', 'display_name' => 'Admin'],
        ['driver' => 'database', 'dsn' => 'host=localhost dbname=s3server ...'],
    ],
]);
```

## Multi-Tenancy

Every credential has an `ownerId`. All S3 operations are scoped to the authenticated owner:

- `ListBuckets` returns only buckets owned by the authenticated user
- `CreateBucket` assigns ownership to the authenticated user
- `DeleteBucket` verifies ownership before deletion
- Object operations verify the bucket belongs to the requester

This provides complete tenant isolation without any extra configuration.

## Presigned URLs

Clients generate presigned URLs using their secret key. The server validates them on request:

```php
// Generate a presigned URL (client-side, using AWS SDK)
$cmd = $s3->getCommand('GetObject', [
    'Bucket' => 'my-bucket',
    'Key'    => 'private/document.pdf',
]);

$presignedUrl = (string) $s3->createPresignedRequest($cmd, '+15 minutes')->getUri();
// Share this URL — it works without authentication for 15 minutes
```

The server validates:
- Signature correctness
- Expiration time
- `host` header is in `SignedHeaders` (prevents cross-bucket reuse)

## Unauthenticated Mode

If no auth middleware is registered, the server runs unauthenticated (logs a warning). All operations use a default owner ID. Useful only for local development.

## Custom Authentication

Add your own middleware for custom auth flows:

```php
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

class MyAuthMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $handler): Response
    {
        // Your auth logic here
        $request->setAttribute('ownerId', 'verified-owner-id');
        return $handler->handleRequest($request);
    }
}

$server->addMiddleware(new MyAuthMiddleware());
```

## Managing Credentials

Use the CLI to create, list, inspect, toggle, and delete credentials.

### Standalone CLI

```bash
# Create a new credential (access key and secret auto-generated)
php vendor/bin/s3-server credentials create \
  --credentials-driver=file \
  --credentials-file=/etc/s3-server/credentials.json \
  --owner-id=tenant-alpha \
  --display-name="Alpha Corp"

# Output:
#   Access Key ID:     AKIA5F8B2C1D3E4A6789
#   Secret Access Key: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
#   Owner ID:          tenant-alpha
#   Display Name:      Alpha Corp
#   Active:            Yes
#   WARNING: Store the secret access key securely. It cannot be retrieved again.

# List all credentials
php vendor/bin/s3-server credentials list \
  --credentials-driver=file \
  --credentials-file=/etc/s3-server/credentials.json

# Show credential details (secret key is masked)
php vendor/bin/s3-server credentials show AKIA5F8B2C1D3E4A6789 \
  --credentials-driver=file \
  --credentials-file=/etc/s3-server/credentials.json

# Deactivate a credential (blocks authentication without deleting)
php vendor/bin/s3-server credentials deactivate AKIA5F8B2C1D3E4A6789 \
  --credentials-driver=file \
  --credentials-file=/etc/s3-server/credentials.json

# Re-activate
php vendor/bin/s3-server credentials activate AKIA5F8B2C1D3E4A6789 \
  --credentials-driver=file \
  --credentials-file=/etc/s3-server/credentials.json

# Delete permanently
php vendor/bin/s3-server credentials delete AKIA5F8B2C1D3E4A6789 \
  --credentials-driver=file \
  --credentials-file=/etc/s3-server/credentials.json
```

With the database driver:

```bash
php vendor/bin/s3-server credentials create \
  --credentials-driver=database \
  --credentials-dsn="pgsql:host=localhost;dbname=s3server;user=s3;password=secret" \
  --owner-id=tenant-beta \
  --display-name="Beta Inc"
```

### Laravel Artisan

In Laravel, the command uses the credential provider configured in `config/s3-server.php`:

```bash
# Create
php artisan s3:credentials create --owner-id=tenant-alpha --display-name="Alpha Corp"

# List
php artisan s3:credentials list

# Show
php artisan s3:credentials show AKIA5F8B2C1D3E4A6789

# Activate / Deactivate
php artisan s3:credentials activate AKIA5F8B2C1D3E4A6789
php artisan s3:credentials deactivate AKIA5F8B2C1D3E4A6789

# Delete
php artisan s3:credentials delete AKIA5F8B2C1D3E4A6789
```

### Custom Access Keys

To specify your own access key and secret instead of auto-generating:

```bash
php vendor/bin/s3-server credentials create \
  --credentials-driver=file \
  --credentials-file=./credentials.json \
  --owner-id=admin \
  --display-name="Admin" \
  --access-key=AKIAIOSFODNN7EXAMPLE \
  --secret-key=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

### Multi-Tenant Workflow

Each credential has an `ownerId` that scopes all S3 operations. Create one credential per tenant:

```bash
# Tenant A
php artisan s3:credentials create --owner-id=tenant-a --display-name="Company A"
# → AKIA... / secret...

# Tenant B
php artisan s3:credentials create --owner-id=tenant-b --display-name="Company B"
# → AKIA... / secret...
```

Tenant A can only see and manage buckets/objects created by Tenant A. Tenant B is completely isolated. Multiple credentials can share the same `ownerId` (e.g., different API keys for the same team).
