<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for Phase 7: Encryption Config, Lifecycle Config, Notifications.
 */
final class EncryptionLifecycleNotificationTest extends S3FunctionalTestCase
{
    private static string|false $previousMasterKey = false;

    private static string $bucket = '';

    private static bool $seeded = false;

    public static function setUpBeforeClass(): void
    {
        self::$previousMasterKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('E', 32)));

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$previousMasterKey === false) {
            putenv('S3_ENCRYPTION_MASTER_KEY');
        } else {
            putenv('S3_ENCRYPTION_MASTER_KEY=' . self::$previousMasterKey);
        }
        self::$seeded = false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = self::createIsolatedBucket('phase7');
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Bucket Encryption
    // -----------------------------------------------------------------

    public function test_get_bucket_encryption_not_set(): void
    {
        $bucket = self::createIsolatedBucket('phase7-empty-encryption');

        try {
            self::$s3->getBucketEncryption([
                'Bucket' => $bucket,
            ]);
            $this->fail('Expected ServerSideEncryptionConfigurationNotFoundError');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('ServerSideEncryptionConfigurationNotFoundError', $e->getAwsErrorCode());
        }
    }

    public function test_put_and_get_bucket_encryption(): void
    {
        $putResult = self::$s3->putBucketEncryption([
            'Bucket' => self::$bucket,
            'ServerSideEncryptionConfiguration' => [
                'Rules' => [
                    [
                        'ApplyServerSideEncryptionByDefault' => [
                            'SSEAlgorithm' => 'AES256',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get encryption.
        $getResult = self::$s3->getBucketEncryption([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $rules = $getResult['ServerSideEncryptionConfiguration']['Rules'] ?? [];
        $this->assertNotEmpty($rules);
        $this->assertSame('AES256', $rules[0]['ApplyServerSideEncryptionByDefault']['SSEAlgorithm']);
    }

    public function test_delete_bucket_encryption(): void
    {
        // Ensure encryption exists first.
        self::$s3->putBucketEncryption([
            'Bucket' => self::$bucket,
            'ServerSideEncryptionConfiguration' => [
                'Rules' => [
                    [
                        'ApplyServerSideEncryptionByDefault' => [
                            'SSEAlgorithm' => 'AES256',
                        ],
                    ],
                ],
            ],
        ]);

        $deleteResult = self::$s3->deleteBucketEncryption([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // Get after delete should fail.
        try {
            self::$s3->getBucketEncryption([
                'Bucket' => self::$bucket,
            ]);
            $this->fail('Expected ServerSideEncryptionConfigurationNotFoundError after delete');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('ServerSideEncryptionConfigurationNotFoundError', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Bucket Lifecycle
    // -----------------------------------------------------------------

    public function test_get_bucket_lifecycle_not_set(): void
    {
        $bucket = self::createIsolatedBucket('phase7-empty-lifecycle');

        try {
            self::$s3->getBucketLifecycleConfiguration([
                'Bucket' => $bucket,
            ]);
            $this->fail('Expected NoSuchLifecycleConfiguration');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchLifecycleConfiguration', $e->getAwsErrorCode());
        }
    }

    private static function createIsolatedBucket(string $prefix): string
    {
        $bucket = $prefix . '-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);

        return $bucket;
    }

    public function test_put_and_get_bucket_lifecycle(): void
    {
        $putResult = self::$s3->putBucketLifecycleConfiguration([
            'Bucket' => self::$bucket,
            'LifecycleConfiguration' => [
                'Rules' => [
                    [
                        'ID' => 'expire-old-logs',
                        'Status' => 'Enabled',
                        'Filter' => [
                            'Prefix' => 'logs/',
                        ],
                        'Expiration' => [
                            'Days' => 30,
                        ],
                    ],
                    [
                        'ID' => 'archive-data',
                        'Status' => 'Enabled',
                        'Filter' => [
                            'Prefix' => 'data/',
                        ],
                        'Transitions' => [
                            [
                                'Days' => 90,
                                'StorageClass' => 'GLACIER',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get lifecycle.
        $getResult = self::$s3->getBucketLifecycleConfiguration([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $rules = $getResult['Rules'] ?? [];
        $this->assertCount(2, $rules);

        // Find rules by ID.
        $ruleMap = [];
        foreach ($rules as $rule) {
            $ruleMap[$rule['ID']] = $rule;
        }

        $this->assertArrayHasKey('expire-old-logs', $ruleMap);
        $this->assertSame('Enabled', $ruleMap['expire-old-logs']['Status']);
        $this->assertSame(30, $ruleMap['expire-old-logs']['Expiration']['Days']);

        $this->assertArrayHasKey('archive-data', $ruleMap);
        $this->assertSame('Enabled', $ruleMap['archive-data']['Status']);
    }

    public function test_delete_bucket_lifecycle(): void
    {
        // Ensure lifecycle exists first.
        self::$s3->putBucketLifecycleConfiguration([
            'Bucket' => self::$bucket,
            'LifecycleConfiguration' => [
                'Rules' => [
                    [
                        'ID' => 'temp-rule',
                        'Status' => 'Enabled',
                        'Filter' => [
                            'Prefix' => '',
                        ],
                        'Expiration' => [
                            'Days' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $deleteResult = self::$s3->deleteBucketLifecycle([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // Get after delete should fail.
        try {
            self::$s3->getBucketLifecycleConfiguration([
                'Bucket' => self::$bucket,
            ]);
            $this->fail('Expected NoSuchLifecycleConfiguration after delete');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchLifecycleConfiguration', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Bucket Notification
    // -----------------------------------------------------------------

    public function test_get_bucket_notification_empty(): void
    {
        // S3 always returns 200 for notification config, even when empty.
        $result = self::$s3->getBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    public function test_put_and_get_bucket_notification(): void
    {
        $putResult = self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [
                    [
                        'Id' => 'notify-puts',
                        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:my-topic',
                        'Events' => ['s3:ObjectCreated:*'],
                        'Filter' => [
                            'Key' => [
                                'FilterRules' => [
                                    [
                                        'Name' => 'prefix',
                                        'Value' => 'uploads/',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get notification config.
        $getResult = self::$s3->getBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);

        // Verify the topic configuration was stored correctly.
        $topicConfigs = $getResult['TopicConfigurations'] ?? [];
        $this->assertCount(1, $topicConfigs);
        $this->assertSame('notify-puts', $topicConfigs[0]['Id']);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:my-topic', $topicConfigs[0]['TopicArn']);
        $this->assertContains('s3:ObjectCreated:*', $topicConfigs[0]['Events']);
    }

    public function test_put_empty_notification_clears_config(): void
    {
        // First set a notification.
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [
                    [
                        'Id' => 'temp-notify',
                        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:temp-topic',
                        'Events' => ['s3:ObjectCreated:*'],
                    ],
                ],
            ],
        ]);

        // Now clear it with empty config.
        $putResult = self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => [],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get should return empty.
        $getResult = self::$s3->getBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
        ]);

        $topicConfigs = $getResult['TopicConfigurations'] ?? [];
        $queueConfigs = $getResult['QueueConfigurations'] ?? [];
        $lambdaConfigs = $getResult['LambdaFunctionConfigurations'] ?? [];

        $this->assertEmpty($topicConfigs);
        $this->assertEmpty($queueConfigs);
        $this->assertEmpty($lambdaConfigs);
    }

    // -----------------------------------------------------------------
    // Multi-event notification config (multiple <Event> per entry)
    // -----------------------------------------------------------------

    public function test_put_and_get_notification_with_multiple_events(): void
    {
        $putResult = self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => [
                'TopicConfigurations' => [
                    [
                        'Id' => 'multi-event-config',
                        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:multi-topic',
                        'Events' => ['s3:ObjectCreated:*', 's3:ObjectRemoved:*'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        $getResult = self::$s3->getBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
        ]);

        $topicConfigs = $getResult['TopicConfigurations'] ?? [];
        $this->assertCount(1, $topicConfigs);
        $this->assertSame('multi-event-config', $topicConfigs[0]['Id']);

        $events = $topicConfigs[0]['Events'] ?? [];
        $this->assertCount(2, $events);
        $this->assertContains('s3:ObjectCreated:*', $events);
        $this->assertContains('s3:ObjectRemoved:*', $events);

        // Clean up.
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => [],
        ]);
    }
}
