<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

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
        putenv('S3_ENCRYPTION_MASTER_KEYS='.json_encode($keys, JSON_THROW_ON_ERROR));

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$previousMasterKeys === false) {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
        } else {
            putenv('S3_ENCRYPTION_MASTER_KEYS='.self::$previousMasterKeys);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$bucket !== '') {
            return;
        }

        self::$bucket = 'kms-default-'.bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
    }

    public function test_aws_kms_bucket_default_round_trips_with_configured_master_key_provider(): void
    {
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

        $encryption = self::$s3->getBucketEncryption(['Bucket' => self::$bucket]);
        $defaults = $encryption['ServerSideEncryptionConfiguration']['Rules'][0]['ApplyServerSideEncryptionByDefault'];

        self::assertSame('aws:kms', $defaults['SSEAlgorithm']);
        self::assertSame('alias/test-key', $defaults['KMSMasterKeyID']);

        $key = 'kms/default-object.txt';
        $body = 'kms encrypted payload '.bin2hex(random_bytes(16));

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        self::assertSame('AES256', $head['ServerSideEncryption']);

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        self::assertSame($body, (string) $get['Body']);

        self::assertFalse(
            self::storageContains($body),
            'Plaintext payload was found in storage while aws:kms default encryption was enabled.',
        );
    }

    private static function storageContains(string $needle): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$storagePath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && str_contains($contents, $needle)) {
                return true;
            }
        }

        return false;
    }
}
