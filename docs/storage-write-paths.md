# Physical Storage Write and Cleanup Boundaries

Physical object paths are referenced by object metadata, committed multipart
parts, restore and tier-transition jobs, write intents, and durable storage
garbage. A metadata mutation that removes or replaces one of those references
must stage its exact cleanup path in the same transaction. If normal cleanup
admission is exhausted, the whole metadata mutation rolls back and request
paths return S3 `SlowDown`.

`s3_storage_cleanup_capacity.retained_count` counts every
`s3_storage_garbage` row except `part_write_intent`. Normal cleanup can use the
first 900,000 rows. A row with immutable `recovery_admission=true` can use the
remaining protected band through 1,000,000, and is created only by
`DurableStorageDelete` after `deleteObjectByPath()` has already failed.
Multipart part write intents keep their independent
`s3_write_intent_capacity(kind='part')` limit.

At the hard cleanup ceiling, the failed physical delete is never reported as
successful. The process increments the recovery rejection counter and reports
bucket, storage tier, bounded origin token, SHA-256 path identifier, retained
cleanup count when readable, and exception classes without logging the raw
storage path.
Run the stopped-profile `storage:inventory` procedure in
[deployment.md](deployment.md#offline-orphan-inventory) to find physical files
that have no remaining metadata, job, intent, or garbage reference. Supply the
configured default write tier when more than one local tier is mapped so
legacy and in-progress restore references cannot be assigned to the wrong
physical root.

Collectors and discard paths do not consume capacity. Deleting a counted row
or changing it to `part_write_intent` decrements the cleanup counter even when
the queue began above a supported boundary. Direct SQL inserts, deletes, and
operation transitions are subject to the same database triggers as package
callers.
