# Release Checklist

Use this checklist before tagging a production release. A release candidate is
not complete merely because the default PHPUnit run passes: the opt-in
integration and load profiles below are release gates for the backends being
advertised.

## Unreleased Candidate

The `develop` branch contains a substantial runtime and metadata update after
`v1.3.1`. Do not treat the branch as a tagged production release until every
gate required by the advertised deployment profile has passed.

### Required Gates Before Tagging

- Run `composer quality`, strict Composer validation, and the locked dependency
  audit on PHP 8.4 and PHP 8.5.
- Run PostgreSQL and MySQL functional suites with skipped external-driver tests
  treated as failures. PostgreSQL API profiles must load `pq` and `raphf`.
- Measure every schema migration on a representative, isolated database copy;
  stop old API and worker processes before the production migration.
- Qualify the exact local filesystem, mount options, shutdown path, backup, and
  sudden-power-loss behavior used by a single-host deployment.
- Qualify each advertised multi-host storage backend on separate hosts. Shared
  PostgreSQL or MySQL metadata alone does not qualify object storage.
- Keep generic Flysystem and unverified shared mounts out of production until
  their provider-specific delayed-write and cleanup fence has passed.
- Exercise signed path-style and virtual-hosted requests through the intended
  load balancer with exact `Host` preservation and trusted-proxy CIDRs.
- Run the long soak only after the selected storage backend and proxy profile
  satisfy their correctness gates; a soak cannot establish a missing write
  fence.
- For a strict at-rest claim, verify encrypted request spools, encrypted
  multipart staging, private temporary directories, swap, core dumps, proxy
  logs, worker memory, and cleanup of legacy plaintext multipart parts.

### Upgrade Checks

- Review cross-account grants for the new two-sided identity/resource Allow
  requirement.
- Run one `metadata:migrate` and, when database credentials are used, one
  `credentials:migrate` with DDL-capable bootstrap roles before serving.
- Abort or migrate legacy encrypted multipart uploads and drain old writers
  before the coordinated node replacement.
- Abort and restart legacy multipart uploads with explicit retention or legal
  holds, and uploads subject to current default retention, before serving with
  the new Object Lock configuration binding.
- Abort and restart every multipart upload created before exact initiation
  policy snapshots were introduced; the server cannot safely reconstruct its
  ACL, encryption, and storage-class condition context at completion.
- Inventory stored bucket and object tags for values outside the documented S3
  character, length, count, and uniqueness rules. Repair or delete invalid
  legacy rows before rollout; reads and `CopyObject` tag copies fail closed
  with `InternalError` rather than serializing corrupt metadata.
- Verify that every persisted storage tier is configured on every API node and
  that dedicated workers intentionally defer tiers they do not serve.
- Revoke older permanent OIDC-issued credentials where indefinite access is no
  longer acceptable, and prepare the issuer-scoped owner migration before
  enabling multiple issuers.
- Confirm webhook consumers tolerate duplicate delivery and that optional
  broker adapters are accepted as Best Effort.

### Current Local Evidence

