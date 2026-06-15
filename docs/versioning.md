# Versioning & Object Lock

## Bucket Versioning

Versioning keeps every version of every object, including deletions.

### Enable Versioning

```php
$s3->putBucketVersioning([
    'Bucket' => 'my-bucket',
    'VersioningConfiguration' => [
        'Status' => 'Enabled',  // or 'Suspended'
    ],
]);
```

### Behavior

| State | PUT | DELETE | GET |
|-------|-----|--------|-----|
| Disabled | Overwrites | Permanently deletes | Returns current |
| Enabled | Creates new version | Creates delete marker | Returns latest version |
| Suspended | Overwrites `null` version | Creates delete marker with `versionId=null` | Returns latest version |

### Version IDs

When versioning is enabled, every PUT returns a `x-amz-version-id` header:

```php
$result = $s3->putObject([
    'Bucket' => 'my-bucket',
    'Key'    => 'document.pdf',
    'Body'   => $content,
]);
echo $result['VersionId']; // e.g., "v1_abc123"
```

### List Versions

```php
$result = $s3->listObjectVersions(['Bucket' => 'my-bucket']);
foreach ($result['Versions'] as $version) {
    echo "{$version['Key']} v{$version['VersionId']} "
       . ($version['IsLatest'] ? '(latest)' : '') . "\n";
}
foreach ($result['DeleteMarkers'] ?? [] as $marker) {
    echo "{$marker['Key']} v{$marker['VersionId']} [DELETE MARKER]\n";
}
```

### Access a Specific Version

```php
$result = $s3->getObject([
    'Bucket'    => 'my-bucket',
    'Key'       => 'document.pdf',
    'VersionId' => 'v1_abc123',
]);
```

### Permanently Delete a Version

```php
$s3->deleteObject([
    'Bucket'    => 'my-bucket',
    'Key'       => 'document.pdf',
    'VersionId' => 'v1_abc123',  // removes this specific version
]);
```

## Object Lock

Object Lock prevents objects from being deleted or overwritten for a fixed period or indefinitely. Requires versioning to be enabled.

### Enable Object Lock

Object Lock must be enabled at bucket creation time:

```php
$s3->createBucket([
    'Bucket' => 'immutable-bucket',
    'ObjectLockEnabledForBucket' => true,
]);
```

### Default Retention

Set a default retention policy for all new objects:

```php
$s3->putObjectLockConfiguration([
    'Bucket' => 'immutable-bucket',
    'ObjectLockConfiguration' => [
        'ObjectLockEnabled' => 'Enabled',
        'Rule' => [
            'DefaultRetention' => [
                'Mode' => 'GOVERNANCE',  // or 'COMPLIANCE'
                'Days' => 365,
            ],
        ],
    ],
]);
```

### Retention Modes

| Mode | Delete | Overwrite | Shorten | Extend | Override |
|------|--------|-----------|---------|--------|----------|
| **GOVERNANCE** | Blocked | Blocked | Blocked | Allowed | With `x-amz-bypass-governance-retention: true` |
| **COMPLIANCE** | Blocked | Blocked | Blocked | Allowed | Cannot be overridden (not even by root) |

### Per-Object Retention

```php
$s3->putObjectRetention([
    'Bucket'    => 'immutable-bucket',
    'Key'       => 'audit-log.csv',
    'Retention' => [
        'Mode'            => 'COMPLIANCE',
        'RetainUntilDate' => '2030-01-01T00:00:00Z',
    ],
]);
```

### Legal Hold

A legal hold blocks deletion regardless of retention settings. It can be set and removed independently.

```php
// Apply legal hold
$s3->putObjectLegalHold([
    'Bucket'    => 'immutable-bucket',
    'Key'       => 'evidence.pdf',
    'LegalHold' => ['Status' => 'ON'],
]);

// Remove legal hold
$s3->putObjectLegalHold([
    'Bucket'    => 'immutable-bucket',
    'Key'       => 'evidence.pdf',
    'LegalHold' => ['Status' => 'OFF'],
]);
```

### Check Status

```php
$retention = $s3->getObjectRetention([
    'Bucket' => 'immutable-bucket',
    'Key'    => 'audit-log.csv',
]);
echo $retention['Retention']['Mode']; // "COMPLIANCE"
echo $retention['Retention']['RetainUntilDate']; // "2030-01-01T00:00:00Z"

$hold = $s3->getObjectLegalHold([
    'Bucket' => 'immutable-bucket',
    'Key'    => 'evidence.pdf',
]);
echo $hold['LegalHold']['Status']; // "ON"
```
