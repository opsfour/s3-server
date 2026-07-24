<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class KmsEncryptionFunctionalTest extends S3FunctionalTestCase
{
    private static string|false $previousMasterKeys;

    private static string $bucket = '';

    public static function setUpBeforeClass(): void
    {
        self::$previousMasterKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');

        $keys = [
            'test-kms-key' => base64_encode(str_repeat('K', 32)),
        ];
        putenv('S3_ENCRYPTION_MASTER_KEYS=' . json_encode($keys, JSON_THROW_ON_ERROR));

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$previousMasterKeys === false) {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
        } else {
            putenv('S3_ENCRYPTION_MASTER_KEYS=' . self::$previousMasterKeys);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$bucket !== '') {
            return;
        }

        self::$bucket = 'kms-default-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
    }

    public function test_aws_kms_bucket_default_is_rejected_until_a_real_kms_adapter_exists(): void
    {
        try {
            self::$s3->putBucketEncryption([
                'Bucket' => self::$bucket,
                'ServerSideEncryptionConfiguration' => [
                    'Rules' => [
                        [
                            'ApplyServerSideEncryptionByDefault' => [
                                'SSEAlgorithm' => 'aws:kms',
                                'KMSMasterKeyID' => 'alias/test-key',
                            ],
                            'BucketKeyEnabled' => true,
                        ],
                    ],
                ],
            ]);
            self::fail('Expected aws:kms bucket encryption to be rejected.');
        } catch (S3Exception $e) {
            self::assertSame(501, $e->getStatusCode());
            self::assertSame('NotImplemented', $e->getAwsErrorCode());
        }
    }
}
