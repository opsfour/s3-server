# Policy Compatibility Matrix

OpsFour S3 Server supports an IAM-style bucket policy subset for the S3 data
plane. The evaluator is intentionally bounded: supported conditions are applied
explicitly, and unsupported condition operators or keys fail closed with
`AccessDenied`.

## Evaluation Model

| Feature | Status | Notes |
| --- | --- | --- |
| JSON bucket policies | Supported | `Version` is accepted but not semantically versioned. |
| Single `Statement` object | Supported | Normalized to a one-item statement list. |
| Statement list | Supported | Must be non-empty and contain only statement objects. |
| `Effect: Allow` | Supported | Allows only if principal, action, resource, and conditions match. |
| `Effect: Deny` | Supported | Explicit deny wins over allow. |
| Invalid/corrupt policy JSON | Fail closed | Directly loaded corrupt policies evaluate as `Deny`; `PutBucketPolicy` rejects malformed documents with `MalformedPolicy`. |
| Unsupported condition operator/key | Fail closed | Evaluates as `Deny` for the whole policy. |
| Account policies | Supported local extension | One policy document per `ownerId`; evaluated before bucket-owner bypass. |
| Named credential policies | Supported local extension | Credentials can reference reusable policy names stored in the metadata backend. |
| Missing named policy reference | Fail closed | A credential that references a missing named policy is denied. |
| Bucket-owner bypass | Supported local rule | Authenticated bucket owner bypasses bucket policy only after account/named identity policies are checked. |

## Principal

| Principal Form | Status | Notes |
| --- | --- | --- |
| `"Principal": "*"` | Supported | Matches anonymous and authenticated principals. |
| `"Principal": {"AWS": "*"}` | Supported | Public principal. |
| `"Principal": {"AWS": "tenant-id"}` | Supported | Matches local `ownerId`/principal string. |
| `"Principal": {"AWS": ["tenant-a", "tenant-b"]}` | Supported | Any listed principal matches. |
| `"NotPrincipal"` | Supported | Negates the same principal matching rules. |
| Omitted `Principal` in bucket policies | Unsupported | Resource policies without `Principal` do not match. |
| Omitted `Principal` in account/named policies | Supported | Identity policies are evaluated in identity-policy mode and may omit `Principal`. |
| AWS account-root ARN | Partial | `arn:...:root` matches ARN-like principals only; there is no AWS account model. |
| Federated/service principals | Unsupported | Use external IAM credential issuance to map identities to local owner IDs. |

## Actions

| Action Form | Status | Notes |
| --- | --- | --- |
| Exact `s3:*` action names | Supported | Requests are mapped from `S3Operation` to IAM-style S3 actions. |
| Wildcards such as `s3:Get*` | Supported | Case-insensitive `fnmatch`. |
| `"*"` / `"s3:*"` | Supported | Matches all mapped S3 actions. |
| `NotAction` | Supported | Negates the same action matching rules. |
| Non-S3 IAM actions | Unsupported | They can be stored, but they never match S3 data-plane requests. |

## Resources

| Resource Form | Status | Notes |
| --- | --- | --- |
| Bucket ARN `arn:aws:s3:::bucket` | Supported | Used for bucket-level operations. |
| Object ARN `arn:aws:s3:::bucket/key` | Supported | Used for object-level operations. |
| Wildcards such as `arn:aws:s3:::bucket/public/*` | Supported | Uses `fnmatch`. |
| `"Resource": "*"` | Supported | Matches all S3 resources. |
| `NotResource` | Supported | Negates resource matching. |
| Access Point / Multi-Region Access Point ARNs | Unsupported | No access point model exists. |

## Conditions

