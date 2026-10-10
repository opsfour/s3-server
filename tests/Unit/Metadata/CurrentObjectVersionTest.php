<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrentObjectVersionTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite', null];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('drivers')]
    public function test_current_lookup_includes_only_the_latest_version_even_when_it_is_a_delete_marker(string $driver, ?string $dsnVariable): void
    {
        if ($driver === 'sqlite') {
            $config = ['path' => ':memory:'];
        } else {
            $dsn = getenv($dsnVariable ?? '');
            if (!is_string($dsn) || trim($dsn) === '') {
                self::markTestSkipped("Set {$dsnVariable} for the disposable backend test.");
            }
            $config = ['dsn' => $dsn];
        }

        $metadata = MetadataStoreFactory::create($driver, $config);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'current-version-' . $suffix;
        $owner = 'owner-' . $suffix;
        $key = 'key';
        $versions = [];

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->setBucketVersioning($bucket, 'Enabled');
            $versions[] = $metadata->putObjectVersioned($bucket, $key, $owner, 1, 'etag', 'text/plain', 'path/one');
            $current = $metadata->getCurrentObjectVersion($bucket, $key);
            self::assertNotNull($current);
            self::assertSame($versions[0], $current->versionId);

            $versions[] = $metadata->deleteObjectVersioned($bucket, $key, $owner);
            self::assertNull($metadata->getObjectMetadata($bucket, $key));
            $noncurrent = $metadata->getObjectMetadataByVersion($bucket, $key, $versions[0]);
            self::assertNotNull($noncurrent);
            self::assertArrayHasKey('noncurrentAt', $noncurrent->systemMetadata);
            $marker = $metadata->getCurrentObjectVersion($bucket, $key);
            self::assertNotNull($marker);
            self::assertTrue($marker->isDeleteMarker);
            self::assertSame($versions[1], $marker->versionId);

            $metadata->deleteObjectVersion($bucket, $key, $versions[1]);
            $promoted = $metadata->getCurrentObjectVersion($bucket, $key);
            self::assertNotNull($promoted);
            self::assertSame($versions[0], $promoted->versionId);
            self::assertFalse($promoted->isDeleteMarker);
            self::assertArrayNotHasKey('noncurrentAt', $promoted->systemMetadata);
        } finally {
            foreach ($versions as $versionId) {
                $metadata->deleteObjectVersion($bucket, $key, $versionId);
            }
            $metadata->deleteBucket($owner, $bucket);
        }
    }
}
