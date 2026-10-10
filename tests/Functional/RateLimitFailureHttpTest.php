<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;

final class RateLimitFailureHttpTest extends S3FunctionalTestCase
{
    public function test_broken_rate_bucket_query_rejects_s3_but_keeps_probes_available(): void
    {
        $storageDriver = getenv('S3_TEST_SERVER_STORAGE_DRIVER') ?: 'filesystem';
        $metadataDriver = getenv('S3_METADATA_DRIVER') ?: 'sqlite';
        $metadataPathOverride = getenv('S3_METADATA_PATH');
        if ($storageDriver === 'memory' || $metadataDriver !== 'sqlite'
            || ($metadataPathOverride !== false && trim($metadataPathOverride) !== '')) {
            self::markTestSkipped('The rate-bucket failure test needs the suite\'s disposable SQLite metadata file.');
        }

        $http = new Client([
            'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
            'http_errors' => false,
            'timeout' => 5,
        ]);

        self::assertSame(200, $http->get('/.live')->getStatusCode());
        self::assertArrayHasKey('Buckets', self::$s3->listBuckets()->toArray());

        $metadataPath = $storageDriver === 'flysystem'
            ? self::$storagePath . '/.tmp/metadata.sqlite'
            : self::$storagePath . '/metadata.sqlite';
        $pdo = new \PDO('sqlite:' . $metadataPath);
        $pdo->exec('DROP TABLE s3_rate_limit_buckets');

        self::assertSame(200, $http->get('/.live')->getStatusCode());
        $readiness = $http->get('/.health');
        self::assertSame(503, $readiness->getStatusCode());
        self::assertSame('{"status":"degraded"}', (string) $readiness->getBody());

        try {
            self::$s3->listBuckets();
            self::fail('An S3 request must not bypass a failed configured rate-limit check.');
        } catch (S3Exception $error) {
            self::assertSame(503, $error->getStatusCode());
            self::assertSame('SlowDown', $error->getAwsErrorCode());
        }
    }
}
