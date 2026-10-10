# Policy Compatibility Matrix

opsfour S3 Server supports an IAM-style bucket policy subset for the S3 data
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
| Owner bypass | Supported local rule | Explicit denies and credential scopes run first. Existing object operations use the stored object owner; bucket operations and new writes use the bucket owner. A foreign object owner inside another account's bucket still needs an identity Allow. |

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
| AWS account-root ARN | Partial | A 12-digit `arn:partition:iam::account:root` matches local IAM and STS principal ARNs with the same partition and account ID. Other local principal strings have no AWS account model. |
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

Every statement must contain exactly one of `Action`/`NotAction` and exactly one
of `Resource`/`NotResource`. New account and named policies written through the
built-in metadata stores are validated before replacement. Identity policies
may contain an empty `Statement` list to deny by omission; bucket policies may
not. An explicitly present `Principal` must have a
valid supported form; only a truly omitted `Principal` is permitted for an
identity policy. Unknown statement fields, including misspelled `Condition`,
are rejected. A malformed statement denies evaluation of the entire policy,
including otherwise valid Allow statements. Before upgrading, repair any
directly stored account or named policy with omitted action/resource selectors,
invalid conditions, or unknown statement fields. Inventory existing direct
policies read-only and repair affected documents through a reviewed operator
procedure before rollout; this release neither migrates nor deletes those rows.
Existing rows still fail closed when evaluated. Host applications that
write malformed policies must correct those documents before upgrading; new
writes now throw `MalformedPolicy` without changing the prior row.

## Conditions

| Operator | Status | Notes |
| --- | --- | --- |
| `Bool` | Supported | Used for `aws:SecureTransport`; policy values must be JSON Booleans or `true`/`false` strings. |
| `IpAddress` | Supported | IPv4 and IPv6 address or CIDR matching; malformed policy ranges are rejected. |
| `NotIpAddress` | Supported | Negated IPv4 and IPv6 address or CIDR matching; also matches a missing source IP. Malformed policy ranges are rejected. |
| `StringEquals` | Supported | Exact string match against context values. |
| `StringNotEquals` | Supported | Negated exact string match; also matches a missing request key. |
| `StringLike` | Supported | `*` and `?` wildcard match; literal brackets are not character classes. |
| `StringNotLike` | Supported | Negated wildcard match; also matches a missing request key. |
| `Null` | Supported | Checks whether a supported condition key is absent or present; requires one JSON Boolean or `true`/`false` string. |
| Numeric comparisons | Supported | `NumericLessThan`, `NumericLessThanEquals`, `NumericGreaterThan`, `NumericGreaterThanEquals`; thresholds must be finite numbers. |
| Date comparisons | Partial | `DateLessThan`, `DateLessThanEquals`, `DateGreaterThan`, `DateGreaterThanEquals`; Unix epoch seconds, full ISO 8601 timestamps, and W3C year-month/full dates work. Relative dates are rejected; shorter W3C time forms and year-only dates are not supported. |
| `ForAnyValue` / `ForAllValues` modifiers | Supported | Applies supported base operators to multi-value context keys. With a missing key, `ForAnyValue` is false and `ForAllValues` is true. |
| ARN operators | Unsupported | Fail closed with `Deny`. |

Bucket policies are limited to 20 KiB, matching the S3 bucket-policy limit.

