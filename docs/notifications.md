# Event Notifications

OpsFour S3 Server queues event notifications for HTTP(S) webhook destinations when objects are created, deleted, or copied. Delivered server access-log files also produce `ObjectCreated:Put` events in the logging target bucket. The database queue retains pending deliveries across restarts and retries failures with exponential backoff before dead-letter handling.

Webhook enqueueing requires the bucket generation captured by the operation
that produced the event. A delayed event from a deleted bucket is discarded if
that bucket name now belongs to a new generation, including a new owner.
Integrations using `S3Event`, `NotificationDispatcher::createEvent()` or
`NotificationDispatcher::dispatch()` must pass the original
`bucketGeneration` to enqueue webhooks; calls without it can still reach
internal listeners. Built-in S3 handlers pass their previously observed
generation.

Webhook POSTs can repeat. Separate workers do not lease the same pending queue
row concurrently, but a crash or timeout after the receiver has accepted a
POST can lead to a retry. Receivers should deduplicate events when repeated
delivery matters; permanently failing deliveries eventually dead-letter.

## Configure Notifications

```php
$s3->putBucketNotificationConfiguration([
    'Bucket' => 'my-bucket',
    'NotificationConfiguration' => [
        'TopicConfigurations' => [
            [
                'Id'       => 'uploads-webhook',
                'TopicArn' => 'https://api.example.com/webhooks/s3',
                'Events'   => ['s3:ObjectCreated:*'],
                'Filter'   => [
                    'Key' => [
                        'FilterRules' => [
                            ['Name' => 'prefix', 'Value' => 'uploads/'],
                            ['Name' => 'suffix', 'Value' => '.jpg'],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
```

The S3 configuration API accepts absolute HTTPS webhook URLs by default. When
`S3_NOTIFICATION_REQUIRE_HTTPS=false`, it also accepts HTTP URLs. This matches
the worker's transport policy, so a plaintext URL is rejected at configuration
time when the worker would refuse to deliver it. The API rejects SNS,
SQS, and Lambda ARNs with `NotImplemented` because the package cannot deliver
to those destinations. Previously stored ARN configurations remain readable,
but the built-in dispatcher skips them. Internal listeners and the broker
adapters below receive normalized events independently of S3 notification XML.
The XML endpoint accepts only the event names listed below. It rejects other
well-formed names with `NotImplemented` because this package does not emit
those webhook events. Internal listener patterns, including `s3:*`, have a
separate contract.
Each bucket accepts at most 256 notification configurations in one replacement
request. A request with 257 or more returns `400 InvalidArgument` and preserves
the existing configuration. The empty configuration remains valid for disabling
notifications.

## Supported Events

| Event Pattern | Triggers On |
|---------------|-------------|
| `s3:ObjectCreated:*` | Any object creation (Put, Post, Copy, CompleteMultipartUpload) |
| `s3:ObjectCreated:Put` | PutObject |
| `s3:ObjectCreated:Post` | PostObject |
| `s3:ObjectCreated:Copy` | CopyObject |
| `s3:ObjectCreated:CompleteMultipartUpload` | CompleteMultipartUpload |
| `s3:ObjectRemoved:*` | Direct or batch object removal and delete-marker creation |
| `s3:ObjectRemoved:Delete` | Permanent object or version deletion |
| `s3:ObjectRemoved:DeleteMarkerCreated` | Delete marker created by direct or batch delete, including an absent key |
| `s3:ObjectRestore:Completed` | Restore copy completed |

## Filter Rules

Filter which objects trigger notifications:

- **prefix** — Only objects whose key starts with the value
- **suffix** — Only objects whose key ends with the value

Both can be combined (AND logic).
Filter values follow S3's URL encoding: encode spaces as `+` and a literal plus
as `%2B`. The dispatcher decodes the stored value before comparing it with the
object key; GET returns the configured value unchanged. Each configuration may
have one prefix and one suffix; duplicate filter names and wildcard `*` are
rejected before replacing a working configuration.
Configurations for overlapping event types cannot have filters that match the
same key. A shared prefix is allowed when suffixes are disjoint, and identical
filters are allowed for disjoint event types.

## Webhook Payload

Notifications are delivered as HTTP POST with S3-style JSON. The payload uses
the configured notification ID and bucket owner, omits the `s3:` prefix from
`eventName`, and form-encodes object keys (spaces become `+`):

