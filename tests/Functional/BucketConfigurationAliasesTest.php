<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

final class BucketConfigurationAliasesTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static string $targetBucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        $suffix = bin2hex(random_bytes(4));
        self::$bucket = 'bucket-config-' . $suffix;
        self::$targetBucket = 'bucket-config-target-' . $suffix;

        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$s3->createBucket(['Bucket' => self::$targetBucket]);

        self::$seeded = true;
    }

    public function test_lifecycle_legacy_sdk_aliases_hit_same_configuration(): void
    {
        $put = self::$s3->putBucketLifecycle([
            'Bucket' => self::$bucket,
            'LifecycleConfiguration' => [
                'Rules' => [
                    [
                        'ID' => 'alias-expire',
                        'Status' => 'Enabled',
                        'Prefix' => 'tmp/',
                        'Expiration' => ['Days' => 7],
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $put['@metadata']['statusCode']);

        $get = self::$s3->getBucketLifecycle([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $get['@metadata']['statusCode']);
        $this->assertSame('alias-expire', $get['Rules'][0]['ID']);
        $this->assertSame(7, $get['Rules'][0]['Expiration']['Days']);
    }

    public function test_notification_legacy_sdk_aliases_hit_same_configuration(): void
    {
        $put = self::$s3->putBucketNotification([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => [
                'QueueConfiguration' => [
                    'Id' => 'alias-queue',
                    'Queue' => 'arn:aws:sqs:us-east-1:123456789012:queue',
                    'Event' => 's3:ObjectCreated:*',
                ],
            ],
        ]);

        $this->assertSame(200, $put['@metadata']['statusCode']);

        $get = self::$s3->getBucketNotification([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $get['@metadata']['statusCode']);
        $this->assertSame('alias-queue', $get['QueueConfiguration']['Id'] ?? null);
        $this->assertSame('arn:aws:sqs:us-east-1:123456789012:queue', $get['QueueConfiguration']['Queue'] ?? null);
    }

    public function test_bucket_logging_configuration_round_trip(): void
    {
        $put = self::$s3->putBucketLogging([
            'Bucket' => self::$bucket,
            'BucketLoggingStatus' => [
                'LoggingEnabled' => [
                    'TargetBucket' => self::$targetBucket,
                    'TargetPrefix' => 'logs/',
                ],
            ],
        ]);

        $this->assertSame(200, $put['@metadata']['statusCode']);

        $get = self::$s3->getBucketLogging([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $get['@metadata']['statusCode']);
        $this->assertSame(self::$targetBucket, $get['LoggingEnabled']['TargetBucket']);
        $this->assertSame('logs/', $get['LoggingEnabled']['TargetPrefix']);

        self::$s3->putBucketLogging([
            'Bucket' => self::$bucket,
            'BucketLoggingStatus' => [],
        ]);

        $disabled = self::$s3->getBucketLogging([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $disabled['@metadata']['statusCode']);
        $this->assertArrayNotHasKey('LoggingEnabled', $disabled);
    }

    public function test_bucket_policy_status_reports_public_policy(): void
    {
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $status = self::$s3->getBucketPolicyStatus([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $status['@metadata']['statusCode']);
        $this->assertTrue($status['PolicyStatus']['IsPublic']);

        self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
    }
}
