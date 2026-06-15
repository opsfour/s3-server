# API Operations

OpsFour S3 Server implements 66 S3 API operations. All operations use standard AWS S3 request/response formats and work with any S3-compatible client.

## Bucket Operations

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| CreateBucket | `PUT` | `/{bucket}` | Create a new bucket |
| DeleteBucket | `DELETE` | `/{bucket}` | Delete an empty bucket |
| HeadBucket | `HEAD` | `/{bucket}` | Check if bucket exists |
| ListBuckets | `GET` | `/` | List all buckets for the authenticated owner |
| GetBucketLocation | `GET` | `/{bucket}?location` | Get bucket region |

## Object CRUD

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| PutObject | `PUT` | `/{bucket}/{key}` | Upload an object |
| GetObject | `GET` | `/{bucket}/{key}` | Download an object (supports Range headers) |
| HeadObject | `HEAD` | `/{bucket}/{key}` | Get object metadata without body |
| DeleteObject | `DELETE` | `/{bucket}/{key}` | Delete an object (or create delete marker) |
| DeleteObjects | `POST` | `/{bucket}?delete` | Batch delete up to 1000 objects |
| PostObject | `POST` | `/{bucket}` | Browser-style form upload |
| CopyObject | `PUT` | `/{bucket}/{key}` | Copy object (with `x-amz-copy-source` header) |

## Listing

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| ListObjectsV2 | `GET` | `/{bucket}?list-type=2` | List objects (recommended) |
| ListObjects | `GET` | `/{bucket}` | List objects (v1 legacy) |
| ListObjectVersions | `GET` | `/{bucket}?versions` | List all versions of objects |

## Multipart Uploads

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| CreateMultipartUpload | `POST` | `/{bucket}/{key}?uploads` | Initiate multipart upload |
| UploadPart | `PUT` | `/{bucket}/{key}?uploadId=...&partNumber=...` | Upload a part |
| UploadPartCopy | `PUT` | `/{bucket}/{key}?uploadId=...&partNumber=...` | Copy a part from existing object |
| CompleteMultipartUpload | `POST` | `/{bucket}/{key}?uploadId=...` | Complete and assemble parts |
| AbortMultipartUpload | `DELETE` | `/{bucket}/{key}?uploadId=...` | Cancel and clean up parts |
| ListParts | `GET` | `/{bucket}/{key}?uploadId=...` | List uploaded parts |
| ListMultipartUploads | `GET` | `/{bucket}?uploads` | List in-progress uploads |

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

## ACLs

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketAcl | `GET` | `/{bucket}?acl` | Get bucket ACL |
| PutBucketAcl | `PUT` | `/{bucket}?acl` | Set bucket ACL |
| GetObjectAcl | `GET` | `/{bucket}/{key}?acl` | Get object ACL |
| PutObjectAcl | `PUT` | `/{bucket}/{key}?acl` | Set object ACL |

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

## Tagging

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketTagging | `GET` | `/{bucket}?tagging` | Get bucket tags |
| PutBucketTagging | `PUT` | `/{bucket}?tagging` | Set bucket tags |
| DeleteBucketTagging | `DELETE` | `/{bucket}?tagging` | Remove bucket tags |
| GetObjectTagging | `GET` | `/{bucket}/{key}?tagging` | Get object tags |
| PutObjectTagging | `PUT` | `/{bucket}/{key}?tagging` | Set object tags |
| DeleteObjectTagging | `DELETE` | `/{bucket}/{key}?tagging` | Remove object tags |

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

## Notifications

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketNotification | `GET` | `/{bucket}?notification` | Get notification config |
| PutBucketNotification | `PUT` | `/{bucket}?notification` | Set notification config |

See [Notifications](notifications.md) for webhook delivery.

## Logging

| Operation | Method | Path | Description |
|-----------|--------|------|-------------|
| GetBucketLogging | `GET` | `/{bucket}?logging` | Get access log config |
| PutBucketLogging | `PUT` | `/{bucket}?logging` | Set access log target |

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
