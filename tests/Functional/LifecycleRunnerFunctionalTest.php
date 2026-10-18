<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

final class LifecycleRunnerFunctionalTest extends S3FunctionalTestCase
{
    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    public static function setUpBeforeClass(): void
    {
        foreach ([
            'S3_TEST_SERVER_STORAGE_DRIVER' => 'filesystem',
            'S3_METADATA_DRIVER' => 'sqlite',
            'S3_METADATA_CACHE_TTL' => '0',
            'S3_LIFECYCLE_INTERVAL_SECONDS' => '0.05',
        ] as $name => $value) {
            self::$previousEnv[$name] = getenv($name);
            putenv("{$name}={$value}");
        }

        self::$previousEnv['S3_METADATA_PATH'] = getenv('S3_METADATA_PATH');
        putenv('S3_METADATA_PATH');

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        foreach (self::$previousEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        self::$previousEnv = [];
    }

    public function test_started_server_runs_lifecycle_expiration_automatically(): void
    {
        $bucket = 'lifecycle-runner-' . bin2hex(random_bytes(4));
        $key = 'expired.txt';

        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketLifecycleConfiguration([
            'Bucket' => $bucket,
            'LifecycleConfiguration' => [
                'Rules' => [[
                    'ID' => 'expire-current-object',
                    'Status' => 'Enabled',
                    'Filter' => ['Prefix' => ''],
                    'Expiration' => ['Date' => new \DateTimeImmutable('2000-01-01T00:00:00Z')],
                ]],
            ],
        ]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => 'expired by the background lifecycle runner',
        ]);

        $deadline = microtime(true) + 5.0;
        $expired = false;
        do {
            $objects = self::$s3->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => $key,
            ])['Contents'] ?? [];
            if ($objects === []) {
                $expired = true;
                break;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::assertTrue($expired, "The started server did not expire the object before the deadline.\n" . self::serverLogs());
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? []);

        $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
        self::assertSame([], $versions['Versions'] ?? []);
        self::assertSame([], $versions['DeleteMarkers'] ?? []);

        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }
}
