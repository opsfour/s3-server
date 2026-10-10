<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectWriteIntentDecoratorTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-object-intent-decorator-');
        self::assertIsString($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_cached_and_observed_wrappers_preserve_intent_transaction_and_claim(): void
    {
        $sqlite = new SqliteMetadataStore($this->databasePath);
        $sqlite->initialize();
        $metrics = new MetricsCollector();
        $store = new ObservedMetadataStore(new CachedMetadataStoreDecorator($sqlite), $metrics, 'sqlite');
        self::assertInstanceOf(ObjectWriteIntentStore::class, $store);

        $first = self::intent('a');
        $reserved = $store->reserveObjectWriteIntent($first, 60.0);
        self::assertSame($first->attemptId, $store->getObjectWriteIntent($first->attemptId)?->attemptId);

        try {
            $store->transaction(function () use ($store, $first): void {
                self::assertTrue($store->consumeObjectWriteIntent($first->attemptId));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        self::assertNotNull($store->getObjectWriteIntent($reserved->attemptId));

        $store->transaction(static function () use ($store, $first): void {
            self::assertTrue($store->consumeObjectWriteIntent($first->attemptId));
        });
        self::assertNull($store->getObjectWriteIntent($first->attemptId));

        $second = self::intent('b');
        $secondReserved = $store->reserveObjectWriteIntent($second, 60.0);
        self::assertNotNull($store->armObjectWriteIntent($second->attemptId, $secondReserved->leaseUntil, str_repeat('b', 32), 60.0));
        self::assertTrue($store->abandonObjectWriteIntent($second->attemptId));
        $claims = $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claims);
        self::assertSame($second->attemptId, $claims[0]->attemptId);
        self::assertTrue($store->sealObjectWriteIntent($second->attemptId, $claims[0]->claimGeneration));
        self::assertSame('sealed', $store->getObjectWriteIntent($second->attemptId)?->state);
        self::assertStringContainsString('operation="reserveObjectWriteIntent"', $metrics->renderPrometheus());
    }

    public function test_wrapper_fails_closed_when_inner_store_lacks_intent_contract(): void
    {
        $inner = $this->createMock(MetadataStore::class);
        $cached = new CachedMetadataStoreDecorator($inner);
        $observed = new ObservedMetadataStore($cached, new MetricsCollector(), 'custom');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Metadata backend does not support durable object write intents.');
        $observed->reserveObjectWriteIntent(self::intent('c'), 60.0);
    }

    private static function intent(string $suffix): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: str_repeat($suffix, 32),
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: 'owner',
            storageTier: 'STANDARD',
            storagePath: 'objects/' . $suffix,
            purpose: 'object',
        );
    }
}
