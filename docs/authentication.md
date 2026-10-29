# Authentication

opsfour S3 Server implements the documented AWS Signature Version 4 request forms, including presigned URLs and chunked streaming signatures.

## How Authentication Works

Every request is verified by the `AuthMiddleware`, which supports three authentication modes:

1. **Header-based SigV4** — Standard `Authorization: AWS4-HMAC-SHA256 ...` header
2. **Presigned URLs** — Time-limited URLs with `X-Amz-Algorithm` query parameter
3. **Chunked streaming** — `STREAMING-AWS4-HMAC-SHA256-PAYLOAD` for large uploads

On success, the middleware sets request attributes: `credential`, `ownerId`, and
`signedHeaders`. It also carries a typed, verified authentication classification
into policy evaluation: `REST-HEADER` for header SigV4 (including aws-chunked),
`REST-QUERY-STRING` for SigV4 presigned URLs, and `POST` for authenticated SigV2
or SigV4 browser forms. Anonymous, invalid, website, and internal requests do
not receive a classification. The server never derives this policy value from
headers, query parameters, methods, form fields, or raw string attributes.

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
The file is loaded when the process starts. Its writer mutex protects only one
process. This matches the current deployment profile; do not use a shared
credential file to evaluate concurrent API processes or expect another running
process to observe edits. A later multi-process profile requires the database
provider and its own qualification.
The package requires `ext-posix` so the file provider can verify the effective
user, the credential file, and every ancestor of its canonical directory.
The directory must be private or root-owned and sticky (such as `/tmp`);
secrets are written in a private temporary directory before an atomic move.
Directory symlinks are accepted only when the link is owned by root or the
effective user and both its lexical and resolved ancestors pass the trust checks.
An existing file must be a non-empty regular file owned by root or the effective
user, readable by its owner, inaccessible to group and other users, no larger
than 16 MiB, and unchanged between path inspection and reading. Unsafe files and
symbolic links make startup fail closed; a genuinely absent target remains a
valid empty initial state. Writes that would exceed the same 16 MiB limit are
rejected before a temporary file is created, leaving both the current file and
the provider's in-memory snapshot unchanged.
Each JSON entry is validated strictly at startup. Unknown fields and fields with
the wrong JSON type make startup fail instead of falling back to credential
defaults. Owner IDs and display names must be valid UTF-8 containing only XML
1.0 characters because S3 returns them in XML responses.

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
S3_CREDENTIALS_DSN="pgsql:host=localhost;port=5432;dbname=s3server"
S3_CREDENTIALS_USERNAME=s3
S3_CREDENTIALS_PASSWORD=secret
S3_CREDENTIALS_CACHE_TTL=1
```

Before starting the API process or running credential management, execute
`php bin/s3-server credentials:migrate` once with a DDL-capable credential
database role. Set `S3_CREDENTIALS_DSN`, `S3_CREDENTIALS_USERNAME`, and
`S3_CREDENTIALS_PASSWORD` in that bootstrap environment; the migrator preserves
or creates one stable, non-secret credential-store identity and prints it while
preserving existing credentials. In
cluster mode the identity participates in admission, so every process must use
the same PostgreSQL/MySQL credential database. Runtime credential changes do
not replace that identity. The identity row is read-only to serving roles.
Serving processes verify the table, required columns,
the `(expires_at, access_key_id)` expiration index, and a single byte-exact
primary key on `access_key_id` without changing the schema. MySQL requires a
full-length `VARBINARY(255)` primary key; SQLite
requires its `BINARY` collation, and PostgreSQL requires a deterministic
text/varchar primary-key collation. The explicit migrator also rejects an
existing table that cannot meet these checks instead of reporting a successful
partial migration. The explicit migrator replaces a package-named expiration
index when its columns, access method, visibility, or predicate are invalid;
serving processes never perform that DDL. Use a DML-only role for serving after
the bootstrap. Existing PostgreSQL and MySQL serving roles also need read access
to the identity singleton created by the migration. Grant and verify that access
before restarting API nodes, replacing the example role/database identifiers as
appropriate:

```sql
-- PostgreSQL
GRANT SELECT ON TABLE s3_credential_store_identity TO s3_runtime;
SELECT credential_store_identity
FROM s3_credential_store_identity WHERE singleton_id = 1;

