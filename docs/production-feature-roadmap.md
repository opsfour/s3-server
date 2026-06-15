# Production Feature Roadmap

This document tracks production-grade features that go beyond the core S3
object API. The goal is to keep product decisions explicit: each feature should
be either implemented, deliberately deferred, or scoped as an external
integration.

## Current Baseline

The package already covers the core S3-compatible surface used by common AWS
SDK clients:

- Object CRUD, range reads, metadata, tags, checksums, conditional requests
- Multipart upload, multipart copy, abort/list parts/list uploads
- Bucket operations, ACLs, bucket policies, Public Access Block, CORS, website
- Versioning, object versions, delete markers
- Object Lock configuration, retention, legal hold
- Bucket encryption configuration, SSE-S3, SSE-C, `aws:kms`-compatible defaults
- Lifecycle configuration parsing/storage
- Bucket notification configuration and webhook delivery queue
- Filesystem, Flysystem, memory storage backends
- Reliability coverage for concurrency, large objects, restart/stale behavior,
  external S3-compatible storage, and opt-in soak testing

## Priority 1: Operational Runtime

Supported production-grade scope for this roadmap:

- [x] Runtime observability: Prometheus metrics endpoint and request counters.
- [x] Quotas: global config, persistent per-account overrides, concurrent
  enforcement.
- [x] Quota management interface for operators.
- [x] Lifecycle execution hardening for deterministic rule execution.
- [x] Lifecycle execution hardening for large-bucket 24/7 operation.
- [x] Internal event listener API with webhook delivery as one adapter.
- [x] Notification delivery hardening and optional destination adapters.
- [x] External IAM/OIDC/Keycloak credential issuer/admin plane.
- [x] Policy-engine compatibility audit and documented matrix.
- [x] Physical tier movement and RestoreObject support for configured storage
  tiers.

### 1. Metrics and Health for 24/7 Operation

Status: In progress.

Need:

- [x] Prometheus-compatible metrics endpoint.
- [x] Counters for requests by operation/status/error code.
- [x] Request duration sum/count by operation/status.
- [x] Latency histograms by operation.
- [x] Storage backend errors by driver.
- [x] Full metadata backend errors and slow operation counters.
- [x] Metadata queue stats lookup covered by observed metadata store.
- [x] Notification queue depth, retry count, dead-letter count.
- [x] Lifecycle scan duration and object action counts.
- [x] Amp worker pool health where available.

Acceptance:

- Operators can alert on error rate, p95/p99 latency, queue backlog, stale
  lifecycle runner, temp-file growth, and process RSS growth.

### 2. Quotas

Status: In progress.

Need:

- [x] Per-owner and per-bucket quota model.
- [x] Account-specific quota definitions keyed by `ownerId`.
- [x] Provider fallback order:
  account-specific metadata quota -> global config/env quota -> unlimited.
- Quota dimensions:
  - [x] max buckets per owner
  - [x] max objects per bucket
  - [x] max bytes per bucket
  - [x] optional max bytes per owner
- [x] Enforcement on `CreateBucket`, `PutObject`, `CopyObject`, multipart complete,
  and versioned writes.
- [x] Config/env interface for quota definitions:
  `S3_QUOTA_MAX_BUCKETS_PER_OWNER`,
  `S3_QUOTA_MAX_OBJECTS_PER_BUCKET`,
  `S3_QUOTA_MAX_BYTES_PER_BUCKET`,
  `S3_QUOTA_MAX_BYTES_PER_OWNER`.
- [x] Persistent metadata table for per-account quota overrides.
- [x] Correct accounting for overwrite deltas and versioned writes.
- [x] Operator CLI for changing quota definitions without restart.
- [x] HTTP/Admin runtime API for changing quota definitions without restart.
- [x] Explicit lifecycle-delete and delete-marker quota regression coverage.

Acceptance:

- [x] Exceeding quota returns a stable S3-style `QuotaExceeded` error.
- [x] Quota accounting remains correct under concurrent writes.
- [x] Tests cover versioning and concurrency.
- [x] Tests cover copy and multipart complete quota edge cases.
- [x] Tests cover shared `ownerId` across multiple credentials and
  account-specific override of global fallback quotas.

## Priority 2: Lifecycle and Object Management

### 3. Lifecycle Executor Hardening

Status: Production hardening implemented; deployment-specific interval, batch,
and action-budget tuning should be set per installation.

Need:

- [x] Confirm lifecycle runner is always wired for standalone and Laravel entry
  points.
- [x] Add deterministic tests for:
  - [x] current object expiration
  - [x] noncurrent version expiration
  - [x] abort incomplete multipart upload
  - [x] prefix filters
  - [x] delete marker behavior
