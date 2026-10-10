<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class DeleteMarkerNotificationTest extends TestCase
{
    public function test_direct_and_batch_delete_emit_marker_event_even_for_absent_keys(): void
    {
        $path = sys_get_temp_dir() . '/s3-delete-marker-events-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', 'Enabled');
        $metadata->putBucketNotification('bucket', [[
            'id' => 'marker-events',
            'events' => ['s3:ObjectRemoved:DeleteMarkerCreated'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/markers',
            'filterRules' => null,
        ]]);
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $tiers = StorageTierRegistry::single($storage);
        $notifications = new NotificationDispatcher($metadata);
        $received = [];
        $notifications->listen('s3:ObjectRemoved:*', static function (S3Event $event) use (&$received): void {
            $received[] = [$event->name, $event->key];
        });

        try {
            $direct = $this->request('DELETE', 'http://127.0.0.1/bucket/direct-missing');
            $direct->setAttribute('s3.key', 'direct-missing');
            self::assertSame(204, (new DeleteObjectHandler($metadata, $tiers, $notifications))->handleRequest($direct)->getStatus());

            $batch = $this->request(
                'POST',
                'http://127.0.0.1/bucket?delete',
                '<Delete><Object><Key>batch-missing</Key></Object></Delete>',
            );
            self::assertSame(200, (new DeleteObjectsHandler($metadata, $tiers, $notifications))->handleRequest($batch)->getStatus());
            $notifications->shutdown();

            self::assertSame([
                ['s3:ObjectRemoved:DeleteMarkerCreated', 'direct-missing'],
                ['s3:ObjectRemoved:DeleteMarkerCreated', 'batch-missing'],
            ], $received);
            self::assertSame(
                ['batch-missing', 'direct-missing'],
                array_map(static fn($marker): string => $marker->key, $metadata->listObjectVersions('bucket')['deleteMarkers']),
            );
            $queued = $metadata->dequeueNotifications(10);
            self::assertSame(['direct-missing', 'batch-missing'], array_column($queued, 'key_name'));
            self::assertSame([
                's3:ObjectRemoved:DeleteMarkerCreated',
                's3:ObjectRemoved:DeleteMarkerCreated',
            ], array_column($queued, 'event_name'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $uri, string $body = ''): Request
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, $method, Http::new($uri), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