-- MySQL
GRANT SELECT ON s3server.s3_credential_store_identity TO 's3_runtime'@'%';
SELECT credential_store_identity
FROM s3server.s3_credential_store_identity WHERE singleton_id = 1;
```

Run the verification query as the serving role; it must return exactly one
32-character identity. The
DSN uses PDO syntax; credentials are passed separately so the
same configuration works for PostgreSQL and MySQL. Positive lookups are cached
for one second by default. Set
`S3_CREDENTIALS_CACHE_TTL=0` when the running process must observe revocation on
the next request.

## External IAM / OIDC Credential Issuer

The S3 data plane stays AWS-compatible: SDK clients still authenticate with
SigV4 access key and secret pairs. For Keycloak or another OIDC provider, the
server can expose a small admin API that validates an external RS256 JWT and
issues scoped SigV4 credentials in the configured credential provider.
Both the expected issuer and audience are required, including when embedding
`JwtExternalIdentityProvider` directly. Tokens signed by the same identity
provider for another client must not issue S3 credentials.
The JWT `aud` claim must be either one string or a JSON array containing only
strings. Configured groups, policy-name, and allowed-prefix claims must be JSON
arrays containing only non-empty strings when present; JSON objects, scalar
strings, and `null` are rejected.

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

When `S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM` is configured, each accepted JWT
must contain at least one non-empty prefix in that claim. Missing, `null`, and
empty values are rejected before credentials are issued. Omitting the setting
keeps the documented unscoped credential behavior. Existing credentials issued
before this validation change must be reviewed or revoked separately.

Supported key sources are:

- `S3_EXTERNAL_IAM_JWKS_PATH` for a local JWKS file with RSA keys
- `S3_EXTERNAL_IAM_PUBLIC_KEY_PATH` for a PEM public key file
- `S3_EXTERNAL_IAM_PUBLIC_KEY` for an inline PEM public key

Configure exactly one of these sources for a single issuer. Verification keys
must be RSA keys of at least 2,048 bits. When a JWKS key declares `use`, `alg`,
or `key_ops`, it must permit signature verification with RS256 (`use=sig`,
`alg=RS256`, and `verify` in `key_ops`). Duplicate `kid` values and ambiguous
or wrongly typed key sources make startup fail. JWT clock skew is restricted to
0–3,600 seconds so a configuration mistake cannot effectively disable token
expiry and issued-at checks.

Local JWKS and PEM verification-key files must be non-empty regular files no
larger than 1 MiB, owned by root or the service user, and not writable by group
or other users. Public verification keys may remain group- or world-readable
(for example, mode `0644`). Every lexical and resolved directory ancestor must
also be owned by root or the service user and must not be group/other-writable
unless it is sticky. The server rejects unsafe links, unreadable files, and
files replaced while they are opened or read.

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

Successful revocation returns `204`. An unknown or concurrently removed key
returns `404`; if the observed key was replaced before the compare-and-delete,
the API preserves the replacement and returns `409` with `{"error":"conflict"}`.
All admin responses use `Cache-Control: no-store`.

Before each successful external issuance, the server atomically removes a
small bounded batch of already expired temporary credentials. Permanent
credentials and credentials whose expiry is still in the future are never
included. Invalid identities and cleanup failures do not create a credential.
The file provider performs this rewrite under its single-process writer mutex;
use the database provider when credential state is shared by multiple API
processes.

With a database provider, revocation becomes visible after the positive-lookup
cache TTL (one second by default). Set `S3_CREDENTIALS_CACHE_TTL=0` when the
next request must see revocation; this causes a database lookup for each signed
request.

### Multiple OIDC issuers and owner migration

Programmatic Laravel/Symfony integrations may configure `allowed_issuers` with
more than one issuer. In that case, `keys_by_issuer` is required: map each exact
issuer URL to its own `kid => PEM or RSA JWK` key set. A global `keys`,
`jwks_path`, or `public_key` cannot verify multi-issuer tokens. Issuer-specific
key IDs may repeat; a token's untrusted `iss` only chooses its key set, and
the signature, issuer, and audience are then verified. The server rejects an
identical public key in two issuer sets because that key could sign tokens for
either issuer. Keep signing keys separate across independent issuers.

```php
'allowed_issuers' => ['https://idp-a.example/realm', 'https://idp-b.example/realm'],
'keys_by_issuer' => [
    'https://idp-a.example/realm' => ['current' => $issuerAPublicKey],
    'https://idp-b.example/realm' => ['current' => $issuerBPublicKey],
],
```

With multiple issuers, the S3 owner ID is the configured `owner_prefix` plus
`oidc:` plus lowercase SHA-256 hex of a four-byte big-endian issuer length,
the exact issuer bytes, and the raw configured owner-claim bytes.
`OidcClaimMapper::issuerScopedOwnerId()`
computes the value for migration inventories. The owner prefix may contain at
most 186 bytes so the complete ID fits the 255-byte metadata and credential
fields; invalid multi-issuer configurations fail at startup. One-issuer
configurations retain their prior owner IDs exactly.

Changing an existing one-issuer deployment to multiple issuers is a cutover:
the new scoped IDs differ even for its original issuer. Previously issued S3
credentials keep their old owner IDs until revoked or expired, including any
older permanent credentials. Revoke them before enabling the new configuration,
wait out the running process's positive credential-cache TTL (or set it to
zero), then issue new credentials.

Inventory and back up credentials and metadata before the cutover. Existing
buckets, account quotas, account policies, ACL principals, and other owner
references do not change automatically. If two issuers previously used the
same owner claim, the old owner ID cannot identify which issuer owns a bucket
or policy. Assign each resource explicitly from authoritative records; do not
infer its issuer from the shared ID. There is no online owner-reassignment API:
either migrate a verified snapshot offline with all references checked, or
create new issuer-scoped buckets and copy objects under new credentials. Test
same-claim tokens from both issuers, cross-issuer signatures, revocation, and
access to each assigned bucket before serving traffic.

Credentials always expire and include `sessionToken` and `expiresAt`. Omitting
`ttlSeconds` requests a one-hour lifetime; explicit values must be from 3,600 to
43,200 seconds (one to twelve hours). The issuing JWT's `exp` is an additional
hard limit, so the effective credential lifetime can be shorter. SDK clients
must pass all three credential fields and refresh before expiry. Header-based
SigV4, presigned URLs, and POST object uploads reject missing, invalid, or
expired session tokens. Credentials
already issued without an expiry must be revoked separately if they should no
longer be usable.

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
    "maxBytesPerOwner": 1099511627776,
    "maxMultipartUploadsPerBucket": 0,
    "maxMultipartUploadsPerOwner": 0,
    "maxMultipartBytesPerBucket": 0,
    "maxMultipartBytesPerOwner": 0
  }'
```

