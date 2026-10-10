<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Bucket\PutBucketNotificationHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class PutBucketNotificationHandlerTest extends TestCase
{
    public function test_http_destination_follows_worker_transport_policy(): void
    {
        $path = sys_get_temp_dir() . '/s3-notification-policy-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $request = function (): Request {
            $request = new Request(
                $this->createMock(Client::class),
                'PUT',
                Http::new('http://127.0.0.1/bucket?notification'),
                [],
                '<NotificationConfiguration><TopicConfiguration><Id>plain</Id>'
                    . '<Topic>http://notifications.invalid/webhook</Topic><Event>s3:ObjectCreated:*</Event>'
                    . '</TopicConfiguration></NotificationConfiguration>',
            );
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            return $request;
        };

        try {
            try {
                (new PutBucketNotificationHandler($metadata))->handleRequest($request());
                self::fail('Plaintext HTTP must be rejected when the worker requires HTTPS.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $metadata->getBucketNotification('bucket'));
            }

            self::assertSame(200, (new PutBucketNotificationHandler($metadata, false))->handleRequest($request())->getStatus());
            self::assertSame(
                ['http://notifications.invalid/webhook'],
                array_column($metadata->getBucketNotification('bucket'), 'destinationArn'),
            );
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_configuration_limit_accepts_256_and_rejects_257_before_replacement(): void
    {
        $path = sys_get_temp_dir() . '/s3-notification-limit-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $handler = new PutBucketNotificationHandler($metadata);

        $request = function (string $body): Request {
            $request = new Request(
                $this->createMock(Client::class),
                'PUT',
                Http::new('http://127.0.0.1/bucket?notification'),
                [],
                $body,
            );
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            return $request;
        };

        $entries = [];
        for ($number = 0; $number < 256; $number++) {
            $entries[] = '<TopicConfiguration><Id>n' . $number . '</Id>'
                . '<Topic>https://notifications.invalid/hook</Topic><Event>s3:ObjectCreated:Put</Event>'
                . '<Filter><S3Key><FilterRule><Name>prefix</Name><Value>p' . $number . '/</Value>'
                . '</FilterRule></S3Key></Filter></TopicConfiguration>';
        }

        try {
            $body = '<NotificationConfiguration>' . implode('', $entries) . '</NotificationConfiguration>';
            self::assertSame(200, $handler->handleRequest($request($body))->getStatus());
            self::assertCount(256, $metadata->getBucketNotification('bucket'));

            // This entry also overlaps the first one: the count guard must run first.
            $entries[] = $entries[0];
            $oversizedBody = '<NotificationConfiguration>' . implode('', $entries) . '</NotificationConfiguration>';
            self::assertLessThan(\OpsFour\S3Server\Http\RequestBody::XML_LIMIT, strlen($oversizedBody));
            try {
                $handler->handleRequest($request($oversizedBody));
                self::fail('257 notification configurations must be rejected.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('256 entries', $e->getMessage());
            }
            $stored = $metadata->getBucketNotification('bucket');
            self::assertCount(256, $stored);
            self::assertContains('n0', array_column($stored, 'id'));
            self::assertContains('n255', array_column($stored, 'id'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_duplicate_events_do_not_multiply_overlap_comparisons(): void
    {
        $path = sys_get_temp_dir() . '/s3-notification-events-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');

        $entry = static fn(string $id, string $event): string => '<TopicConfiguration><Id>' . $id . '</Id>'
            . '<Topic>https://notifications.invalid/hook</Topic>'
            . str_repeat('<Event>' . $event . '</Event>', 2000)
            . '</TopicConfiguration>';
        $body = '<NotificationConfiguration>'
            . $entry('created', 's3:ObjectCreated:Put')
            . $entry('removed', 's3:ObjectRemoved:Delete')
            . '</NotificationConfiguration>';
        self::assertLessThan(\OpsFour\S3Server\Http\RequestBody::XML_LIMIT, strlen($body));

        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket?notification'),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        try {
            self::assertSame(200, (new PutBucketNotificationHandler($metadata))->handleRequest($request)->getStatus());
            $stored = $metadata->getBucketNotification('bucket');
            self::assertCount(2, $stored);
            self::assertCount(2000, $stored[0]['events']);
            self::assertCount(2000, $stored[1]['events']);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_duplicate_or_oversized_ids_do_not_replace_existing_configuration(): void
    {
        $path = sys_get_temp_dir() . '/s3-notification-ids-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $handler = new PutBucketNotificationHandler($metadata);

        $request = function (string $body): Request {
            $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket?notification'), [], $body);
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            return $request;
        };
        $entry = static fn(string $id, string $event): string => '<TopicConfiguration><Id>' . $id . '</Id>'
            . '<Topic>https://notifications.invalid/hook</Topic><Event>' . $event . '</Event></TopicConfiguration>';

        try {
            self::assertSame(200, $handler->handleRequest($request('<NotificationConfiguration>'
                . $entry('existing', 's3:ObjectCreated:Put') . '</NotificationConfiguration>'))->getStatus());

            $duplicate = '<NotificationConfiguration>' . $entry('same', 's3:ObjectCreated:Put')
                . $entry('same', 's3:ObjectRemoved:Delete') . '</NotificationConfiguration>';
            $oversized = '<NotificationConfiguration>' . $entry(str_repeat('x', 256), 's3:ObjectCreated:Put')
                . '</NotificationConfiguration>';
            foreach ([$duplicate, $oversized] as $invalid) {
                try {
                    $handler->handleRequest($request($invalid));
                    self::fail('Invalid notification ID was accepted.');
                } catch (InvalidArgumentException $exception) {
                    self::assertSame(400, $exception->getHttpStatus());
                }
                self::assertSame(['existing'], array_column($metadata->getBucketNotification('bucket'), 'id'));
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
