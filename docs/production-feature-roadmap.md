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

The current production target is:

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

Other local filesystem types and mount configurations remain supported only to
the extent documented by their guides; they need their own deployment
qualification before they are advertised as a production profile.

## Qualified in v2.0.0

The exact release-soak profile used PHP 8.4, SQLite metadata, one API process,
and one Linux host with its local XFS mount. It completed the 12-hour continuity
and memory gate plus restart, disk-full, cleanup, backup and restore, and sudden
process-termination gates. Separate integration gates covered PostgreSQL and
MySQL metadata, the standalone, Laravel, and Symfony entry points, and a
generic reverse proxy that preserves the signed `Host` header. These separate
gates do not broaden the qualified storage topology, and the release does not
claim sudden host power-loss durability.

## Now

- Maintain the qualified target and close correctness, security, and recovery
  defects in its documented S3 surface.
- Keep unsupported AWS operations explicit and fail closed.
- Apply the [release checklist](release-checklist.md), including the current
  target-profile gates, to every release.
- Keep optional deployment profiles in **Later** until they have a concrete
  product need and their own qualification record.

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
