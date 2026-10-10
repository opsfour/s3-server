<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Quota\QuotaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountQuotaPageBackendTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function metadataDrivers(): iterable
    {
        yield 'sqlite' => ['sqlite', null];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('metadataDrivers')]
    public function test_account_quota_page_is_bounded_and_resumes_after_last_owner(string $driver, ?string $dsnEnv): void
    {
        $path = null;
        if ($dsnEnv !== null) {
            $dsn = getenv($dsnEnv);
            if ($dsn === false || trim($dsn) === '') {
                self::markTestSkipped("Set {$dsnEnv} to run the {$driver} quota page test.");
            }
            $config = ['dsn' => trim($dsn)];
        } else {
            $path = sys_get_temp_dir() . '/s3-quota-page-' . bin2hex(random_bytes(6)) . '.sqlite';
            $config = ['path' => $path];
        }

        $store = MetadataStoreFactory::create($driver, $config);
        $prefix = 'quota-page-' . bin2hex(random_bytes(8));
        $owners = [$prefix . '-1', $prefix . '-2', $prefix . '-3'];
        try {
            foreach ($owners as $number => $ownerId) {
                $store->putAccountQuota($ownerId, new QuotaConfig(maxBytesPerOwner: $number + 1));
            }

            $first = $store->listAccountQuotasPage($prefix . '-0', 2);
            self::assertSame($owners[0], $first[0]['ownerId']);
            self::assertSame($owners[1], $first[1]['ownerId']);
            self::assertSame(2, count($first));
            self::assertSame(1, $first[0]['quota']->maxBytesPerOwner);

            $second = $store->listAccountQuotasPage($first[1]['ownerId'], 2);
            self::assertSame([$owners[2]], array_column($second, 'ownerId'));
            self::assertSame(3, $second[0]['quota']->maxBytesPerOwner);
        } finally {
            foreach ($owners as $ownerId) {
                $store->deleteAccountQuota($ownerId);
            }
            if ($path !== null) {
                @unlink($path);
                @unlink($path . '-wal');
                @unlink($path . '-shm');
            }
        }
    }
}
