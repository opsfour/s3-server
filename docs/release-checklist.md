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
- [ ] Verify a second API process cannot acquire the same local storage root,
  orderly shutdown releases ownership, and a failed shutdown keeps ownership
  until process exit.
- [ ] Exercise restart, abrupt process termination, disk-full behavior,
  temporary-file recovery, orphan cleanup, backup, and restore.
- [ ] With API and worker processes stopped, restore a matched metadata and
  storage backup to the same canonical absolute tier roots, map every
  referenced local tier explicitly, run
  `storage:audit`, and require exit status 0 before accepting writes. Run the
  separate recovery and garbage gates for transient or cleanup references.
- [ ] Confirm the filesystem and mount support create-only hard links plus file
  and directory `fsync`. Run the abrupt-power-loss/reboot recovery gate on the
  exact filesystem and mount before claiming sudden-power-loss durability.
- [ ] Run the SQLite profile.
- [ ] Run PostgreSQL and MySQL integration, contention, recovery, and migration
  profiles against real disposable servers with required tests unable to skip.
  PostgreSQL API profiles must load `pq` and `raphf`.
- [ ] Run the standalone, Laravel, and Symfony entry-point profiles.
- [ ] Exercise signed path-style and virtual-hosted requests directly and
  through a generic reverse proxy or load balancer. Preserve `Host` exactly,
  including the port, and validate trusted-proxy CIDRs.
- [ ] Run bounded large-object, multipart, concurrent request, and S3 Select
  expansion profiles, then one uninterrupted 12-hour local-filesystem soak on
  Linux with one API process and the metadata backend selected for the release
  evidence. Do not multiply the 12-hour run across every metadata backend;
  their integration, contention, recovery, and migration gates remain
  separate.
- [ ] Verify access-log, metrics, notification, lifecycle, quota, encryption,
  and cleanup behavior under failure.

## Later Profiles

Multiple API processes, multiple hosts, remote storage, shared filesystems,
strict whole-system encryption-at-rest claims, and larger scale profiles are
not gates for this release. Promote one only for a concrete deployment need,
define its own acceptance evidence, and keep it experimental until that exact
profile passes. See the [roadmap](production-feature-roadmap.md).

## Out of Current Scope

Do not use the current release checklist to imply generic approval for
arbitrary Flysystem adapters, shared mounts, cluster topologies, or performance
and scale levels that were not measured for the exact deployment.

## Upgrade Checks

- [ ] Back up metadata and every configured storage tier.
- [ ] Run one `metadata:migrate` and, when database credentials are used, one
  `credentials:migrate` with DDL-capable bootstrap roles before serving.
- [ ] Review cross-account grants for the two-sided identity and resource Allow
  requirement.
- [ ] Abort or migrate legacy multipart uploads whose encryption, retention,
  Object Lock, ACL, storage class, or policy snapshot cannot be reconstructed.
- [ ] Complete or abort multipart uploads created before destination ownership
  snapshots before enabling `BucketOwnerPreferred`; marker-less uploads retain
  legacy behavior and never infer a Preferred ownership transfer.
- [ ] If a malformed ownership snapshot makes listing or completion fail closed,
  abort the upload by its known upload ID. Repair metadata only by replacing it
  with a fully validated snapshot.
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
