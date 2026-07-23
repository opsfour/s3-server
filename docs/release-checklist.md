# Release Checklist

Use this checklist before tagging a production release. A release candidate is
not complete merely because the default PHPUnit run passes: the opt-in
integration and load profiles below are release gates for the backends being
advertised.

## Current Release Candidate

Validated on 2026-07-23 with PHP 8.4.8:

- SQLite schema version is `11`; PostgreSQL and MySQL schema version is `12`.
- AWS STS Query API compatibility is intentionally outside the release scope.
- Website configuration activates the dedicated website endpoint; each object
  still requires anonymous `s3:GetObject` permission through ACL or bucket
  policy.
- External S3-compatible storage was validated against the configured Linode
  bucket through the Flysystem AWS S3 adapter.
- PostgreSQL 16 and MySQL 8.0 were validated with fresh schemas, metadata
  roundtrips, observability, two-process quota contention, and destructive
  schema-v11-to-v12 upgrade profiles in disposable local containers.

## Recorded Verification

| Profile | Result |
|---------|--------|
| Complete default suite | 598 tests, 2,917 assertions, 9 expected skips, 0 failures; 1:27.239; 70.50 MB |
| PHPStan, complete package | No errors |
| PHP CS Fixer, 352 files | No changes required |
| Composer locked dependency audit | No known security advisories |
| 256 MiB streamed PUT/GET | Passed; 25.872 seconds; 28 MB |
| 128 MiB multipart upload, 4 x 32 MiB | Passed; 16.866 seconds |
| 50 objects / 25 concurrent requests | Passed; 54 assertions; 4.655 seconds |
| 200 objects / 100 concurrent requests | Passed; 204 assertions; 5.641 seconds |
| Five-minute production soak | Passed; 2,812 assertions; 5:10.529; 50.86 MB |
| External Linode Flysystem backend | Passed; 2 tests, 11 assertions; 7.896 seconds |
| PostgreSQL 16 metadata integration | Passed; 2 tests, 20 assertions; v11-to-v12 migration 1 test, 3 assertions |
| MySQL 8.0 metadata integration | Passed; 2 tests, 20 assertions; v11-to-v12 migration 1 test, 3 assertions |

The nine default-suite skips are one opt-in soak test, two external S3 tests,
and six MySQL/PostgreSQL data-provider cases. The soak, external S3, normal
database integration, and destructive migration profiles were run separately
and passed. OpenSSL was present, so TLS and JWT coverage did not skip.

## Required Verification

Run all commands from the package directory before tagging:

```bash
vendor/bin/phpunit
../../vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run --diff --sequential
composer validate --strict
composer audit --locked
git diff --check
```

Validate every advertised external backend:

```bash
S3_INTEGRATION_ENABLED=1 \
vendor/bin/phpunit tests/Functional/ExternalS3StorageIntegrationTest.php

S3_TEST_MYSQL_METADATA_DSN='host=...;port=3306;dbname=...;user=...;password=...' \
vendor/bin/phpunit tests/Functional/MetadataBackendIntegrationTest.php

S3_TEST_POSTGRES_METADATA_DSN='host=... port=5432 dbname=... user=... password=...' \
vendor/bin/phpunit tests/Functional/MetadataBackendIntegrationTest.php
```

Only against disposable databases, validate the current destructive upgrade
fixture:

```bash
S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 \
S3_TEST_MYSQL_METADATA_DSN='host=... port=3306 dbname=... user=... password=...' \
vendor/bin/phpunit tests/Functional/MetadataBackendIntegrationTest.php \
  --filter 'migrates_v11.*mysql'

S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 \
S3_TEST_POSTGRES_METADATA_DSN='host=... port=5432 dbname=... user=... password=...' \
vendor/bin/phpunit tests/Functional/MetadataBackendIntegrationTest.php \
  --filter 'migrates_v11.*postgres'
```

Run the release load profiles:

```bash
S3_TEST_LARGE_OBJECT_BYTES=268435456 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php \
  --filter test_large_put_and_get_use_file_streams_and_preserve_bytes

S3_TEST_MULTIPART_PART_BYTES=33554432 \
S3_TEST_MULTIPART_PARTS=4 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php \
  --filter test_large_multipart_upload_round_trip_from_streamed_parts

S3_TEST_CONCURRENT_OBJECTS=200 \
S3_TEST_CONCURRENT_OBJECT_BYTES=65536 \
S3_TEST_CONCURRENT_REQUESTS=100 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php \
  --filter test_concurrent_put_head_get_delete_workload_stays_responsive

S3_TEST_PRODUCTION_SOAK=1 \
S3_TEST_PRODUCTION_SOAK_SECONDS=300 \
S3_TEST_PRODUCTION_SOAK_BATCH=25 \
S3_TEST_PRODUCTION_SOAK_OBJECT_BYTES=262144 \
S3_TEST_PRODUCTION_SOAK_CONCURRENCY=25 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php \
  --filter test_opt_in_production_soak_keeps_memory_temp_files_and_responses_stable
```

## Upgrade Notes

- Back up metadata before starting a node with this package version.
- Back up every configured storage tier, not only the default hot tier.
- Let one node complete schema migration before adding more serving nodes.
- Verify all nodes use the same tier configuration names before enabling
  lifecycle transition rules.
- Watch `s3_server_tiering_worker_results_total` and
  `s3_server_notification_queue_depth` after rollout.
- Rollback after migration requires a package version that understands SQLite
  schema `11` and PostgreSQL/MySQL schema `12`, or a pre-migration metadata
  restore.

## Tagging

Do not tag with a dirty worktree or a pending release gate:

```bash
git status --short
git tag -a vX.Y.Z -m "Release vX.Y.Z"
```
