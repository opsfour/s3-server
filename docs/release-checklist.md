# Release Checklist

Use this checklist before tagging a production release. A release candidate is
not complete merely because the default PHPUnit run passes: the opt-in
integration and load profiles below are release gates for the backends being
advertised.

## Current Release Candidate

Validated on 2026-07-24 with PHP 8.4.8:

- SQLite schema version is `14`; PostgreSQL and MySQL schema version is `15`.
- AWS STS Query API compatibility is intentionally outside the release scope.
- Website configuration activates the dedicated website endpoint; each object
  still requires anonymous `s3:GetObject` permission through ACL or bucket
  policy.
- PostgreSQL 16 and MySQL 8.0 were validated with fresh schemas, metadata
  roundtrips, observability, two-process quota contention, and destructive
  schema-v11-to-v15 upgrade profiles in disposable local containers.
- The external Linode Path-Style profile passed after the complete hardening
  set against the configured remote bucket.

## Recorded Verification

| Profile | Result |
|---------|--------|
| Complete default suite | 745 tests, 3,287 assertions, 9 expected skips, 0 failures; 1:44.904; 80.50 MB |
| Lowest supported dependency set | Composer audit clean; PHPStan 2.1 clean; complete suite passed without deprecations |
| Highest supported dependency set | Laravel 13.21, Symfony 8.1, AWS SDK 3.389, PHPStan 2.2, and PHPUnit 11.5 passed |
| AWS SDK operation guard | Every S3 operation exposed by AWS SDK 3.389 is routed or explicitly documented as unsupported |
| PHPStan, complete package | No errors |
| PHP CS Fixer, 395 files | No changes required |
| Composer locked dependency audit | No known security advisories |
| 256 MiB streamed PUT/GET | Passed; 58.816 seconds; 32 MB |
| 128 MiB multipart upload, 4 x 32 MiB | Passed; 35.238 seconds; 30 MB |
| 200 objects / 100 concurrent requests | Passed; 204 assertions; 8.592 seconds; 62 MB |
| Five-minute production soak | Passed; 598 assertions; 5:20.138; 44 MB |
| External Linode Flysystem backend | Passed; 64 MiB object plus 3 x 8 MiB multipart; 2 tests, 11 assertions; 50.830 seconds; 86.05 MB; Path-Style |
| PostgreSQL 16 + MySQL 8.0 metadata integration | Passed; 6 tests, 82 assertions, 2 expected migration-profile skips; fresh schema, queues, stale-lease recovery, long-key tags, and quota concurrency |
| PostgreSQL 16 + MySQL 8.0 destructive migration | Passed; 2 tests, 6 assertions; v11-to-v15 |

The nine default-suite skips are one opt-in soak test, two external S3 tests,
and six MySQL/PostgreSQL data-provider cases. The soak and all database
integration/migration profiles were run separately and passed. The external S3
profile was also run separately and passed. OpenSSL was present, so TLS and JWT
coverage did not skip.

## Required Verification

Run all commands from the package directory before tagging:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress
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
  --filter 'migrates_v11_schema_to_current.*mysql'

S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 \
S3_TEST_POSTGRES_METADATA_DSN='host=... port=5432 dbname=... user=... password=...' \
vendor/bin/phpunit tests/Functional/MetadataBackendIntegrationTest.php \
  --filter 'migrates_v11_schema_to_current.*postgres'
```

Run the release load profiles:

```bash
S3_TEST_LARGE_OBJECT_BYTES=268435456 \
S3_TEST_TRANSFER_TIMEOUT_SECONDS=180 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php \
  --filter test_large_put_and_get_use_file_streams_and_preserve_bytes

S3_TEST_MULTIPART_PART_BYTES=33554432 \
S3_TEST_MULTIPART_PARTS=4 \
S3_TEST_TRANSFER_TIMEOUT_SECONDS=180 \
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
  schema `14` and PostgreSQL/MySQL schema `15`, or a pre-migration metadata
  restore.

## Tagging

Do not tag with a dirty worktree or a pending release gate:

```bash
git status --short
git tag -a vX.Y.Z -m "Release vX.Y.Z"
```
