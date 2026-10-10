<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalCorsMetadataStoreTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function externalDrivers(): iterable
    {
        yield 'PostgreSQL' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'MySQL' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('externalDrivers')]
    public function test_cors_id_null_zero_and_maximum_age_round_trip(string $driver, string $dsnEnvironment): void
    {
        $dsn = getenv($dsnEnvironment);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnvironment} to run the {$driver} CORS persistence test.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $bucket = 'cors-store-' . bin2hex(random_bytes(8));
        $rules = [
            self::rule('sdk-rule', 0),
            self::rule(null, null),
            self::rule('largest', 2_147_483_647),
        ];

        try {
            $store->putBucketCors($bucket, $rules);
            self::assertSame($rules, $store->getBucketCors($bucket));
        } finally {
            $store->deleteBucketCors($bucket);
        }
    }

    /** @return array{id?: string, allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null} */
    private static function rule(?string $id, ?int $maxAgeSeconds): array
    {
        $rule = [
            'allowedOrigins' => ['https://*.example'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => ['x-amz-*'],
            'exposeHeaders' => ['ETag'],
            'maxAgeSeconds' => $maxAgeSeconds,
        ];
        if ($id !== null) {
            $rule['id'] = $id;
        }

        return $rule;
    }
}