`PUT` replaces the complete per-account quota override. All eight fields are
required; an omitted field or `null` returns `400` and leaves the stored
override unchanged. Set a field explicitly to `0` for no limit. The aliases
`maxBuckets` and `maxBytes` may replace `maxBucketsPerOwner` and
`maxBytesPerOwner`, respectively.

List, show, and delete overrides:

```bash
curl http://127.0.0.1:9000/.admin/quotas \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"

curl 'http://127.0.0.1:9000/.admin/quotas?limit=100' \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"

curl http://127.0.0.1:9000/.admin/quotas/tenant%3Aacme \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"

curl -X DELETE http://127.0.0.1:9000/.admin/quotas/tenant%3Aacme \
  -H "Authorization: Bearer ${S3_ADMIN_API_TOKEN}"
```

The unpaged list retains its `{"quotas": [...]}` response for up to 1000
overrides; above that it returns `400 pagination_required`. Paginated requests
accept `limit` from 1 to 1000 (default 100 when only `cursor` is supplied) and
return `{"quotas": [...], "nextCursor": "..."}`. Send the returned `nextCursor`
as the next request's URL-encoded `cursor` parameter until it is `null`.
Pages follow owner ID order; concurrent changes may appear on later pages.
Owner IDs must contain 1 to 255 bytes of valid UTF-8, contain no control
characters, and use only characters permitted in XML 1.0 responses. IDs may
contain `/`; percent-encode it as `%2F` in an admin API path so the complete
remainder identifies one owner.

