# Release Checklist

Use this checklist before tagging a production release.

## Current Release Candidate

Validated on 2026-06-15:

- Physical storage tier movement and `RestoreObject` workflow are implemented.
- Metadata schema version is `11` for SQLite, MySQL, and PostgreSQL.
- AWS STS Query API compatibility remains optional and intentionally out of the
  current release scope.
- External S3-compatible storage was validated against the configured Linode
  bucket through the Flysystem S3 adapter.
- A short production soak profile was validated locally.

## Required Verification

Run before tagging:

```bash
vendor/bin/phpunit
S3_INTEGRATION_ENABLED=1 vendor/bin/phpunit tests/Functional/ExternalS3StorageIntegrationTest.php
S3_TEST_PRODUCTION_SOAK=1 \
S3_TEST_PRODUCTION_SOAK_SECONDS=300 \
S3_TEST_PRODUCTION_SOAK_BATCH=25 \
S3_TEST_PRODUCTION_SOAK_OBJECT_BYTES=262144 \
S3_TEST_PRODUCTION_SOAK_CONCURRENCY=25 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php --filter test_opt_in_production_soak_keeps_memory_temp_files_and_responses_stable
```

For a quick pre-release smoke pass:

```bash
S3_TEST_LARGE_OBJECT_BYTES=8388608 \
S3_TEST_MULTIPART_PART_BYTES=2097152 \
S3_TEST_MULTIPART_PARTS=3 \
S3_TEST_CONCURRENT_OBJECTS=30 \
S3_TEST_CONCURRENT_OBJECT_BYTES=32768 \
S3_TEST_CONCURRENT_REQUESTS=15 \
S3_TEST_SOAK_ITERATIONS=40 \
vendor/bin/phpunit tests/Functional/ReliabilityStressTest.php
```

## Upgrade Notes

- Back up metadata before starting a node with this package version.
- Back up every configured storage tier, not only the default hot tier.
- Let one node complete schema migration before adding more serving nodes.
- Verify all nodes use the same tier configuration names before enabling
  lifecycle transition rules.
- Watch `s3_server_tiering_worker_results_total` and
  `s3_server_notification_queue_depth` after rollout.
- Rollback after schema migration requires a package version that understands
  schema version `11`, or a metadata restore from a pre-migration backup.

## Tagging

After verification, create an annotated tag from a clean worktree:

```bash
git status --short
git tag -a vX.Y.Z -m "Release vX.Y.Z"
```
