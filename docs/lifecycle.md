# Lifecycle Rules

Lifecycle rules automate object management — expiring old objects, cleaning up noncurrent versions, and aborting incomplete multipart uploads.

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

Move objects to different storage classes (metadata-only in this implementation):

```php
'Transitions' => [
    ['Days' => 30, 'StorageClass' => 'STANDARD_IA'],
    ['Days' => 90, 'StorageClass' => 'GLACIER'],
]
```

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

## Lifecycle Runner

The lifecycle executor runs automatically in the background when the server starts (if metadata and storage backends are available). It periodically evaluates all bucket lifecycle rules and applies them.

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