The latest confirmed local `composer quality` run on PHP 8.5.11 passed 4,303
PHPUnit tests with 32,963 assertions and 232 expected opt-in or environment
skips. PHPStan passed, and PHP CS Fixer reported zero fixes across 962 files.
Strict Composer validation, the locked dependency audit, and `git diff --check`
also passed on the same current candidate. The earlier CI baseline at `54c6542`
passed all four jobs in GitHub Actions run
[38814433357](https://github.com/opsfour/s3-server/actions/runs/38814433357),
including quality, metadata backends, and the lowest and highest dependency
matrices. That run covers the host-pin and version-timestamp batch but predates
the listenerless heartbeat reader and delegated multipart-abort commit
reauthorization. Both later changes are covered by the current local quality
run, but no newer GitHub Actions result is recorded yet. The production watcher,
runtime wiring before listener admission, and qualification of the exact
read-only bind and stale replay contract on the intended Linux hosts remain
open. External PostgreSQL/MySQL execution without skips, the exact PHP 8.4
deployment profile, multi-host storage qualification, and the current-source
long soak remain separate gates.

## Released Version

`v1.3.1` is a documentation-only follow-up to the runtime-identical `v1.3.0`,
which was validated on 2026-07-26 with PHP 8.4.8:

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
| Complete default suite | 745 tests, 3,288 assertions, 9 expected skips, 0 failures; 1:52.369; 74.50 MB |
| Lowest supported dependency set | Composer audit clean; PHPStan 2.1 clean; complete suite passed without deprecations |
| Highest supported dependency set | Laravel 13.21, Symfony 8.1, AWS SDK 3.389, PHPStan 2.2, and PHPUnit 11.5 passed |
| AWS SDK operation guard | Every S3 operation exposed by AWS SDK 3.389 is routed or explicitly documented as unsupported |
| PHPStan, complete package | No errors |
| PHP CS Fixer, 396 files | No changes required |
| Composer locked dependency audit | No known security advisories |
| 256 MiB streamed PUT/GET | Passed; 58.816 seconds; 32 MB |
| 128 MiB multipart upload, 4 x 32 MiB | Passed; 35.238 seconds; 30 MB |
| 200 objects / 100 concurrent requests | Passed; 204 assertions; 8.592 seconds; 62 MB |
| Five-minute production soak | Passed; 598 assertions; 5:20.138; 44 MB |
| Full-stack soak harness smoke | Passed against Linode Flysystem plus PostgreSQL; 75 batches, 1,265 assertions; 1:03.040; 28 MB |
| Docker-isolated full-stack smoke | Passed against Linode Flysystem plus PostgreSQL; 1,675 assertions; 1:01.406; 30 MB PHPUnit; 1 GiB cgroup; no swap; no OOM; remote cleanup passed |
| Detached 12-hour full-stack gate | Passed against Linode Flysystem plus PostgreSQL; 12:00:04.186; 209,852 assertions; 1 GiB cgroup; no swap; no OOM; continuity and remote cleanup passed |
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

Before a major production release, run the same soak against remote storage and
an external metadata database for at least 12 hours. The repository Docker
runner caps the complete PHP/S3 process tree at 1 GiB without swap and
PostgreSQL at 512 MiB, mounts the package read-only, uses a unique backing
prefix, and performs remote cleanup even after an OOM kill:

```bash
scripts/run-production-soak start
scripts/run-production-soak status
```

The runner reads the ignored `.env.integration` file. Duration, batch size,
object size, concurrency, pacing, worker count, and RSS thresholds can be
overridden with the `S3_SOAK_*` environment variables in
`docker/production-soak/compose.yml`. The full-stack profile verifies every
returned object body, both health probes, temporary-file cleanup, an absolute
process-tree RSS ceiling from the first batch, and RSS growth after lazy worker
startup while exercising Flysystem and PostgreSQL.

The workload also rejects gaps longer than 120 seconds between completed
batches, including a pause immediately before loop termination. On macOS the
runner starts a launchd-managed `caffeinate` assertion for the configured
duration plus 20 minutes. Closing the lid, manually sleeping the machine, or
pausing the Docker VM can still invalidate the run; the continuity assertion
then makes the test fail instead of counting suspended wall time.

## Upgrade Notes

- Back up metadata before starting a node with this package version.
- Back up every configured storage tier, not only the default hot tier.
- Let one node complete schema migration before adding more serving nodes.
- Verify all nodes use the same tier configuration names before enabling
  lifecycle transition rules.
- Watch `s3_server_tiering_worker_results_total` and
  `s3_server_notification_queue_depth` after rollout.
- Rollback after migrating this candidate requires a package version that
  understands SQLite schema `39`, PostgreSQL schema `42`, and MySQL schema
  `45`, or a pre-migration metadata restore. The schema `14`/`15` values above
  describe only the historical `v1.3.1` release.

## Tagging

Do not tag with a dirty worktree or a pending release gate:

```bash
git status --short
git tag -a vX.Y.Z -m "Release vX.Y.Z"
```
