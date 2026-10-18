# Production Feature Roadmap

This roadmap keeps the product contract narrow and separates shipped code from
deployment profiles that have been qualified for production use.

## Status Terms

These terms are used across the documentation:

| Status | Meaning |
|--------|---------|
| **Implemented** | The capability exists in the package and has automated coverage. This alone is not a production claim. |
| **Supported** | The capability is part of the maintained public contract for the documented versions and configuration. |
| **Qualified** | The exact deployment profile has passed its required release gates. |
| **Experimental / test-only** | The capability may be used for development, integration, or evaluation, but is not approved for production. |
| **Out of scope** | The capability is not part of the current product contract. |

An integration can be implemented without being supported or qualified. A
deployment profile is qualified only when its own gates pass; optional profiles
do not block releases that do not advertise them.

## Current Target Contract

The initial production target is:

- Linux on one host;
- one S3 API process;
- a persistent local filesystem for object bytes;
- SQLite, PostgreSQL, or MySQL for metadata;
- the standalone CLI, Laravel integration, or Symfony integration; and
- direct TLS or a generic reverse proxy or load balancer that preserves the
  signed `Host` header exactly, including its port.

The documented S3 operation set, SigV4 authentication, policies, ACLs,
versioning, multipart uploads, checksums, quotas, lifecycle processing,
webhook delivery, metrics, SSE-S3, and SSE-C remain part of the package. Refer
to the feature-specific guides for their exact constraints.

Memory storage is test-only. Flysystem storage, remote filesystems, shared
filesystems such as NFS or EFS, multiple hosts, and multiple API processes are
experimental until the exact profile has its own qualification record.

## Now

- Finish the universal release gates for the current target contract on PHP
  8.4 and PHP 8.5.
- Qualify local filesystem behavior on Linux, including restart, disk-full,
  cleanup, backup and restore, and sudden process termination.
- Run PostgreSQL and MySQL integration and migration profiles without hidden
  skips. Keep SQLite covered by the default suite.
- Verify the standalone, Laravel, and Symfony entry points use the same runtime
  and security defaults.
- Verify signed path-style and virtual-hosted requests through a generic reverse
  proxy with exact `Host` preservation and trusted-proxy configuration.
- Close correctness, security, and recovery bugs in the currently documented S3
  surface. Keep unsupported AWS operations explicit and fail closed.
- Publish a release only when the [release checklist](release-checklist.md) is
  complete for this target contract.

## Later

These profiles may be qualified after the initial target is stable. Each needs
its own evidence and only then becomes an advertised production profile:

- multiple API processes on one host;
- a specific remote object-storage provider through Flysystem;
- a specific shared filesystem and mount configuration;
- multiple hosts with shared metadata and qualified shared object storage;
- load and soak profiles beyond the bounded 12-hour single-host release gate;
  and
- strict at-rest claims that include request spools, multipart staging, swap,
  core dumps, logs, and backups.

Work on these profiles must remain isolated from the universal product contract.
Passing a soak does not qualify storage semantics, and sharing PostgreSQL or
MySQL metadata does not qualify shared object storage.

## Out of Current Scope

- Generic production approval for arbitrary Flysystem adapters or arbitrary
  NFS, EFS, SMB, FUSE, or other shared mounts.
- Built-in site replication and geo replication.
- A transactional outbox for Redis, Kafka, AMQP, or NATS adapters; these remain
  Best Effort in-process integrations while HTTP(S) webhooks use the durable
  queue.
- AWS STS Query API compatibility.
- AWS KMS emulation. `aws:kms` requests continue to fail closed unless a real
  adapter is provided.
- AWS-specific inventory, analytics, S3 Express, metadata tables, object
  annotations, Object Lambda, and Torrent APIs.
- Performance or scale claims without reproducible measurements for the exact
  qualified profile.

New work belongs in **Now** only when it is required by the current target
contract or fixes a defect in the documented surface. Everything else must have
a concrete deployment need and a profile-specific acceptance gate before it is
moved forward.
