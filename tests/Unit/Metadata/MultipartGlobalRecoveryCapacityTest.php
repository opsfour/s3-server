<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class MultipartGlobalRecoveryCapacityTest extends TestCase
{
    public function test_part_admission_rejects_global_saturation_before_writing(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->createMultipartUpload('upload', 'bucket', 'key', 'owner');
        $bucket = $store->getBucket('bucket');
        self::assertNotNull($bucket);

        $pdo = (new \ReflectionClass($store))->getMethod('connection')->invoke($store);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 1000000 WHERE kind = 'part'");
        try {
            $store->createMultipartPartWriteIntent('bucket', $bucket->generation, 'STANDARD', 'upload', 'parts/new');
            self::fail('Part admission accepted one million retained intents.');
        } catch (SlowDownException $error) {
            self::assertSame('Multipart part recovery capacity is exhausted.', $error->getMessage());
        } finally {
            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 0 WHERE kind = 'part'");
        }

        self::assertSame([], $store->listMultipartPartWriteIntents('owner', 0, 1));
    }
}