| Condition Key | Status | Populated From |
| --- | --- | --- |
| `aws:PrincipalArn` | Supported | Local authenticated principal/owner ID. |
| `aws:CurrentTime` | Supported | Current UTC server time. |
| `aws:SecureTransport` | Supported | Direct TLS state, or the single `X-Forwarded-Proto` value supplied by a configured trusted proxy peer. Missing/ambiguous proxy values are insecure. |
| `aws:SourceIp` | Supported | Direct remote internet address, or the rightmost untrusted `X-Forwarded-For` hop supplied by a configured trusted proxy peer. Missing or invalid forwarded chains are rejected for S3 requests. |
| `aws:UserAgent` | Supported | `User-Agent` request header. |
| `s3:prefix` | Supported | `prefix` query parameter on list operations. |
| `s3:delimiter` | Supported | `delimiter` query parameter on list operations. |
| `s3:max-keys` | Supported | `max-keys` query parameter. |
| `s3:VersionId` | Supported | Explicit `versionId` query parameters are used only by operations that actually select an object version; copy-source version IDs come from the copy-source header. Destination-write query parameters do not supply this key, and the current stored version ID is not inserted when the request omits it. |
| `s3:ExistingObjectTag/<key>` | Package extension | Object tag value from the version actually read or mutated. In Suspended buckets, PUT, POST, copy, multipart initiation/completion, and unversioned DELETE use the null version's tags even when a numbered version is currently latest; explicit version operations use that version's tags. Secondary `s3:PutObjectTagging` checks for tagged writes use the same target tags. Enabled buckets retain the existing latest-version check for writes and unversioned DELETE. AWS does not support this key for `PutObject` or `DeleteObject`, so do not rely on that behavior for portable policies. |
| `s3:RequestObjectTag/<key>` | Supported | Tags supplied in `x-amz-tagging` or the `PutObjectTagging` XML body. For `CopyObject`, the header contributes only with `TaggingDirective=REPLACE`; `COPY` ignores it. |
| `s3:x-amz-acl` | Supported | `x-amz-acl` request header. |
| `s3:x-amz-server-side-encryption` | Supported | `x-amz-server-side-encryption` request header. |
| `s3:x-amz-storage-class` | Supported | `x-amz-storage-class` request header. |
| Organization, VPC, source ARN/account keys | Unsupported | There is no AWS Organization/VPC model. |

## External Credential Scopes

Credentials issued by the external IAM/OIDC admin API can carry
`allowedPrefixes`. These prefixes are enforced before bucket-owner bypass, so a
scoped credential cannot access keys outside its assigned prefixes even when it
uses the bucket owner's `ownerId`.
If a prefix claim is configured for OIDC issuance, its absence or an empty
value rejects issuance; it never means unrestricted access.

Credentials can also carry `policyNames`. Each name is resolved through the
metadata-backed named policy registry and evaluated as an identity policy.
Account-policy writes through the built-in metadata stores take the account's
owner-write lock, so they serialize with S3 commits for that account. A write
commit using attached named policies locks their existing rows in name order
and evaluates the current locked documents. Direct named-policy UPDATE/DELETE
waits for that commit, including when the policy is shared across owners. A
missing named policy fails closed. Host-managed write transactions that read
named policies directly should call `lockNamedPoliciesForUpdate()` and use its
returned documents before committing; ordinary reads are not serialized.
Explicit deny wins across account policies, named credential policies, and bucket
policies. A signed foreign principal needs an explicit identity-policy Allow
and resource-side consent through a bucket policy or applicable ACL. This also
applies when no account or named policy was previously configured: a bucket
policy or ACL grant alone no longer authorizes that credential. Provision the
requester's account or named policy before deploying this change. Anonymous
public access remains subject to the resource policy, ACL, and Public Access
Block without an identity policy. Governance-retention bypass requires both
sides to allow that action.
`AbortMultipartUpload` uses the upload initiator, bucket owner, or an explicit
bucket-policy Allow; bucket ACL `WRITE` alone cannot abort another upload.
For `CopyObject` and `UploadPartCopy`, the source read check uses the stored
object owner. A foreign identity-policy Allow alone cannot read a private source;
the object must be owned by the requester or accessible through its ACL or an
applicable bucket policy. Bucket ownership alone does not read an object owned
by another account.
The same stored-owner rule applies to direct object reads, ACL/tagging and Object
Lock subresources, and restore operations, including specific versions. A bucket
policy can deny access to a foreign-owned object but cannot grant access to it;
the object owner must grant access through the object ACL. `DeleteObject` keeps
its separate bucket-owner authorization rule.

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
| Public Access Block APIs | `s3:GetBucketPublicAccessBlock`, `s3:PutBucketPublicAccessBlock` (also authorizes DeletePublicAccessBlock) |

Unsupported or future operations fall back to `s3:{OperationName}` and only
match if a policy explicitly uses that fallback action name or a wildcard.

`GetBucketPolicy` has an additional owner-account rule: after authorization,
a caller from a different account receives 405 instead of the policy document.
Without authorization the response is 403.
