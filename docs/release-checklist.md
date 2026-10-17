# Release Checklist

Use this checklist before tagging a release. The status terms and current
production target are defined in the
[production feature roadmap](production-feature-roadmap.md).

## Universal Gates

These gates apply to every production release:

- [ ] Run the complete quality suite on PHP 8.4 and PHP 8.5:

  ```bash
  composer quality
  composer validate --strict
  composer audit --locked
  git diff --check
  ```

- [ ] Test the lowest and highest supported Composer dependency sets.
- [ ] Review every skip in the release run. Environment-dependent tests required
  by the advertised profile must run; treat their skips as failures.
- [ ] Verify the AWS SDK operation guard and confirm every SDK operation is
  routed or explicitly documented as unsupported.
- [ ] Run endpoint tests for authentication, authorization, malformed input,
  limits, cancellation, concurrent mutation, restart, and cleanup paths.
- [ ] Verify startup validation, `/.live`, `/.health`, graceful shutdown, and
  forced termination.
- [ ] Verify migrations from the latest tagged release against representative,
  disposable database copies. Stop old API and worker processes before running
  production DDL.
- [ ] Review the changelog, upgrade notes, configuration reference, operation
  list, and support matrix against the release candidate.
- [ ] Confirm the release commit is clean and immutable before tagging.

## Current Target Profile

The initial production profile adds these gates:

- [ ] Run the complete functional suite with persistent local filesystem
  storage on Linux and one API process.
- [ ] Exercise restart, abrupt process termination, disk-full behavior,
  temporary-file recovery, orphan cleanup, backup, and restore.
- [ ] Confirm the filesystem and mount support atomic rename. Do not claim
  sudden-power-loss durability until file and directory synchronization is
  implemented and qualified.
- [ ] Run the SQLite profile.
- [ ] Run PostgreSQL and MySQL integration, contention, recovery, and migration
  profiles against real disposable servers with required tests unable to skip.
  PostgreSQL API profiles must load `pq` and `raphf`.
- [ ] Run the standalone, Laravel, and Symfony entry-point profiles.
- [ ] Exercise signed path-style and virtual-hosted requests directly and
  through a generic reverse proxy or load balancer. Preserve `Host` exactly,
  including the port, and validate trusted-proxy CIDRs.
- [ ] Run bounded large-object, multipart, concurrent request, S3 Select
  expansion, and soak profiles sized for the intended first deployment.
- [ ] Verify access-log, metrics, notification, lifecycle, quota, encryption,
  and cleanup behavior under failure.

## Profile-Specific Gates

Run this section only for profiles advertised by the release. Record the exact
software versions, configuration, limits, and results with the release
artifacts. A pass for one provider, filesystem, or topology does not transfer to
another.

### Multiple API Processes on One Host

- [ ] Test the exact process count with the selected metadata backend and local
  temporary directories.
- [ ] Verify interprocess locks, spool budgets, lifecycle and queue leases,
  graceful shutdown, and forced worker termination.

### Remote Storage Through Flysystem

- [ ] Test the exact adapter and provider with real credentials in an isolated
  bucket.
- [ ] Establish provider-specific behavior for late writes, multipart abort,
  exact-path cleanup, timeouts, retries, and ambiguous completion.
- [ ] Measure local staging capacity and worker limits under cancellation and
  provider failure.
- [ ] Keep the profile experimental if these correctness gates are incomplete,
  regardless of soak duration.

### Shared Filesystem or Multiple Hosts

- [ ] Test the exact filesystem, service, mount options, kernel, and topology on
  separate hosts.
- [ ] Verify atomic rename, locking, cache visibility, close-to-open behavior,
  failover, reconnect, and cleanup races.
- [ ] Verify that shared PostgreSQL or MySQL metadata cannot expose bytes before
  they are durable and visible on every serving host.

### Strict Encryption at Rest

- [ ] Verify encryption of object data, request spools, multipart parts,
  assembly files, copy and checksum staging, swap, core dumps, logs, and
  backups.
- [ ] Inventory and resolve legacy plaintext temporary or multipart data before
  making the claim.

## Upgrade Checks

- [ ] Back up metadata and every configured storage tier.
- [ ] Run one `metadata:migrate` and, when database credentials are used, one
  `credentials:migrate` with DDL-capable bootstrap roles before serving.
- [ ] Review cross-account grants for the two-sided identity and resource Allow
  requirement.
- [ ] Abort or migrate legacy multipart uploads whose encryption, retention,
  Object Lock, ACL, storage class, or policy snapshot cannot be reconstructed.
- [ ] Inventory legacy bucket and object tags and repair values outside the
  documented validation rules.
- [ ] Verify that every persisted storage tier exists in the new configuration.
- [ ] Prepare issuer-scoped owner migration before enabling multiple OIDC
  issuers and revoke indefinite credentials that should become temporary.
- [ ] Confirm webhook consumers accept duplicate delivery and operators
  understand that optional broker adapters are Best Effort.
- [ ] Verify rollback compatibility with the migrated schema or keep a tested
  pre-migration restore path.

## Evidence and Tagging

Store release evidence outside the maintained product documentation so static
test counts and old environment results cannot be mistaken for current proof.
The evidence should identify the commit, PHP and dependency versions, backend
versions, operating system, exact profile, commands, skips, duration, and
result.

Do not tag with a dirty worktree or a pending required gate:

```bash
git status --short
git tag -a vX.Y.Z -m "Release vX.Y.Z"
```
