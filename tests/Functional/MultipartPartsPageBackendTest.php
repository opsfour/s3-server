<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\PagedMultipartPartsStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartPartsPageBackendTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function metadataDrivers(): iterable
    {
        yield 'sqlite' => ['sqlite', null];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('metadataDrivers')]
    public function test_page_filters_gaps_and_large_markers(string $driver, ?string $dsnEnv): void
    {
        [$store, $path] = self::store($driver, $dsnEnv);
        self::assertInstanceOf(PagedMultipartPartsStore::class, $store);
        $owner = 'parts-page-owner';
        $bucket = 'parts-page-' . bin2hex(random_bytes(6));
        $uploadId = 'AbCd' . bin2hex(random_bytes(12));

        $store->createBucket($owner, $bucket, 'us-east-1');
        try {
            $store->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            foreach ([1, 3, 10, 10000] as $partNumber) {
                $store->putPart($uploadId, $partNumber, 'etag-' . $partNumber, 1, 'part-' . $partNumber);
            }

            $firstPage = $store->listPartsPage($uploadId, 0, 2);
            self::assertSame([1, 3], array_column($firstPage, 'part_number'));
            self::assertMatchesRegularExpression(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',
                $firstPage[0]['created_at'],
            );
            self::assertSame([3], array_column($store->listPartsPage($uploadId, 2, 1), 'part_number'));
            self::assertSame([10, 10000], array_column($store->listPartsPage($uploadId, 3, 1001), 'part_number'));
            self::assertSame([], $store->listPartsPage($uploadId, 10000, 1));
            self::assertSame([], $store->listPartsPage($uploadId, PHP_INT_MAX, 1));
            self::assertSame([], $store->listPartsPage(strtolower($uploadId), 0, 1001));

            foreach ([0, 1002] as $invalidLimit) {
                try {
                    $store->listPartsPage($uploadId, 0, $invalidLimit);
                    self::fail('An unbounded part page must be rejected.');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            $store->deleteMultipartUpload($uploadId);
            $store->deleteBucket($owner, $bucket);
            self::removeSqlite($path);
        }
    }

    #[DataProvider('metadataDrivers')]
    public function test_ten_thousand_part_upload_returns_only_requested_page(string $driver, ?string $dsnEnv): void
    {
        [$store, $path] = self::store($driver, $dsnEnv);
        self::assertInstanceOf(PagedMultipartPartsStore::class, $store);
        $owner = 'parts-scale-owner';
        $bucket = 'parts-scale-' . bin2hex(random_bytes(6));
        $uploadId = bin2hex(random_bytes(16));

        $store->createBucket($owner, $bucket, 'us-east-1');
        try {
            $store->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            $store->transaction(static function () use ($store, $uploadId): void {
                for ($partNumber = 1; $partNumber <= 10000; $partNumber++) {
                    $store->putPart($uploadId, $partNumber, 'etag', 1, 'part-' . $partNumber);
                }
            });

            self::assertSame([1, 2], array_column($store->listPartsPage($uploadId, 0, 2), 'part_number'));
            $first = $store->listPartsPage($uploadId, 0, 1001);
            self::assertCount(1001, $first);
            self::assertSame(1001, $first[1000]['part_number']);
            self::assertSame([9999, 10000], array_column($store->listPartsPage($uploadId, 9998, 1001), 'part_number'));
            self::assertSame([], $store->listPartsPage($uploadId, 10000, 1001));
        } finally {
            $store->deleteMultipartUpload($uploadId);
            $store->deleteBucket($owner, $bucket);
            self::removeSqlite($path);
        }
    }

    /** @return array{\OpsFour\S3Server\Metadata\MetadataStore, ?string} */
    private static function store(string $driver, ?string $dsnEnv): array
    {
        if ($dsnEnv === null) {
            $path = sys_get_temp_dir() . '/s3-parts-page-' . bin2hex(random_bytes(6)) . '.sqlite';

            return [MetadataStoreFactory::create($driver, ['path' => $path]), $path];
        }

        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run the {$driver} multipart parts page test.");
        }

        return [MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]), null];
    }

    private static function removeSqlite(?string $path): void
    {
        if ($path === null) {
            return;
        }
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }
}
