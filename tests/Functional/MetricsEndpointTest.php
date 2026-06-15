<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

final class MetricsEndpointTest extends S3FunctionalTestCase
{
    public function test_metrics_endpoint_exposes_prometheus_request_counters(): void
    {
        $bucket = 'metrics-'.bin2hex(random_bytes(4));

        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'object.txt',
            'Body' => 'metrics body',
        ]);
        self::$s3->headObject([
            'Bucket' => $bucket,
            'Key' => 'object.txt',
        ]);

        $metrics = file_get_contents(sprintf('http://%s:%d/.metrics', self::$host, self::$port));

        self::assertIsString($metrics);
        self::assertStringContainsString('# TYPE s3_server_up gauge', $metrics);
        self::assertStringContainsString('s3_server_up 1', $metrics);
        self::assertStringContainsString('# TYPE s3_server_requests_total counter', $metrics);
        self::assertStringContainsString('operation="CreateBucket"', $metrics);
        self::assertStringContainsString('operation="PutObject"', $metrics);
        self::assertStringContainsString('operation="HeadObject"', $metrics);
        self::assertStringContainsString('status="200"', $metrics);
        self::assertStringContainsString('s3_server_request_duration_seconds_sum', $metrics);
        self::assertStringContainsString('s3_server_request_duration_seconds_count', $metrics);
        self::assertStringContainsString('# TYPE s3_server_lifecycle_running gauge', $metrics);
        self::assertStringContainsString('s3_server_lifecycle_running', $metrics);
        self::assertStringContainsString('# TYPE s3_server_lifecycle_sweeps_total counter', $metrics);
        self::assertStringContainsString('# TYPE s3_server_lifecycle_actions_total counter', $metrics);
        self::assertStringContainsString('# TYPE s3_server_notification_queue_depth gauge', $metrics);
        self::assertStringContainsString('# TYPE s3_server_notification_deliveries_total counter', $metrics);
    }

    public function test_metrics_endpoint_exposes_notification_queue_depth(): void
    {
        $bucket = 'metrics-notifications-'.bin2hex(random_bytes(4));

        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => $bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [[
                    'Id' => 'metrics-webhook',
                    'TopicArn' => 'https://notifications.example.test/s3',
                    'Events' => ['s3:ObjectCreated:*'],
                ]],
            ],
        ]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'queued.txt',
            'Body' => 'queued body',
        ]);

        $metrics = file_get_contents(sprintf('http://%s:%d/.metrics', self::$host, self::$port));

        self::assertIsString($metrics);
        self::assertStringContainsString('s3_server_notification_queue_depth{status="pending"} 1', $metrics);
    }
}