- [x] Add deterministic tests for tag filters.
- [x] Prevent overlapping lifecycle runner executions.
- [x] Apply `Filter.And` prefixes during lifecycle execution.
- [x] Apply tag filters during current-object, noncurrent-version, and delete
  marker lifecycle execution.
- [x] Keep tag-filtered abort-multipart rules conservative until incomplete MPU
  tags are persisted.
- [x] Add bounded scan batching and per-run action backpressure.
- [x] Add configurable lifecycle interval, batch size, and max actions per run.
- [x] Add metadata-backed lifecycle lease so only one node runs a lifecycle
  sweep at a time.
- [x] Add persistent checkpointing for resumable multi-node sweeps.
- [x] Add lifecycle sweep status, duration, running, last-success, and action
  metrics.
- [x] Add broader lifecycle structured logs.
- [x] Add safe multi-node locking using metadata backend primitives.

Acceptance:

- Lifecycle actions are idempotent.
- Multiple server nodes cannot process the same lifecycle sweep destructively.
- Large buckets are scanned incrementally without blocking request handling.
- One lifecycle sweep cannot perform unbounded destructive work.
- Lifecycle sweeps resume large backlogs from durable per-rule/action cursors.

### 4. Tiering

Status: Physical tiering and restore accepted for implementation. Metadata-only
transitions remain the first compatibility layer, but production scope now
includes safe data movement between configured storage tiers and temporary
restore copies for cold tiers.

Need:

- [x] Introduce a storage-tier registry:
  - [x] named tiers (`STANDARD`, `STANDARD_IA`, `GLACIER`, `DEEP_ARCHIVE`, custom)
  - [x] one storage backend per tier
  - [x] explicit hot/readable vs cold/restore-required semantics
- [x] Add object placement metadata:
  - [x] current storage tier
  - [x] physical storage path for the current tier
  - [x] transition status and last transition error
  - [x] restored hot-copy path and expiry timestamp
- [x] Add durable transition job storage with retry/dequeue state.
- [x] Apply lifecycle `Transition` and `NoncurrentVersionTransition` rules by
  enqueueing durable transition jobs.
- [x] Implement copy-verify-commit data movement:
  - [x] copy from source tier to target tier
  - [x] verify size and simple ETag-compatible MD5 integrity
  - [x] atomically switch metadata to target tier
  - [x] delete the old physical object only after commit
- [x] Preserve read behavior during transition.
- [x] Add `RestoreObject` API for cold tiers:
  - [x] parse restore XML
  - [x] create durable restore job
  - [x] expose `x-amz-restore` headers
  - [x] return `InvalidObjectState` for cold, non-restored reads
  - [x] expire restored hot copies after the requested days
- [x] Add retry, dead-letter, background processing, and structured logs for
  transition and restore workers.
- [x] Cover versioned objects, object lock/retention, quota accounting, delete
  races, overwrite races, and lifecycle interactions.

Recommendation:

- Implement in slices: metadata model first, then tier registry, then transition
  jobs, then restore API/worker. Never delete source data before target data is
  verified and metadata is committed.

## Priority 3: Identity and Policy

### 5. External IAM / OIDC / Keycloak

Status: Implemented for the supported production scope. SigV4 auth, local
credential providers, external identity mapping, RS256 JWT validation, runtime
admin HTTP APIs, and STS-style temporary SigV4 credentials exist. The actual
AWS STS Query API remains optional compatibility work.

Need:

- [x] Define an external identity provider contract.
- [x] Add an OIDC/Keycloak claim mapper for owner IDs, groups, policy names,
  and allowed bucket prefixes.
- [x] Issue regular SigV4 access key/secret pairs from authenticated external
  identities.
- [x] Keep S3 data-plane requests SigV4-only for standard AWS SDK compatibility.
- [x] Enforce credential revocation through the backing credential provider
  without server restart.
- [x] Support Keycloak/OIDC token validation for management APIs or STS-like
  credential issuance.
- [x] Add runtime admin APIs for issuing/revoking external credentials.
- [x] Optional STS-style temporary credentials:
  - external-identity assume-role style flow
  - temporary access key/secret/session token
  - expiration and revocation
- [ ] AWS STS Query API compatibility (`AssumeRole`, XML responses) if clients
  require the actual STS API surface.
- [x] Map Keycloak claims/groups to:
  - owner ID
  - tenant
  - named policy references
  - allowed bucket prefixes
- [x] Persist credential `policyNames` and resolve them through the
  metadata-backed named policy registry during authorization.

Recommendation:

- Keep S3 data-plane SigV4.
- Add an OIDC/Keycloak-backed credential issuer/admin plane rather than trying
  to replace SigV4 with bearer tokens for normal S3 clients.

Acceptance:

