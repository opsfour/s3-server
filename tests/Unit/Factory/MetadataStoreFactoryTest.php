<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Factory;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class MetadataStoreFactoryTest extends TestCase
{
    public function test_sqlite_worker_defaults_respect_storage_mode_and_explicit_opt_out(): void
    {
        $path = sys_get_temp_dir() . '/s3-metadata-factory-' . bin2hex(random_bytes(8)) . '.sqlite';

        try {
            $parallel = MetadataStoreFactory::create('sqlite', ['path' => $path]);
            self::assertInstanceOf(ParallelSqliteMetadataStore::class, $parallel);
            $parallel->shutdown();

            self::assertInstanceOf(SqliteMetadataStore::class, MetadataStoreFactory::create('sqlite', [
                'path' => $path,
                'worker_pool_size' => 0,
            ]));
            self::assertInstanceOf(SqliteMetadataStore::class, MetadataStoreFactory::create('sqlite', [
                'path' => ':memory:',
                'worker_pool_size' => 4,
            ]));
        } finally {
            foreach (glob($path . '*') ?: [] as $file) {
                @unlink($file);
            }
        }
    }
}
