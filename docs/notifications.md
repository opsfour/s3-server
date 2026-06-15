# Event Notifications

OpsFour S3 Server delivers event notifications to webhook endpoints when objects are created, deleted, or copied. Notifications are persisted in a database queue with guaranteed delivery, retry with exponential backoff, and dead-letter handling.

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

## Supported Events

| Event Pattern | Triggers On |
|---------------|-------------|
| `s3:ObjectCreated:*` | Any object creation (Put, Post, Copy, CompleteMultipartUpload) |
| `s3:ObjectCreated:Put` | PutObject |
| `s3:ObjectCreated:Copy` | CopyObject |
| `s3:ObjectCreated:CompleteMultipartUpload` | CompleteMultipartUpload |
| `s3:ObjectRemoved:*` | Any deletion |
| `s3:ObjectRemoved:Delete` | DeleteObject |
| `s3:ObjectRemoved:DeleteMarkerCreated` | Delete marker created (versioned bucket) |

## Filter Rules

Filter which objects trigger notifications:

- **prefix** — Only objects whose key starts with the value
- **suffix** — Only objects whose key ends with the value

Both can be combined (AND logic).

## Webhook Payload

Notifications are delivered as HTTP POST with JSON body following the standard S3 event notification format:

```json
{
  "Records": [
    {
      "eventVersion": "2.1",
      "eventSource": "aws:s3",
      "awsRegion": "us-east-1",
      "eventTime": "2025-03-20T12:00:00.000Z",
      "eventName": "s3:ObjectCreated:Put",
      "userIdentity": {
        "principalId": "owner-id"
      },
      "s3": {
        "bucket": {
          "name": "my-bucket",
          "arn": "arn:aws:s3:::my-bucket"
        },
        "object": {
          "key": "uploads/photo.jpg",
          "size": 1048576,
          "eTag": "d41d8cd98f00b204e9800998ecf8427e"
        }
      }
    }
  ]
}
```

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

Notifications are enqueued in the metadata database (not in-memory). This means:

- Notifications survive server restarts
- No messages lost on crash or deployment
- Multi-node Postgres/MySQL deployments share the queue

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

### Circuit Breaker

If a destination fails 5 consecutive times, the circuit breaker opens for 60 seconds. During cooldown, notifications for that destination are deferred without consuming retry attempts.

### Stale Lock Recovery

If the server crashes while processing a notification, the item remains in `processing` state. After 5 minutes, it's automatically reset to `pending` and retried.

## SSRF Protection

All webhook destinations are validated before delivery:

1. DNS resolution of the hostname
2. Rejection of private/reserved IP ranges (RFC 1918, RFC 5737, loopback, link-local)
3. URL pinning to the resolved IP to prevent DNS rebinding attacks

Destinations resolving to private IPs are immediately dead-lettered.

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

Dead-letter items are automatically cleaned up after 24 hours.