If legacy or manually edited metadata contains an invalid owner ID, list
responses omit that row from `quotas` and include a `repairHints` entry with an
`invalid_owner_id` issue and the SHA-256 digest of the stored bytes. The raw
invalid value and its quota are not returned. Cursor pagination still advances
past the row; use the digest to identify and repair it directly in the metadata
database.

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

Each `providers` entry may be either a constructed credential-provider object
or a nested configuration with a nonempty `driver`. An empty chain, an
associative rather than list-shaped collection, and malformed nested entries
are rejected during construction.

Credential creation is insert-only: an explicit access key ID that already
exists is rejected without rotating the existing secret or changing its owner
or active state. A generated ID is retried a bounded number of times if it
collides. Custom writable providers used by `CredentialManager` must implement
`AtomicCredentialCreator`; management creation refuses an unprotected upsert.
Use `putCredential()` only for an intentional credential update or rotation.
When chaining independently writable providers, assign disjoint access-key
namespaces. The chain can check all providers before a write, but cannot make
that check atomic across separate stores; a later duplicate in a lower-priority
provider could become visible if the higher-priority key is removed.
For the same reason, both `CredentialManager` and direct
`ChainCredentialProvider::deleteCredential()` refuse deletion through a chain
with more than one provider. Delete from the intended underlying store
under its own administrative controls after confirming no duplicate key exists.

## Multi-Tenancy

Every credential has an `ownerId`. Bucket ownership controls creation,
enumeration, and deletion:

- `ListBuckets` returns only buckets owned by the authenticated user
- `CreateBucket` assigns ownership to the authenticated user
- `DeleteBucket` verifies ownership before deletion
- Object operations check the bucket and object owner plus the applicable
  identity policy, bucket policy, ACL, and Public Access Block settings