```json
{
  "Records": [
    {
      "eventVersion": "2.1",
      "eventSource": "aws:s3",
      "awsRegion": "us-east-1",
      "eventTime": "2025-03-20T12:00:00.000Z",
      "eventName": "ObjectCreated:Put",
      "userIdentity": {
        "principalId": "owner-id"
      },
      "s3": {
        "s3SchemaVersion": "1.0",
        "configurationId": "uploads-webhook",
        "bucket": {
          "name": "my-bucket",
          "ownerIdentity": {"principalId": "owner-id"},
          "arn": "arn:aws:s3:::my-bucket"
        },
        "object": {
          "key": "uploads/photo.jpg",
          "size": 1048576,
          "eTag": "d41d8cd98f00b204e9800998ecf8427e",
          "sequencer": "0000000000000001"
        }
      }
    }
  ]
}
```

Events emitted by HTTP object operations include the actual client IP and the
request/host IDs returned in the S3 response. Background events omit these
fields when no HTTP request exists. Events from versioning-enabled object
operations include the committed `versionId`; unversioned and suspended
`null` versions omit it. ObjectCreated and ObjectRemoved webhook events include
a durable hexadecimal `sequencer` from the notification queue. Compare it only
for the same bucket and key, padding shorter values with zeros on the left. Multiple matching
destinations for one event receive the same value. Later committed changes to
that key receive larger values across API nodes sharing the metadata database;
delivery itself can still be retried or arrive out of order. Sequence values
can have gaps and are not object version IDs. ObjectRestore events omit this
PUT/DELETE-only field.
Delete and restore events also omit `size` and `eTag` when those values were
not captured. A zero-byte creation or completed restore with a known object
size still includes `size: 0`. Completed restore events include
`glacierEventData.restoreEventData` with the source storage class and restored
copy's expiry time when the worker has both values.

## Internal Listeners and Broker Adapters

Applications can also listen to normalized in-process `S3Event` objects and
forward them to their own infrastructure. The built-in optional destination
adapters wrap external producer clients without adding hard Composer
dependencies:

- `RedisStreamNotificationAdapter`
- `KafkaNotificationAdapter`
- `AmqpNotificationAdapter`
- `NatsNotificationAdapter`

Example:

```php
use OpsFour\S3Server\Event\Adapter\DestinationAdapterListener;
use OpsFour\S3Server\Notification\Destination\KafkaNotificationAdapter;

$adapter = new KafkaNotificationAdapter($producer, 's3-events');
$notifications->listen('s3:ObjectCreated:*', new DestinationAdapterListener($adapter));
```

The adapters return `sent`, `retryable_failure`, or `dead_letter` results to the
listener bridge. Transport-specific retry, buffering, acknowledgements, and
broker setup stay inside the injected producer/client.
The injected client must wait for and validate broker acceptance before
returning a success value. An exception, `false`, or `null` (including a
`void` publisher method) is reported as `retryable_failure`; any other returned
value is treated as the client's positive acknowledgement. For Redis Streams,
return the successful `XADD` message ID. For Kafka, verify a delivery report;
for AMQP, verify publisher confirmation and routability; for durable NATS,
verify a JetStream publish acknowledgement. A method that only buffers a
message locally must not return a success value as proof of broker acceptance.
The listener bridge itself is in process: it logs failures but does not persist
or retry broker events, and a full listener queue can drop an event. The
persistent queue and retry schedule below apply to configured webhooks only.
Marking a broker result `retryable_failure` therefore does not itself schedule
a retry. Durable broker delivery would require a transactional outbox, which
this package does not currently implement. Broker delivery remains explicitly
Best Effort for the current release.
The bridge logs the delivery status and adapter name, but omits raw producer
errors and adapter result context because client exceptions can contain broker
credentials. Applications that need more diagnostics should log selected,
redacted fields in their producer integration.

`SimpleEventDispatcher` queues listener callbacks without waiting for an
execution slot. Its constructor keeps the existing concurrency argument and
accepts a bounded listener-task queue; when that bound is full, the event is
dropped and only sanitized metadata is logged. Call `shutdown()` during an
orderly process stop to drain callbacks, including callbacks scheduled by a
listener. Listener and logger failures do not interrupt that drain. This does
not make the in-process bridge durable: a hard process exit can still lose
queued callbacks.