| Operator | Status | Notes |
| --- | --- | --- |
| `Bool` | Supported | Used for `aws:SecureTransport`. |
| `IpAddress` | Supported | IPv4 and IPv6 CIDR matching. |
| `NotIpAddress` | Supported | IPv4 and IPv6 CIDR matching. |
| `StringEquals` | Supported | Exact string match against context values. |
| `StringNotEquals` | Supported | Negated exact string match. |
| `StringLike` | Supported | Wildcard match using `fnmatch`. |
| `StringNotLike` | Supported | Negated wildcard match. |
| `Null` | Supported | Checks whether a supported condition key is absent or present. |
| Numeric comparisons | Supported | `NumericLessThan`, `NumericLessThanEquals`, `NumericGreaterThan`, `NumericGreaterThanEquals`. |
| Date comparisons | Supported | `DateLessThan`, `DateLessThanEquals`, `DateGreaterThan`, `DateGreaterThanEquals`. |
| `ForAnyValue` / `ForAllValues` modifiers | Supported | Applies supported base operators to multi-value context keys. |
| ARN operators | Unsupported | Fail closed with `Deny`. |

| Condition Key | Status | Populated From |
| --- | --- | --- |
| `aws:PrincipalArn` | Supported | Local authenticated principal/owner ID. |
| `aws:CurrentTime` | Supported | Current UTC server time. |
| `aws:SecureTransport` | Supported | TLS state from the HTTP client connection. |
| `aws:SourceIp` | Supported | Remote internet address. |
| `aws:UserAgent` | Supported | `User-Agent` request header. |
| `s3:prefix` | Supported | `prefix` query parameter on list operations. |
| `s3:delimiter` | Supported | `delimiter` query parameter on list operations. |
| `s3:max-keys` | Supported | `max-keys` query parameter. |
| `s3:VersionId` | Supported | `versionId` query parameter. |
| `s3:ExistingObjectTag/<key>` | Supported | Current object tag value from metadata. |
| `s3:RequestObjectTag/<key>` | Supported | `x-amz-tagging` header and `PutObjectTagging` XML body. |
| `s3:x-amz-acl` | Supported | `x-amz-acl` request header. |
| `s3:x-amz-server-side-encryption` | Supported | `x-amz-server-side-encryption` request header. |
| `s3:x-amz-storage-class` | Supported | `x-amz-storage-class` request header. |
| Organization, VPC, source ARN/account keys | Unsupported | There is no AWS Organization/VPC model. |

## External Credential Scopes

Credentials issued by the external IAM/OIDC admin API can carry
`allowedPrefixes`. These prefixes are enforced before bucket-owner bypass, so a
scoped credential cannot access keys outside its assigned prefixes even when it
uses the bucket owner's `ownerId`.

Credentials can also carry `policyNames`. Each name is resolved through the
metadata-backed named policy registry and evaluated as an identity policy.
Explicit deny wins across account policies, named credential policies, and bucket
policies. An allow in either an identity policy or a bucket policy sets
`s3.policyResult=Allow`, which bypasses ACL checks under the server's union
authorization model.

## Operation Mapping

The policy middleware maps server operations to IAM-style S3 actions before
evaluation. The main mappings are:

| Server Operation | IAM Action |
| --- | --- |
| `GetObject`, `HeadObject`, `SelectObjectContent` | `s3:GetObject` |
| `PutObject`, multipart create/upload/complete, copy destination | `s3:PutObject` |
| `DeleteObject`, `DeleteObjects` | `s3:DeleteObject` |
| `ListObjects`, `ListObjectsV2`, `HeadBucket` | `s3:ListBucket` |
| `ListObjectVersions` | `s3:ListBucketVersions` |
| Bucket policy APIs | `s3:GetBucketPolicy`, `s3:PutBucketPolicy`, `s3:DeleteBucketPolicy` |
| Object tag APIs | `s3:GetObjectTagging`, `s3:PutObjectTagging`, `s3:DeleteObjectTagging` |
| Public Access Block APIs | `s3:GetBucketPublicAccessBlock`, `s3:PutBucketPublicAccessBlock`, `s3:DeleteBucketPublicAccessBlock` |

Unsupported or future operations fall back to `s3:{OperationName}` and only
match if a policy explicitly uses that fallback action name or a wildcard.