A signed account accessing another account's bucket needs an explicit
identity-policy Allow and resource-side consent through a bucket policy or an
applicable ACL. Explicit Deny takes precedence. See
[cross-account policy behavior](policy-compatibility.md#external-credential-scopes)
for object-owner and version-specific details. Separate owner IDs and private
buckets provide isolation by default.

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
- Signed query parameters cannot contain different values for the same decoded
  name. SigV4 sorts query pairs before signing, while S3 operations consume
  their first value; rejecting this ambiguity prevents one signature from
  selecting different resources when duplicate pairs are reordered. Identical
  duplicate values remain valid.
  The read-only `x-amz-object-attributes` list is order-insensitive: the AWS SDK
  copies its signed header values into repeated query pairs for presigned
  requests. Those pairs are accepted only when that header is present and
  named in `SignedHeaders`; the object-attributes handler reads the signed
  header as a set.
- Expiration time (`X-Amz-Expires` must be a decimal integer from 1 to 604800 seconds)
- `host` header is in `SignedHeaders` (prevents cross-bucket reuse)
- Every supplied `x-amz-*` header and `Content-MD5` appears in `SignedHeaders`.
  A recipient cannot add an unsigned ACL, copy source, encryption option, or
  checksum to a delegated URL. Generate a new URL with those headers signed
  when they are needed. Header-based SigV4 applies the same rule, except that
  the required `x-amz-content-sha256` is bound through the canonical payload
  hash. Header-based requests use an exact basic UTC `x-amz-date`, or a `Date`
  header in that same format when `x-amz-date` is absent.

The server rejects repeated field lines for security-sensitive singleton
headers instead of silently using only their first value. Core ACL, Object
Lock, ownership, tagging, content metadata, checksum-selection, and streaming
controls are checked before authentication or authorization. Specialized
encryption and copy-source controls are checked by their operation parsers
before storage access. Requests containing both `Content-Length` and
`Transfer-Encoding` are rejected before routing or authentication; HTTP/1
connections are poisoned and closed so already buffered follow-up requests
cannot dispatch. Documented list-valued headers are combined and evaluated in
full.

## Anonymous Requests and Required Middleware

Every programmatic server configuration must register an
`AuthenticationMiddleware`; startup otherwise fails with
`Refusing to start without authentication middleware.` There is no built-in
global authentication-disabled mode, and the built-in anonymous path never
assigns a default owner. The marker interface prevents accidental omission from the
middleware stack; it does not validate a custom implementation. Custom
authentication middleware remains responsible for enforcing its authentication
contract and for introducing an anonymous principal only where intended.

The built-in `AuthMiddleware` still supports unsigned bucket and object API
requests by representing them as the anonymous principal. The normal bucket
policy, ACL, Object Ownership, and Public Access Block checks then decide
whether each operation is allowed; service-level and other
authentication-required operations remain denied. Dedicated website-host
`GET` and `HEAD` requests are also anonymous. Serving object or error-document
content requires either an `AllUsers` object ACL with `READ` or a matching
bucket-policy `Allow` for `s3:GetObject`, subject to explicit denies and Public
Access Block settings. Redirect-all responses and routing-rule redirects that
match only a key prefix are generated from the bucket website configuration
without reading object content, so those redirects do not require an object ACL
or bucket-policy allow.

## Custom Authentication

Add your own middleware for custom auth flows:

```php
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\AuthType;
use OpsFour\S3Server\Contracts\AuthenticationMiddleware;

class MyAuthMiddleware implements AuthenticationMiddleware
{
    public function handleRequest(Request $request, RequestHandler $handler): Response
    {
        // Your auth logic here
        $request->setAttribute('ownerId', 'verified-owner-id');
        // Optional: set this only after your own authentication succeeds.
        AuthType::set($request, AuthType::RestHeader);
        return $handler->handleRequest($request);
    }
}

$server->addMiddleware(new MyAuthMiddleware());
```

Custom authentication remains compatible without this opt-in: policies that
do not use `s3:authType` behave unchanged, while policies that do use it see the
key as missing. Supplying the string `REST-HEADER` as a request attribute is not
an opt-in; policy builders accept only the typed `AuthType` value.

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
  --credentials-dsn="pgsql:host=localhost;port=5432;dbname=s3server" \
  --credentials-username=s3 \
  --credentials-password=secret \
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

The CLI option exposes the supplied secret to shell history and potentially to
process listings. Prefer generated credentials. If a custom key is required,
run the command only in an isolated administrative environment and prevent
history capture.

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

Each credential has an `ownerId`. Create one credential per tenant:

```bash
# Tenant A
php artisan s3:credentials create --owner-id=tenant-a --display-name="Company A"
# → AKIA... / secret...

# Tenant B
php artisan s3:credentials create --owner-id=tenant-b --display-name="Company B"
# → AKIA... / secret...
```

By default, each tenant lists and manages its own buckets and private objects.
Cross-account object access requires the explicit grants described above;
bucket creation, listing, and deletion remain owner-scoped. Multiple
credentials can share the same `ownerId` (for example, separate API keys for
one team).