- [x] Standard AWS SDK clients still use SigV4.
- [x] Keycloak users/groups can be mapped to scoped S3 credential metadata.
- [x] Credential revocation is enforced without server restart.
- [x] Keycloak/OIDC users can request credentials through a runtime admin API.
- [x] Temporary credentials expire automatically without a separate cleanup job.

### 6. Policy Engine Expansion

Status: Implemented for the supported production scope. A compatibility matrix
documents supported principals, actions, resources, condition operators, and
condition keys. Unsupported condition operators or keys fail closed.

Need:

- [x] Audit supported IAM condition keys.
- [x] Add tests for principal matching, source IP, TLS, user agent, object tags,
  prefix restrictions, and explicit deny precedence.
- [x] Add account-level identity policies keyed by `ownerId`.
- [x] Add metadata-backed named policies attachable through credential
  `policyNames`.
- [x] Document unsupported IAM actions/condition keys.

Acceptance:

- [x] Policy compatibility matrix exists.
- [x] Unsupported conditions fail closed or are explicitly rejected.
- [x] Explicit deny wins across account policies, named credential policies, and
  bucket policies.

## Priority 4: Notifications and Eventing

### 7. Event Listener API

Status: Implemented. In-process callbacks, Laravel events, PSR-style event
dispatchers, generic queue-job forwarding, and the durable webhook queue adapter
are available.

Need:

- [x] Add internal event bus/listener interface for in-process callbacks.
- Event coverage:
  - [x] object created
  - [x] object removed
  - object restored if restore is ever implemented
  - [x] multipart completed/aborted
  - [x] lifecycle action applied
- [x] Dispatch event objects after metadata commit for object, multipart, and
  lifecycle action paths.
- Allow applications to register listeners for custom delivery:
  - [x] Laravel events
  - [x] PSR/event-dispatcher
  - [x] in-process callbacks
  - [x] queue jobs
- [x] Keep webhook delivery as the durable compatibility adapter.

Recommendation:

- Yes, events are the right primitive. The S3 notification XML can remain the
  compatibility layer, while applications can listen to normalized internal
  events and send SNS/SQS/Kafka/webhooks themselves.

Acceptance:

- [x] Event listeners never block the S3 response path unless explicitly
  configured.
- [x] Failed listeners are isolated and observable.
- [x] Webhook delivery continues to use durable retry queue.

### 8. Additional Notification Destinations

Status: Implemented. Webhook delivery exists, internal event adapters exist,
queue/dead-letter observability is implemented, and durable destination
adapters can be added against a stable contract.

Need:

- Optional adapters:
  - [x] Laravel queue via generic queue-job listener adapter
  - [x] Redis stream adapter implementation
  - [x] Kafka adapter implementation
  - [x] AMQP adapter implementation
  - [x] NATS adapter implementation
- [x] Queue depth, retry, sent, blocked, and dead-letter metrics.
- [x] Structured delivery logs for retry/dead-letter/circuit-breaker state.
- [x] Destination adapter contracts for durable external destinations.
- [x] Optional Redis/Kafka/AMQP/NATS adapter implementations without hard
  Composer dependencies.

Recommendation:

- Implement only after the listener API, so destinations are plugins/adapters
  rather than hard-coded into object handlers.

## Priority 5: Advanced / Deferred

### 9. Replication

Status: Not implemented.

Need only if this server must replicate between sites itself.

Recommendation:

- Defer unless there is a real HA/geo-replication requirement. If Flysystem
  points at Linode/S3 or another replicated backend, the underlying storage may
  already provide the desired durability.

### 10. Inventory, Analytics, Metrics Config APIs

Status: Unsupported.

Recommendation:

- Keep unsupported for now. Operational metrics should be implemented as server
  observability, not AWS inventory/analytics compatibility.

### 11. S3 Express, Metadata Tables, Object Lambda, Torrent, Restore

Status: Unsupported.

Recommendation:

- Keep unsupported. These are AWS-specific control-plane or specialized
  features and are not required for a production S3-compatible object server.

## Suggested Implementation Order

1. Metrics and health endpoint.
2. Quotas with strict concurrency tests.
3. Lifecycle executor hardening and tests.
4. Event listener API, then webhook as a listener.
5. External IAM/OIDC credential issuer for Keycloak.
6. Policy engine compatibility audit.
7. Optional notification destination adapters.
8. Tiering or replication only if a concrete deployment needs them.

## Testing Requirements

Every feature must include:

- AWS SDK functional tests where the SDK exposes the API.
- Unit tests for policy/accounting/selection logic.
- Concurrency tests for quota, lifecycle, and event enqueue paths.
- Restart/stale tests where durable queues or background runners are involved.
- Optional external integration profile if a real service is required.