### Symfony Listeners

Symfony applications can reference listener services directly from
`opsfour_s3_server.notifications.listeners`:

```yaml
services:
  App\S3\AuditS3EventListener: ~

opsfour_s3_server:
  notifications:
    listeners:
      - event: 's3:ObjectCreated:*'
        service: 'App\S3\AuditS3EventListener'
```

The bundle can also bridge matching events into Symfony's EventDispatcher with
`notifications.symfony_event_dispatcher`. See
[Symfony Integration](symfony-integration.md#symfony-event-listeners).

## Delivery Guarantees

### Persistent Queue

Configured webhook notifications are enqueued in the metadata database
(not in-memory). This means:

- Notifications survive server restarts
- Pending webhook messages remain queued across process restarts
- Multi-node Postgres/MySQL deployments share the queue

`S3_NOTIFICATION_WORKER=false` disables delivery on that server instance while
keeping enqueueing active. Keep a worker enabled on at least one instance that
uses the same metadata database; otherwise queued webhooks remain pending.

### Retry with Exponential Backoff

Failed deliveries are retried with increasing delays:

| Attempt | Delay |
|---------|-------|
| 1 | 2 seconds |
| 2 | 4 seconds |
| 3 | 8 seconds |
| 4 | 16 seconds |
| 5 | 32 seconds |
| 6 | 64 seconds |
| 7 | 128 seconds |
| 8 | 256 seconds |
| 9+ | 300 seconds (cap) |

After 10 failed attempts (configurable), the notification moves to **dead-letter** status.
Stopping a worker cancels in-flight delivery and returns the leased notification
to pending without consuming an attempt. The receiver may already have accepted
that request, so it still needs to deduplicate repeated events.

### Circuit Breaker

If a destination fails 5 consecutive times, the circuit breaker opens for 60 seconds. During cooldown, notifications for that destination are deferred without consuming retry attempts.

### Stale Lock Recovery

If the server crashes while processing a notification, the item remains in `processing` state. After 5 minutes, it's automatically reset to `pending` and retried.

## SSRF Protection

All webhook destinations are validated before delivery:

1. DNS resolution of the hostname
2. Rejection of private and special-use IP ranges, including shared, benchmarking, documentation, loopback, and link-local addresses
3. HTTPS enforcement by default, including every redirect destination
4. URL pinning to the resolved IP to prevent DNS rebinding attacks

Bracketed IPv6 URL literals are checked as IP addresses. The worker also
blocks the known 6to4, Teredo, and NAT64 transition prefixes and documentation
IPv6 ranges even when PHP's built-in public-IP flags accept them. This check
applies to literal addresses, DNS answers, and each redirect. Operator-specific
IPv6 translation prefixes cannot be identified from the address alone, so
production networks should also restrict webhook egress to approved routes.
The IPv4 guard additionally blocks `100.64.0.0/10`, `198.18.0.0/15`,
`192.0.0.0/24`, and the three documentation ranges that PHP's flags may accept.

Destinations resolving to private IPs are immediately dead-lettered.
Plaintext HTTP destinations are rejected at configuration time by default;
legacy stored HTTP destinations are dead-lettered by the worker. Keep
`S3_NOTIFICATION_REQUIRE_HTTPS=true` in production; setting it to `false`
changes only the transport requirement and does not disable destination or
redirect SSRF validation.

Delivery logs include only the destination scheme, host, and port. URL paths,
query parameters, fragments, user information, and raw exception messages are
omitted because webhook URLs and dependency errors can carry credentials. For
unexpected worker failures, the queue stores a fixed error reason instead of
the exception message.

## Monitoring

Query the notification queue directly for monitoring:

```sql
-- Pending notifications
SELECT COUNT(*) FROM s3_notification_queue WHERE status = 'pending';

-- Dead-letter items (delivery failed after max retries)
SELECT * FROM s3_notification_queue WHERE status = 'dead_letter';

-- Processing (in-flight)
SELECT COUNT(*) FROM s3_notification_queue WHERE status = 'processing';
```

Sent and dead-letter items are cleaned up at least 24 hours after their final
status was recorded. The cleanup job runs every five minutes. Pending and
in-flight items are never removed by this retention job. Rows completed by an
older package version retain their previous lease timestamp for this purpose;
that timestamp is normally five minutes after the final delivery attempt.
