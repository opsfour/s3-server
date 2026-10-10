<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MultiNodeTierWorkerFunctionalTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function externalMetadataDrivers(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_missing_tier_worker_defers_transition_and_restore_for_configured_node(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} tier worker tests.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $ownerId = 'tier-worker-' . bin2hex(random_bytes(4));
        $bucket = 'tier-worker-' . bin2hex(random_bytes(8));
        $root = sys_get_temp_dir() . '/s3-tier-worker-' . bin2hex(random_bytes(8));
        $hotPath = $root . '/hot';
        $coldPath = $root . '/cold';
        mkdir($hotPath, 0o700, true);
        mkdir($coldPath, 0o700, true);
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $hot = new FilesystemBackend($hotPath, $filesystem, $filesystem);
        $cold = new FilesystemBackend($coldPath, $filesystem, $filesystem);

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            $hot->createBucket($bucket);
            $cold->createBucket($bucket);
            $source = $hot->putObject($bucket, 'archive.bin', new ReadableBuffer('tier-worker-data'));
            $metadata->putObjectMetadata(
                $bucket,
                'archive.bin',
                $ownerId,
                $source->size,
                '"' . $source->md5Hex . '"',
                'application/octet-stream',
                $source->path,
            );
            $transitionId = $metadata->enqueueTierTransitionJob(
                $bucket,
                'archive.bin',
                null,
                'STANDARD',
                'GLACIER',
                'GLACIER',
                $source->path,
                maxAttempts: 1,
            );

            self::assertSame(
                ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
                self::runWorker($driver, $dsn, 'transition', 'limited', $hotPath, $coldPath),
            );
            $deferred = $metadata->getTierTransitionJob($transitionId);
            self::assertNotNull($deferred);
            self::assertSame('pending', $deferred['status']);
            self::assertSame(0, $deferred['attempts']);
            self::assertGreaterThan(microtime(true), $deferred['nextAttemptAt']);
            self::assertSame('STANDARD', $metadata->getObjectMetadata($bucket, 'archive.bin')?->storageTier);

            $metadata->updateTierTransitionJobStatus(
                $transitionId,
                'pending',
                nextAttemptAt: microtime(true) - 1,
                incrementAttempts: false,
            );
            self::assertSame(
                ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
                self::runWorker($driver, $dsn, 'transition', 'full', $hotPath, $coldPath),
            );
            $archived = $metadata->getObjectMetadata($bucket, 'archive.bin');
            self::assertSame('GLACIER', $archived->storageTier);
            self::assertSame('completed', $metadata->getTierTransitionJob($transitionId)['status'] ?? null);
            self::assertSame('tier-worker-data', \Amp\ByteStream\buffer($cold->getObjectByPath($archived->systemMetadata['storagePath'])));

            $metadata->updateObjectRestoreState($bucket, 'archive.bin', null, 'pending');
            $restoreId = $metadata->enqueueRestoreJob(
                $bucket,
                'archive.bin',
                null,
                'GLACIER',
                $archived->systemMetadata['storagePath'],
                2,
                maxAttempts: 1,
            );
            self::assertSame(
                ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
                self::runWorker($driver, $dsn, 'restore', 'limited', $hotPath, $coldPath),
            );
            $deferredRestore = $metadata->getRestoreJob($restoreId);
            self::assertNotNull($deferredRestore);
            self::assertSame('pending', $deferredRestore['status']);
            self::assertSame(0, $deferredRestore['attempts']);
            self::assertGreaterThan(microtime(true), $deferredRestore['nextAttemptAt']);
            self::assertSame('pending', $metadata->getObjectMetadata($bucket, 'archive.bin')?->restoreStatus);

            $metadata->updateRestoreJobStatus(
                $restoreId,
                'pending',
                nextAttemptAt: microtime(true) - 1,
                incrementAttempts: false,
            );
            self::assertSame(
                ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
                self::runWorker($driver, $dsn, 'restore', 'full', $hotPath, $coldPath),
            );
            $restored = $metadata->getObjectMetadata($bucket, 'archive.bin');
            self::assertSame('restored', $restored->restoreStatus);
            self::assertNotNull($restored->restoredStoragePath);
            self::assertSame('completed', $metadata->getRestoreJob($restoreId)['status'] ?? null);
            self::assertSame('tier-worker-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->restoredStoragePath)));
        } finally {
            $this->cleanupBucket($metadata, $ownerId, $bucket);
            self::removeTree($root);
        }
    }

    /** @return array{processed: int, completed: int, retried: int, deadLetter: int} */
    private static function runWorker(
        string $driver,
        string $dsn,
        string $operation,
        string $configuration,
        string $hotPath,
        string $coldPath,
    ): array {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Support/metadata-tier-worker.php', $driver, $operation, $configuration, $hotPath, $coldPath],
            dirname(__DIR__, 2),
            ['S3_TEST_WORKER_DSN' => $dsn],
        );
        $process->setTimeout(20);
        $process->mustRun();

        /** @var array{processed: int, completed: int, retried: int, deadLetter: int} $result */
        $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        return $result;
    }

    private function cleanupBucket(MetadataStore $metadata, string $ownerId, string $bucket): void
    {
        if ($metadata->getBucketOwner($bucket) !== $ownerId) {
            return;
        }
        $metadata->deleteObjectMetadata($bucket, 'archive.bin');
        $metadata->deleteBucket($ownerId, $bucket);
    }

    private static function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }
}
