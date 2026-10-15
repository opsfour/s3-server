# Lifecycle Rules

Lifecycle rules automate object management — expiring old objects, cleaning up noncurrent versions, and aborting incomplete multipart uploads.

A configuration contains at most 1,000 rules, and every rule must contain at
least one action. Current-version transitions may use `Days: 0`; expiration,
noncurrent and incomplete-upload day values must be positive. All day values
are capped at 365,000 so date arithmetic remains portable and fail closed.
Rule IDs contain at most 255 Unicode characters.

## Configure Lifecycle Rules

```php
$s3->putBucketLifecycleConfiguration([
    'Bucket' => 'my-bucket',
    'LifecycleConfiguration' => [
        'Rules' => [
            [
                'ID'     => 'expire-old-logs',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => 'logs/'],
                'Expiration' => ['Days' => 90],
            ],
            [
                'ID'     => 'cleanup-versions',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'NoncurrentVersionExpiration' => [
                    'NoncurrentDays' => 30,
                ],
            ],
            [
                'ID'     => 'abort-incomplete',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'AbortIncompleteMultipartUpload' => [
                    'DaysAfterInitiation' => 7,
                ],
            ],
        ],
    ],
]);
```

## Rule Types

### Object Expiration

Delete objects after a number of days or on a specific date:

```php
// Delete after 90 days
'Expiration' => ['Days' => 90]

// Delete on a specific date
'Expiration' => ['Date' => '2025-12-31T00:00:00Z']
```

In versioned buckets, expiration creates a delete marker on the current version.

### Noncurrent Version Expiration

Delete old versions of objects after they become noncurrent:

```php
'NoncurrentVersionExpiration' => [
    'NoncurrentDays' => 30,
]
```

### Abort Incomplete Multipart Uploads

Clean up abandoned multipart uploads:

```php
'AbortIncompleteMultipartUpload' => [
    'DaysAfterInitiation' => 7,
]
```

### Transitions

Move objects to configured physical storage tiers. Each `StorageClass` must
match a tier name in the server configuration; the default standalone profile
has only `STANDARD`. Configure the target tiers before saving this rule:

```php
'Transitions' => [
    ['Days' => 30, 'StorageClass' => 'STANDARD_IA'],
    ['Days' => 90, 'StorageClass' => 'GLACIER'],
]
```

Configure every source and target tier on each transition worker. If a worker
receives a job for a tier it lacks, it returns the job to the pending queue for
another attempt after 60 seconds without consuming its failure budget or moving
the object. Restore the missing tier configuration to recover these jobs. A
worker that can access both tiers can then complete the copy. API servers
refuse to start when stored objects, lifecycle rules, queued tier work, or
storage-garbage entries reference a tier missing from that node's registry.
Retain a tier's backend until its references have been migrated or removed.
Standalone tier workers can intentionally have fewer tiers and continue to
defer work they cannot perform.

Restore workers also defer jobs for 60 seconds without consuming an attempt
when the source tier is missing locally. Configure that tier on a worker to
complete the restore; until then, the object remains in pending restore state.

## Filters

### Prefix Filter

Apply the rule only to objects with a matching key prefix:

```php
'Filter' => ['Prefix' => 'logs/']
```

### Tag Filter

Apply the rule only to objects with a specific tag:

```php
'Filter' => [
    'Tag' => ['Key' => 'environment', 'Value' => 'staging'],
]
```

### Combined Filter (And)

```php
'Filter' => [
    'And' => [
        'Prefix' => 'logs/',
        'Tags' => [
            ['Key' => 'environment', 'Value' => 'staging'],
        ],
    ],
]
```

An `And` filter requires at least two predicates. Every tag key in it must be
unique; tag keys remain case-sensitive. Every tag filter requires `Key`.
Omitting `Value` matches an empty tag value, as does an explicitly empty
`<Value/>`. Keys and values use the same length and character constraints as
object tags. Malformed, nested, foreign-namespace, or duplicate predicates are
rejected before an existing configuration is replaced. A malformed rule found
directly in legacy/imported metadata is skipped without applying any lifecycle
action. See the
[AWS lifecycle filter rules](https://docs.aws.amazon.com/AmazonS3/latest/userguide/intro-lifecycle-filters.html).

## Lifecycle Runner

The lifecycle executor runs automatically in the background when the server starts (if metadata and storage backends are available). It periodically evaluates all bucket lifecycle rules and applies them. The configured maximum action count is shared by all buckets and stale multipart cleanup in one sweep. When that budget is exhausted, a metadata-backed cursor lets the next process or cluster node resume at the following bucket.

There is no manual trigger needed — the runner handles scheduling internally.

## Retrieve Configuration

```php
$result = $s3->getBucketLifecycleConfiguration(['Bucket' => 'my-bucket']);
foreach ($result['Rules'] as $rule) {
    echo "{$rule['ID']}: {$rule['Status']}\n";
}
```

## Delete Configuration

```php
$s3->deleteBucketLifecycle(['Bucket' => 'my-bucket']);
```
