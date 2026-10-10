<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationProcessor;

final class NotificationDeliveryFunctionalTest extends S3FunctionalTestCase
{
    private static string|false $previousWorkerSetting = false;

    public static function setUpBeforeClass(): void
    {
        self::$previousWorkerSetting = getenv('S3_NOTIFICATION_WORKER');
        putenv('S3_NOTIFICATION_WORKER=false');

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$previousWorkerSetting === false) {
            putenv('S3_NOTIFICATION_WORKER');
        } else {
            putenv('S3_NOTIFICATION_WORKER=' . self::$previousWorkerSetting);
        }
    }

    public function test_signed_s3_requests_reach_persisted_webhook_queue_and_deliver_after_server_restart(): void
    {
        $bucket = 'notification-delivery-' . bin2hex(random_bytes(4));
        $destination = 'https://93.184.216.34/webhook';

        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => $bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [[
                    'Id' => 'signed-delivery',
                    'TopicArn' => $destination,
                    'Events' => ['s3:ObjectCreated:Put'],
                    'Filter' => ['Key' => ['FilterRules' => [[
                        'Name' => 'prefix',
                        'Value' => 'logs/',
                    ]]]],
                ]],
            ],
        ]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'other/ignored.txt', 'Body' => 'ignored']);
        $putResult = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'logs/space + sign.txt', 'Body' => 'delivered']);

        $databasePath = self::$storagePath . '/metadata.sqlite';
        $beforeRestart = new SqliteMetadataStore($databasePath);
        $beforeRestart->initialize();
        self::assertSame(['pending' => 1], $beforeRestart->getNotificationQueueStats());

        self::restartServer();
        $afterRestart = new SqliteMetadataStore($databasePath);
        $afterRestart->initialize();
        self::assertSame(['pending' => 1], $afterRestart->getNotificationQueueStats());
        self::$s3->headObject(['Bucket' => $bucket, 'Key' => 'logs/space + sign.txt']);

        $client = new RecordingDeliveryHttpClient();
        $processor = new NotificationProcessor(
            $afterRestart,
            pollInterval: 0.02,
            httpClient: new HttpClient($client, []),
        );
        try {
            $processor->start();
            $deadline = microtime(true) + 3.0;
            do {
                if ($afterRestart->getNotificationQueueStats() === ['sent' => 1]) {
                    break;
                }
                \Amp\delay(0.02);
            } while (microtime(true) < $deadline);
        } finally {
            $processor->stop();
        }

        self::assertSame(['sent' => 1], $afterRestart->getNotificationQueueStats());
        self::assertCount(1, $client->requests);
        self::assertSame('POST', $client->requests[0]['method']);
        self::assertSame($destination, $client->requests[0]['url']);
        self::assertSame('application/json', $client->requests[0]['contentType']);
        $payload = json_decode($client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ObjectCreated:Put', $payload['Records'][0]['eventName']);
        self::assertSame('signed-delivery', $payload['Records'][0]['s3']['configurationId']);
        self::assertSame($bucket, $payload['Records'][0]['s3']['bucket']['name']);
        self::assertSame('logs%2Fspace+%2B+sign.txt', $payload['Records'][0]['s3']['object']['key']);
        $putSequence = $payload['Records'][0]['s3']['object']['sequencer'];
        self::assertMatchesRegularExpression('/^[0-9A-F]{16,}$/', $putSequence);
        self::assertSame(
            $putResult['@metadata']['headers']['x-amz-request-id'],
            $payload['Records'][0]['responseElements']['x-amz-request-id'],
        );
        self::assertSame(
            $putResult['@metadata']['headers']['x-amz-id-2'],
            $payload['Records'][0]['responseElements']['x-amz-id-2'],
        );
        self::assertNotFalse(filter_var($payload['Records'][0]['requestParameters']['sourceIPAddress'], FILTER_VALIDATE_IP));

        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => $bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [[
                    'Id' => 'signed-delete',
                    'TopicArn' => $destination,
                    'Events' => ['s3:ObjectRemoved:Delete'],
                ]],
            ],
        ]);
        $deleteResult = self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'logs/space + sign.txt']);
        self::assertSame(['pending' => 1, 'sent' => 1], $afterRestart->getNotificationQueueStats());

        $deleteClient = new RecordingDeliveryHttpClient();
        $deleteProcessor = new NotificationProcessor(
            $afterRestart,
            pollInterval: 0.02,
            httpClient: new HttpClient($deleteClient, []),
        );
        try {
            $deleteProcessor->start();
            $deadline = microtime(true) + 3.0;
            do {
                if ($afterRestart->getNotificationQueueStats() === ['sent' => 2]) {
                    break;
                }
                \Amp\delay(0.02);
            } while (microtime(true) < $deadline);
        } finally {
            $deleteProcessor->stop();
        }

        self::assertSame(['sent' => 2], $afterRestart->getNotificationQueueStats());
        self::assertCount(1, $deleteClient->requests);
        $deletePayload = json_decode($deleteClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ObjectRemoved:Delete', $deletePayload['Records'][0]['eventName']);
        self::assertArrayNotHasKey('size', $deletePayload['Records'][0]['s3']['object']);
        self::assertArrayNotHasKey('eTag', $deletePayload['Records'][0]['s3']['object']);
        $deleteSequence = $deletePayload['Records'][0]['s3']['object']['sequencer'];
        self::assertMatchesRegularExpression('/^[0-9A-F]{16,}$/', $deleteSequence);
        self::assertLessThan(hexdec($deleteSequence), hexdec($putSequence));
        self::assertSame(
            $deleteResult['@metadata']['headers']['x-amz-request-id'],
            $deletePayload['Records'][0]['responseElements']['x-amz-request-id'],
        );
        self::assertSame(
            $deleteResult['@metadata']['headers']['x-amz-id-2'],
            $deletePayload['Records'][0]['responseElements']['x-amz-id-2'],
        );

        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => $bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [[
                    'Id' => 'signed-versioned',
                    'TopicArn' => $destination,
                    'Events' => ['s3:ObjectCreated:Put', 's3:ObjectRemoved:DeleteMarkerCreated'],
                ]],
            ],
        ]);
        $versionedPut = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'Body' => 'first version']);
        $markerDelete = self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'versioned.txt']);
        self::assertNotEmpty($versionedPut['VersionId']);
        self::assertNotEmpty($markerDelete['VersionId']);

        $versionedClient = new RecordingDeliveryHttpClient();
        $versionedProcessor = new NotificationProcessor(
            $afterRestart,
            pollInterval: 0.02,
            httpClient: new HttpClient($versionedClient, []),
        );
        try {
            $versionedProcessor->start();
            $deadline = microtime(true) + 3.0;
            do {
                if ($afterRestart->getNotificationQueueStats() === ['sent' => 4]) {
                    break;
                }
                \Amp\delay(0.02);
            } while (microtime(true) < $deadline);
        } finally {
            $versionedProcessor->stop();
        }

        self::assertSame(['sent' => 4], $afterRestart->getNotificationQueueStats());
        self::assertCount(2, $versionedClient->requests);
        $versionedPayloads = [];
        foreach ($versionedClient->requests as $delivery) {
            $record = json_decode($delivery['body'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
            $versionedPayloads[$record['eventName']] = $record;
        }
        self::assertSame($versionedPut['VersionId'], $versionedPayloads['ObjectCreated:Put']['s3']['object']['versionId']);
        self::assertSame($markerDelete['VersionId'], $versionedPayloads['ObjectRemoved:DeleteMarkerCreated']['s3']['object']['versionId']);
    }

    public function test_copy_multipart_and_batch_delete_events_use_committed_version_ids(): void
    {
        $bucket = 'notification-versions-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'source.txt', 'Body' => 'source']);
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => $bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [[
                    'Id' => 'versioned-events',
                    'TopicArn' => 'https://93.184.216.34/webhook',
                    'Events' => [
                        's3:ObjectCreated:Copy',
                        's3:ObjectCreated:CompleteMultipartUpload',
                        's3:ObjectRemoved:Delete',
                    ],
                ]],
            ],
        ]);

        $copy = self::$s3->copyObject([
            'Bucket' => $bucket,
            'Key' => 'copy.txt',
            'CopySource' => $bucket . '/source.txt',
        ]);
        $upload = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'multipart.txt']);
        $part = self::$s3->uploadPart([
            'Bucket' => $bucket,
            'Key' => 'multipart.txt',
            'UploadId' => $upload['UploadId'],
            'PartNumber' => 1,
            'Body' => 'multipart payload',
        ]);
        $completed = self::$s3->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => 'multipart.txt',
            'UploadId' => $upload['UploadId'],
            'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
        ]);
        self::assertNotEmpty($copy['VersionId']);
        self::assertNotEmpty($completed['VersionId']);
        self::$s3->deleteObjects([
            'Bucket' => $bucket,
            'Delete' => ['Objects' => [
                ['Key' => 'copy.txt', 'VersionId' => $copy['VersionId']],
                ['Key' => 'multipart.txt', 'VersionId' => $completed['VersionId']],
            ]],
        ]);

        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        $rows = $metadata->dequeueNotifications(10);
        self::assertCount(4, $rows);
        $versionsByEvent = [];
        foreach ($rows as $row) {
            $record = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
            $versionsByEvent[$record['eventName']][] = $record['s3']['object']['versionId'];
            self::assertTrue($metadata->updateNotificationStatus(
                $row['id'],
                'sent',
                incrementAttempts: false,
                expectedLeaseExpiresAt: $row['lease_expires_at'],
            ));
        }

        self::assertSame([$copy['VersionId']], $versionsByEvent['ObjectCreated:Copy']);
        self::assertSame([$completed['VersionId']], $versionsByEvent['ObjectCreated:CompleteMultipartUpload']);
        $deletedVersions = $versionsByEvent['ObjectRemoved:Delete'];
        sort($deletedVersions);
        $expectedVersions = [$copy['VersionId'], $completed['VersionId']];
        sort($expectedVersions);
        self::assertSame($expectedVersions, $deletedVersions);

        // Keep this class's queue-stat assertions independent of test order.
        $connection = new \PDO('sqlite:' . self::$storagePath . '/metadata.sqlite');
        $deleteRows = $connection->prepare('DELETE FROM s3_notification_queue WHERE bucket = ?');
        $deleteRows->execute([$bucket]);
    }
}

final class RecordingDeliveryHttpClient implements DelegateHttpClient
{
    /** @var list<array{method: string, url: string, contentType: ?string, body: string}> */
    public array $requests = [];

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'contentType' => $request->getHeader('Content-Type'),
            'body' => \Amp\ByteStream\buffer($request->getBody()->getContent()),
        ];

        return new Response('1.1', 204, null, [], '', $request);
    }
}
