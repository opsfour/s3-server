# API Operations

opsfour S3 Server implements 69 S3 API operations using standard AWS S3
request/response formats. Compatibility is limited to the documented operation
and policy subset; AWS-specific control-plane services such as KMS and STS are
not implied.

## Bucket Operations

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| CreateBucket | `PUT` | `/{bucket}` | Create a new bucket |
| DeleteBucket | `DELETE` | `/{bucket}` | Delete an empty bucket |
| HeadBucket | `HEAD` | `/{bucket}` | Check if bucket exists |
| ListBuckets | `GET` | `/` | List the authenticated owner's buckets; supports `max-buckets`, `continuation-token`, `prefix`, and `bucket-region` |
| GetBucketLocation | `GET` | `/{bucket}?location` | Get bucket region |

`DeleteBucket` removes incomplete multipart uploads when the bucket has no
objects. Up to 256 uploads and 10,000 parts are cleaned up directly. Larger
backlogs are durably queued for background physical abort after the metadata
deletion commits; the response can arrive before all staged part bytes are gone.
Custom metadata stores need generation-bound listing and bulk-cleanup
capabilities for this endpoint; otherwise it returns HTTP 501. See
[Metadata Backends](metadata-backends.md#generation-bound-multipart-listings).
CreateBucket ignores `x-amz-expected-bucket-owner`; supported bucket and object
operations reject a mismatched expected owner before processing the request.

## Object CRUD

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| PutObject | `PUT` | `/{bucket}/{key}` | Upload an object, including signed or unsigned `aws-chunked` checksum trailers |
| GetObject | `GET` | `/{bucket}/{key}` | Download an object (supports Range headers) |
| HeadObject | `HEAD` | `/{bucket}/{key}` | Get object metadata without body |
| DeleteObject | `DELETE` | `/{bucket}/{key}` | Delete an object (or create delete marker) |
| DeleteObjects | `POST` | `/{bucket}?delete` | Batch delete up to 1000 objects; requires a verified Content-MD5 or supported `x-amz-checksum-*` body digest |
| PostObject | `POST` | `/{bucket}` | Browser-style form upload |
| CopyObject | `PUT` | `/{bucket}/{key}` | Copy object (with `x-amz-copy-source` header) |

Known S3 query selectors and subresources are checked against the request
method, bucket/object scope, and resolved operation. Listing modifiers such as
`marker`, `max-keys`, and `max-uploads` are accepted only for their respective
listing operations; unknown query parameters are not rejected as a group.

CopyObject and UploadPartCopy use `x-amz-source-expected-bucket-owner` to check
the source bucket before reading its object; `x-amz-expected-bucket-owner`
checks the destination bucket.

CopyObject can copy the current object onto the same key when the request
actually changes its supported storage class. A no-op self-copy, including one
that repeats the current storage class, returns `InvalidRequest`.
A single CopyObject request is limited to 5 GiB; larger sources require
multipart copy and return `EntityTooLarge` before storage I/O.
CopyObject checksum selection through `x-amz-checksum-algorithm` is not in the
documented subset and returns HTTP 501 before metadata or storage access.

PutObject accepts one CRC32, CRC32C, SHA1, or SHA256 checksum header. The
optional `x-amz-sdk-checksum-algorithm` selector must identify that same
header or the single verified `aws-chunked` checksum trailer. Duplicate
digest, selector, and trailer-declaration headers are rejected. Unknown
`x-amz-checksum-*` algorithms return HTTP 501 before metadata or storage
access. Successful responses containing a verified checksum also report
`x-amz-checksum-type: FULL_OBJECT`.
When explicit Object Lock retention or legal-hold headers are present, or the
bucket has effective default retention, PutObject requires `Content-MD5` or a
supported SDK checksum. The request fails before object-storage I/O when that
integrity check is missing or invalid.

Stored checksum headers on GET and HEAD require
`x-amz-checksum-mode: ENABLED`; other nonempty values are rejected. The
`partNumber` selector is not implemented for GET or HEAD and returns HTTP 501
before object metadata or bytes are read. Successful and `304 Not Modified`
GET, HEAD and GetObjectAttributes responses carry the selected
`x-amz-version-id`; this includes the literal `null` version of a bucket whose
versioning is suspended. When an enabled full-object GET or HEAD response
contains at least one stored checksum it also contains
`x-amz-checksum-type: FULL_OBJECT`. GetObjectAttributes returns the same type
inside its `Checksum` element. Legacy objects without a stored checksum report
neither a checksum value nor a checksum type. CopyObject preserves verified
source checksums independently of its user-metadata directive and returns them
with `ChecksumType` set to `FULL_OBJECT`; checksum-free source objects return
no checksum fields.

`PostObject` authenticates supported V2/V4 policy fields before spooling the
file. Object options must be policy-covered form fields; raw HTTP object-option
headers are rejected before the file is spooled. Multipart boundaries are limited to the 70 characters specified by
[RFC 2046](https://www.rfc-editor.org/rfc/rfc2046#section-5.1.1). The current
form parser recognizes only complete multipart delimiters, so a delimiter
prefix with a different suffix remains part of the file. A policy-covered
[`Content-MD5` form field](https://docs.aws.amazon.com/AmazonS3/latest/developerguide/RESTObjectPOST.html)
is applied to the uploaded file and validated; supported
`x-amz-checksum-*` form fields receive the same validation. Browser uploads
with explicit Object Lock settings or effective bucket default retention must
supply one of these checksums.

## Listing

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| ListObjectsV2 | `GET` | `/{bucket}?list-type=2` | List objects (recommended) |
| ListObjects | `GET` | `/{bucket}` | List objects (v1 legacy) |
| ListObjectVersions | `GET` | `/{bucket}?versions` | List all versions of objects |

Only the exact value `list-type=2` selects ListObjectsV2; any other supplied
value returns `InvalidArgument`. Its `fetch-owner` parameter accepts only the
case-insensitive values `true` and `false`. ListObjects v1 returns
`NextMarker` for truncated results only when a `delimiter` was supplied, as
required by the S3 response contract. ListObjectVersions requires a nonempty
`key-marker` whenever `version-id-marker` is supplied.
When `encoding-type=url` is requested, listing response fields use RFC 3986
percent-encoding; this includes encoding `/` as `%2F` and `*` as `%2A`.
ListObjectVersions emits object versions and delete markers in their shared
metadata sort order rather than grouping the two entry types.

## Multipart Uploads

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| CreateMultipartUpload | `POST` | `/{bucket}/{key}?uploads` | Initiate multipart upload |
| UploadPart | `PUT` | `/{bucket}/{key}?uploadId=...&partNumber=...` | Upload a part, including signed or unsigned `aws-chunked` checksum trailers |
| UploadPartCopy | `PUT` | `/{bucket}/{key}?uploadId=...&partNumber=...` | Copy a part from existing object |
| CompleteMultipartUpload | `POST` | `/{bucket}/{key}?uploadId=...` | Complete and assemble parts |
| AbortMultipartUpload | `DELETE` | `/{bucket}/{key}?uploadId=...` | Initiator, bucket owner, or explicit bucket-policy grantee cancels and cleans up parts |
| ListParts | `GET` | `/{bucket}/{key}?uploadId=...` | List uploaded parts using `part-number-marker` and `max-parts` (up to 1000 per page) |
| ListMultipartUploads | `GET` | `/{bucket}?uploads` | List in-progress uploads |

Custom metadata stores must support generation-bound multipart listings for
`ListMultipartUploads`; otherwise this endpoint returns HTTP 501. See
[Metadata Backends](metadata-backends.md#generation-bound-multipart-listings).
`upload-id-marker` may be supplied without `key-marker`, or with an empty
`key-marker`; those forms are accepted rather than rejected as malformed.

Each uploaded or copied part is limited to 5 GiB. An oversized part returns
`EntityTooLarge` (HTTP 400); it cannot be committed to the upload.
CompleteMultipartUpload also rejects a selected part set above S3's 5-TiB
object limit before creating a durable write attempt or invoking assembly.
UploadPart accepts one CRC32, CRC32C, SHA1, or SHA256 checksum. It validates
the digest against the complete logical part, returns the matching checksum
response header, persists it with the part, and includes it in ListParts.
`x-amz-sdk-checksum-algorithm` must match the normal checksum header or the
single checksum declared in a verified signed or unsigned `aws-chunked`
trailer. Duplicate digest, selector, and trailer-declaration headers are
rejected. Missing, malformed, unverified, or mismatched declarations fail
before part metadata is committed. Unknown `x-amz-checksum-*` headers return
HTTP 501 before metadata or storage access.

UploadPart requires `Content-MD5` or a supported SDK checksum when the
multipart upload carries explicit Object Lock settings or was initiated under
effective bucket default retention. CreateMultipartUpload binds the upload to
the bucket's Object Lock configuration. A configuration change before a
part uploaded through UploadPart or UploadPartCopy is committed, or before
completion, returns `OperationAborted`, and the upload must be started again.
Legacy uploads without that binding also require a restart when Object Lock is
effective. UploadPartCopy and CompleteMultipartUpload do not accept a client
object payload and therefore do not use this payload-checksum gate.

Custom metadata stores must implement `MultipartPartChecksumStore`, report the
capability as available, and persist the checksum atomically with the part.
UploadPart requests carrying a supported checksum return HTTP 501 before
metadata or storage access when that capability is unavailable. Metadata
decorators must delegate the capability to their wrapped store.

Composite multipart checksums are not yet supported through completion.
CreateMultipartUpload therefore returns HTTP 501 when
`x-amz-checksum-algorithm` or `x-amz-checksum-type` is supplied.
CompleteMultipartUpload likewise returns HTTP 501 for additional-checksum
headers before reading or changing multipart metadata. This applies to every
`x-amz-checksum-*` header, including algorithms newer than the supported
UploadPart subset, and to `x-amz-sdk-checksum-algorithm`.

ListParts and ListMultipartUploads report the identity that created the upload
as `Initiator`. Their `Owner` element follows the bucket ownership rules and
can therefore differ under `BucketOwnerEnforced`.

## Versioning

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketVersioning | `GET` | `/{bucket}?versioning` | Get versioning status |
| PutBucketVersioning | `PUT` | `/{bucket}?versioning` | Enable/suspend versioning |

See [Versioning & Object Lock](versioning.md) for details.

## Object Lock & Retention

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetObjectLockConfig | `GET` | `/{bucket}?object-lock` | Get lock configuration |
| PutObjectLockConfig | `PUT` | `/{bucket}?object-lock` | Set lock configuration |
| GetObjectRetention | `GET` | `/{bucket}/{key}?retention` | Get retention policy |
| PutObjectRetention | `PUT` | `/{bucket}/{key}?retention` | Set retention (Governance/Compliance) |
| GetObjectLegalHold | `GET` | `/{bucket}/{key}?legal-hold` | Get legal hold status |
| PutObjectLegalHold | `PUT` | `/{bucket}/{key}?legal-hold` | Set legal hold ON/OFF |

For these three Object Lock `PUT` operations, a declared
`x-amz-sdk-checksum-algorithm` requires a matching supported checksum header.
Supplied CRC32, CRC32C, SHA1 and SHA256 values are validated before mutation.

## ACLs

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketAcl | `GET` | `/{bucket}?acl` | Get bucket ACL |
| PutBucketAcl | `PUT` | `/{bucket}?acl` | Set bucket ACL |
| GetObjectAcl | `GET` | `/{bucket}/{key}?acl` | Get object ACL |
| PutObjectAcl | `PUT` | `/{bucket}/{key}?acl` | Set object ACL |

## Object Ownership

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketOwnershipControls | `GET` | `/{bucket}?ownershipControls` | Get explicitly stored Object Ownership controls |
| PutBucketOwnershipControls | `PUT` | `/{bucket}?ownershipControls` | Set `ObjectWriter` or `BucketOwnerEnforced` |
| DeleteBucketOwnershipControls | `DELETE` | `/{bucket}?ownershipControls` | Remove explicit controls and use the `ObjectWriter` default |

New buckets use `ObjectWriter` unless `x-amz-object-ownership: BucketOwnerEnforced`
is sent at creation. Existing buckets without controls also use `ObjectWriter`;
they are not migrated automatically. `BucketOwnerEnforced` requires a private
bucket ACL. In that mode object ACL grants are ignored, ACL updates fail, and
object writes accept no ACL or `bucket-owner-full-control`. Objects created
while the mode is enforced belong to the bucket owner even if the mode is
later disabled. `BucketOwnerPreferred` is not implemented.

## Policies

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketPolicy | `GET` | `/{bucket}?policy` | Get bucket policy JSON |
| PutBucketPolicy | `PUT` | `/{bucket}?policy` | Set bucket policy |
| DeleteBucketPolicy | `DELETE` | `/{bucket}?policy` | Remove bucket policy |
| GetBucketPolicyStatus | `GET` | `/{bucket}?policyStatus` | Report whether policy grants public access |

## CORS

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketCors | `GET` | `/{bucket}?cors` | Get CORS rules |
| PutBucketCors | `PUT` | `/{bucket}?cors` | Set CORS rules |
| DeleteBucketCors | `DELETE` | `/{bucket}?cors` | Remove CORS rules |

CORS preflight (OPTIONS) is handled automatically by the CorsMiddleware.
`CORSRule.ID` is preserved across PUT/GET. `MaxAgeSeconds` accepts the complete
non-negative signed 32-bit range, including an explicit `0`. Origins and
allowed-header patterns may contain at most one `*`, matching the runtime
matcher used for preflight requests.

## Tagging

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketTagging | `GET` | `/{bucket}?tagging` | Get bucket tags |
| PutBucketTagging | `PUT` | `/{bucket}?tagging` | Set bucket tags |
| DeleteBucketTagging | `DELETE` | `/{bucket}?tagging` | Remove bucket tags |
| GetObjectTagging | `GET` | `/{bucket}/{key}?tagging` | Get object tags |
| PutObjectTagging | `PUT` | `/{bucket}/{key}?tagging` | Set object tags |
| DeleteObjectTagging | `DELETE` | `/{bucket}/{key}?tagging` | Remove object tags |

Every `Tag` requires both `Key` and `Value`; an empty `<Value/>` is valid.

## Public Access Block

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetPublicAccessBlock | `GET` | `/{bucket}?publicAccessBlock` | Get PAB settings |
| PutPublicAccessBlock | `PUT` | `/{bucket}?publicAccessBlock` | Set PAB settings |
| DeletePublicAccessBlock | `DELETE` | `/{bucket}?publicAccessBlock` | Remove PAB settings |

## Encryption Configuration

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketEncryption | `GET` | `/{bucket}?encryption` | Get default encryption |
| PutBucketEncryption | `PUT` | `/{bucket}?encryption` | Set default encryption |
| DeleteBucketEncryption | `DELETE` | `/{bucket}?encryption` | Remove default encryption |

## Lifecycle

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketLifecycle | `GET` | `/{bucket}?lifecycle` | Get lifecycle rules |
| PutBucketLifecycle | `PUT` | `/{bucket}?lifecycle` | Set lifecycle rules |
| DeleteBucketLifecycle | `DELETE` | `/{bucket}?lifecycle` | Remove lifecycle rules |
| RestoreObject | `POST` | `/{bucket}/{key}?restore` | Restore a temporary hot copy from a cold tier |

See [Lifecycle Rules](lifecycle.md) for rule configuration.
RestoreObject accepts 1 through 365,000 days and implements the `Standard`
retrieval tier. Explicit `Bulk` or `Expedited` requests return HTTP 501.
The first accepted restore returns HTTP 202, renewing a completed temporary
copy returns HTTP 200, and a second request while work is pending returns
HTTP 409 with `RestoreAlreadyInProgress`. SELECT restore requests and their
additional fields are outside the documented subset.

Mutation XML accepts the unprefixed form and the S3 namespace, including a
consistent namespace prefix on every element. Foreign namespaces, duplicate
singleton elements, nested scalar elements, and non-whitespace text inside
container elements return `MalformedXML` before mutation. DTD declarations are
rejected even when an alternate XML encoding would hide their byte pattern,
and schema-unknown attributes are rejected except where an operation explicitly
defines that attribute.
This includes structural errors in Object Ownership and Public Access Block
documents; unsupported but structurally valid values keep their operation
specific error response.

## Notifications

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketNotification | `GET` | `/{bucket}?notification` | Get notification config |
| PutBucketNotification | `PUT` | `/{bucket}?notification` | Replace notification config (maximum 256 entries; 257 or more returns `400 InvalidArgument`) |

See [Notifications](notifications.md) for webhook delivery.

## Logging

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketLogging | `GET` | `/{bucket}?logging` | Get access log config |
| PutBucketLogging | `PUT` | `/{bucket}?logging` | Set same-owner, same-region access log target; target grants and custom key formats return `NotImplemented` |

## Website Hosting

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketWebsite | `GET` | `/{bucket}?website` | Get website config |
| PutBucketWebsite | `PUT` | `/{bucket}?website` | Set website config |
| DeleteBucketWebsite | `DELETE` | `/{bucket}?website` | Remove website config |

## S3 Select

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| SelectObjectContent | `POST` | `/{bucket}/{key}?select&select-type=2` | Run SQL query on object |

See [S3 Select](s3-select.md) for SQL syntax.

## Other

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetObjectAttributes | `GET` | `/{bucket}/{key}?attributes` | Get checksum and part info |
| HealthCheck | `GET` | `/.health` | Server health probe, not counted as an S3 API operation |

`GetObjectAttributes` requires both `s3:GetObject` and
`s3:GetObjectAttributes`; an explicit `versionId` instead requires both
`s3:GetObjectVersion` and `s3:GetObjectVersionAttributes`. An object `READ` ACL
can grant the base read action, but the attributes action requires object
ownership or an explicit policy allow. Explicit Deny on either action rejects
the request. See the [AWS GetObjectAttributes permissions](https://docs.aws.amazon.com/AmazonS3/latest/API/API_GetObjectAttributes.html)
and [object ACL action mapping](https://docs.aws.amazon.com/AmazonS3/latest/userguide/acl-overview.html).

For a missing current object, `GetObject`, `HeadObject`, and
`GetObjectAttributes` return `403 AccessDenied` unless the caller has effective
`s3:ListBucket` permission on the bucket; otherwise they return `404 NoSuchKey`.
This check uses the same identity policy, bucket policy, and bucket ACL rules as
bucket listing. Explicit version lookups and current delete markers retain their
separate responses.

## Unsupported AWS-Specific APIs

The exhaustive classification in `tests/Support/aws-s3-operations.php` is
checked against every operation exposed by the installed AWS SDK. AWS-specific
control-plane features remain outside this server's compatibility target,
including S3 Express sessions, metadata tables, object annotations and
annotation tables, Object Lambda, replication, inventory/analytics
configuration, request-payer configuration, and Torrent.

## Error Responses

All errors follow the standard S3 XML error format:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Error>
  <Code>NoSuchBucket</Code>
  <Message>The specified bucket does not exist.</Message>
  <Resource>/my-bucket</Resource>
  <RequestId>abc123</RequestId>
</Error>
```

Common error codes: `AccessDenied`, `NoSuchBucket`, `NoSuchKey`, `BucketAlreadyExists`, `BucketNotEmpty`, `InvalidArgument`, `EntityTooLarge`, `SlowDown`, `InternalError`.
