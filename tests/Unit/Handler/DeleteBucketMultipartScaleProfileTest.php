<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

/** Run explicitly with S3_AUDIT_DELETE_BUCKET_UPLOADS=2000..5000. */
final class DeleteBucketMultipartScaleProfileTest extends TestCase
{
    public function test_large_sqlite_filesystem_multipart_backlog_drains_completely(): void
    {
        $rawCount = getenv('S3_AUDIT_DELETE_BUCKET_UPLOADS');
        if ($rawCount === false) {
            self::markTestSkipped('Set S3_AUDIT_DELETE_BUCKET_UPLOADS=2000..5000 for the opt-in scale profile.');
        }
        if (! ctype_digit($rawCount) || (int) $rawCount < 2_000 || (int) $rawCount > 5_000) {
            self::fail('S3_AUDIT_DELETE_BUCKET_UPLOADS must be an integer from 2000 to 5000.');
        }
        $uploadCount = (int) $rawCount;
        $base = sys_get_temp_dir() . '/s3-delete-scale-' . bin2hex(random_bytes(6));
        $storageRoot = $base . '/storage';
        mkdir($storageRoot, 0o700, true);
        $databasePath = $base . '/metadata.sqlite';
        $backend = null;

        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $backend = new FilesystemBackend($storageRoot);
            $backend->createBucket('bucket');

            $seedStarted = hrtime(true);
            $metadata->transaction(function () use ($metadata, $storageRoot, $uploadCount): void {
                for ($index = 0; $index < $uploadCount; $index++) {
                    $uploadId = sprintf('%032x', $index + 1);
                    $key = sprintf('key-%05d', $index);
                    $metadata->createMultipartUpload($uploadId, 'bucket', $key, 'owner');
                    $partPath = StoragePath::forPart($storageRoot, $uploadId, 1);
                    mkdir(dirname($partPath), 0o700, true);
                    file_put_contents($partPath, 'x');
                    $metadata->putPart($uploadId, 1, md5('x'), 1, $partPath);
                }
            });
            $seedSeconds = (hrtime(true) - $seedStarted) / 1_000_000_000;

            $metrics = new MetricsCollector();
            $observed = new ObservedMetadataStore($metadata, $metrics, 'sqlite');
            $request = new Request($this->createMock(Client::class), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            $handlerStarted = hrtime(true);
            $response = (new DeleteBucketHandler($observed, $backend))->handleRequest($request);
            $handlerSeconds = (hrtime(true) - $handlerStarted) / 1_000_000_000;
            $transactionSeconds = self::transactionSeconds($metrics->renderPrometheus());
            $pdo = new \PDO('sqlite:' . $databasePath);
            $queueAtCommit = self::queueCount($pdo);

            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend));
            $drainStarted = hrtime(true);
            $completed = 0;
            $batches = 0;
            while ($completed < $uploadCount && $batches <= (int) ceil($uploadCount / 100)) {
                $completed += $collector->collect(100);
                $batches++;
            }
            $drainSeconds = (hrtime(true) - $drainStarted) / 1_000_000_000;
            $queueAfterDrain = self::queueCount($pdo);
            $remainingDirs = glob($storageRoot . '/.parts/*', GLOB_ONLYDIR);
            self::assertIsArray($remainingDirs);

            $result = [
                'uploads' => $uploadCount,
                'seed_seconds' => round($seedSeconds, 3),
                'handler_seconds' => round($handlerSeconds, 3),
                'handler_transactions_seconds' => round($transactionSeconds, 3),
                'queued_at_commit' => $queueAtCommit,
                'drain_seconds' => round($drainSeconds, 3),
                'drain_batches' => $batches,
                'completed_aborts' => $completed,
                'queue_after_drain' => $queueAfterDrain,
                'physical_upload_dirs_after_drain' => count($remainingDirs),
                'peak_php_memory_mib' => round(memory_get_peak_usage(true) / 1_048_576, 1),
            ];
            $resultPath = getenv('S3_AUDIT_DELETE_BUCKET_RESULT');
            if ($resultPath !== false && $resultPath !== '') {
                file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            }

            self::assertSame(204, $response->getStatus());
            self::assertGreaterThan(0.0, $transactionSeconds);
            self::assertNull($metadata->getBucket('bucket'));
            self::assertSame($uploadCount, $queueAtCommit);
            self::assertSame($uploadCount, $completed);
            self::assertSame(0, $queueAfterDrain);
            self::assertSame([], $remainingDirs);
        } finally {
            $backend?->shutdown();
            self::removeDirectory($base);
        }
    }

    private static function transactionSeconds(string $metrics): float
    {
        preg_match(
            '/^s3_server_backend_operation_duration_seconds_sum\{backend="metadata",driver="sqlite",operation="transaction"\} ([0-9.]+)$/m',
            $metrics,
            $match,
        );

        return isset($match[1]) ? (float) $match[1] : 0.0;
    }

    private static function queueCount(\PDO $pdo): int
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
