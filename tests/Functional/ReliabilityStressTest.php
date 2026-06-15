<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\CommandPool;
use Aws\ResultInterface;

/**
 * Focused reliability tests for streaming, restart persistence, and concurrent
 * SDK use. Defaults are CI-friendly; set env vars to turn this into a heavier
 * local soak suite.
 */
final class ReliabilityStressTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'reliability-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    public function test_large_put_and_get_use_file_streams_and_preserve_bytes(): void
    {
        $bytes = self::envInt('S3_TEST_LARGE_OBJECT_BYTES', 32 * 1024 * 1024);
        $source = self::createDeterministicFile('large-source-', $bytes);
        $download = tempnam(sys_get_temp_dir(), 'large-download-');
        $key = 'large-streamed-object.bin';

        $rssBefore = self::serverRssBytes();

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'SourceFile' => $source,
            'ContentType' => 'application/octet-stream',
            'ContentSHA256' => 'UNSIGNED-PAYLOAD',
        ]);

        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame($bytes, $head['ContentLength']);

        self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'SaveAs' => $download,
        ]);

        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $download));

        $rssAfter = self::serverRssBytes();
        if ($rssBefore !== null && $rssAfter !== null) {
            $allowedGrowth = self::envInt('S3_TEST_MAX_RSS_GROWTH_BYTES', 192 * 1024 * 1024);
            $this->assertLessThanOrEqual(
                $allowedGrowth,
                max(0, $rssAfter - $rssBefore),
                'Server RSS grew too much during streamed large-object PUT/GET.',
            );
        }

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        @unlink($source);
        @unlink($download);
    }

    public function test_large_multipart_upload_round_trip_from_streamed_parts(): void
    {
        $partBytes = self::envInt('S3_TEST_MULTIPART_PART_BYTES', 8 * 1024 * 1024);
        $partCount = self::envInt('S3_TEST_MULTIPART_PARTS', 4);
        $key = 'large-multipart.bin';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ContentType' => 'application/octet-stream',
        ]);
        $uploadId = $create['UploadId'];
        $parts = [];
        $partFiles = [];
        $expectedHashContext = hash_init('sha256');

        try {
            for ($partNumber = 1; $partNumber <= $partCount; $partNumber++) {
                $file = self::createDeterministicFile("part-{$partNumber}-", $partBytes, chr(64 + $partNumber));
                $partFiles[] = $file;
                hash_update_file($expectedHashContext, $file);

                $partStream = fopen($file, 'rb');
                if ($partStream === false) {
                    self::fail('Could not open multipart part for reading.');
                }

                try {
                    $upload = self::$s3->uploadPart([
                        'Bucket' => self::$bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                        'PartNumber' => $partNumber,
                        'Body' => $partStream,
                        'ContentLength' => filesize($file),
                    ]);
                } finally {
                    fclose($partStream);
                }

                $parts[] = [
                    'PartNumber' => $partNumber,
                    'ETag' => $upload['ETag'],
                ];
            }

            self::$s3->completeMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);

            $download = tempnam(sys_get_temp_dir(), 'multipart-download-');
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'SaveAs' => $download,
            ]);

            $this->assertSame(hash_final($expectedHashContext), hash_file('sha256', $download));
            $this->assertSame($partBytes * $partCount, filesize($download));

            @unlink($download);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        } finally {
            foreach ($partFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function test_concurrent_put_head_get_delete_workload_stays_responsive(): void
    {
        $objectCount = self::envInt('S3_TEST_CONCURRENT_OBJECTS', 100);
        $objectBytes = self::envInt('S3_TEST_CONCURRENT_OBJECT_BYTES', 64 * 1024);
        $concurrency = self::envInt('S3_TEST_CONCURRENT_REQUESTS', 100);
        $putCommands = [];
        for ($i = 0; $i < $objectCount; $i++) {
            $putCommands[] = self::$s3->getCommand('PutObject', [
                'Bucket' => self::$bucket,
                'Key' => "concurrent/{$i}.bin",
                'Body' => str_repeat(chr(65 + ($i % 26)), $objectBytes),
            ]);
        }

        $this->runCommandPool($putCommands, $concurrency);

        $list = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'concurrent/',
        ]);
        $this->assertSame($objectCount, count($list['Contents'] ?? []));

        $headCommands = [];
        for ($i = 0; $i < $objectCount; $i++) {
            $headCommands[] = self::$s3->getCommand('HeadObject', [
                'Bucket' => self::$bucket,
                'Key' => "concurrent/{$i}.bin",
            ]);
        }

        $this->runCommandPool($headCommands, $concurrency, function (ResultInterface $result) use ($objectBytes): void {
            $this->assertSame($objectBytes, $result['ContentLength']);
        });

        $deleteObjects = [];
        for ($i = 0; $i < $objectCount; $i++) {
            $deleteObjects[] = ['Key' => "concurrent/{$i}.bin"];
        }
        self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => ['Objects' => $deleteObjects],
        ]);

        $afterDelete = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'concurrent/',
        ]);
        $this->assertSame(0, count($afterDelete['Contents'] ?? []));
    }

    public function test_metadata_and_objects_survive_server_restart_without_stale_cache(): void
    {
        $key = 'restart/persistent.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'before restart',
            'Metadata' => ['generation' => 'one'],
        ]);

        self::restartServer();

        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('one', $head['Metadata']['generation'] ?? null);

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('before restart', (string) $get['Body']);

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'after restart',
            'Metadata' => ['generation' => 'two'],
        ]);

        $updated = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('two', $updated['Metadata']['generation'] ?? null);
    }

    public function test_repeated_operations_do_not_make_server_stale_or_unresponsive(): void
    {
        $iterations = self::envInt('S3_TEST_SOAK_ITERATIONS', 75);

        for ($i = 0; $i < $iterations; $i++) {
            $key = sprintf('soak/%04d.txt', $i);
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => "iteration {$i}",
            ]);

            $head = self::$s3->headObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);
            $this->assertSame(strlen("iteration {$i}"), $head['ContentLength']);

            $get = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);
            $this->assertSame("iteration {$i}", (string) $get['Body']);

            if ($i % 5 === 0) {
                $list = self::$s3->listObjectsV2([
                    'Bucket' => self::$bucket,
                    'Prefix' => 'soak/',
                    'MaxKeys' => 10,
                ]);
                $this->assertSame(200, $list['@metadata']['statusCode']);
            }
        }

        $health = file_get_contents(sprintf('http://%s:%d/.health', self::$host, self::$port));
        $this->assertIsString($health);
        $this->assertStringContainsString('ok', strtolower($health));
    }

    public function test_opt_in_production_soak_keeps_memory_temp_files_and_responses_stable(): void
    {
        if (! self::envBool('S3_TEST_PRODUCTION_SOAK')) {
            self::markTestSkipped('Set S3_TEST_PRODUCTION_SOAK=1 to run the long production soak profile.');
        }

        $durationSeconds = self::envInt('S3_TEST_PRODUCTION_SOAK_SECONDS', 300);
        $batchSize = self::envInt('S3_TEST_PRODUCTION_SOAK_BATCH', 25);
        $objectBytes = self::envInt('S3_TEST_PRODUCTION_SOAK_OBJECT_BYTES', 256 * 1024);
        $concurrency = self::envInt('S3_TEST_PRODUCTION_SOAK_CONCURRENCY', 25);
        $allowedGrowth = self::envInt('S3_TEST_PRODUCTION_SOAK_MAX_RSS_GROWTH_BYTES', 256 * 1024 * 1024);

        $rssBefore = self::serverRssBytes();
        $tmpBefore = self::countFiles(self::$storagePath.'/.tmp');
        $deadline = microtime(true) + $durationSeconds;
        $iteration = 0;

        while (microtime(true) < $deadline) {
            $prefix = sprintf('production-soak/%06d/', $iteration++);
            $putCommands = [];
            $headCommands = [];
            $deleteObjects = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $key = $prefix.$i.'.bin';
                $putCommands[] = self::$s3->getCommand('PutObject', [
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'Body' => str_repeat(chr(65 + ($i % 26)), $objectBytes),
                    'ContentSHA256' => 'UNSIGNED-PAYLOAD',
                ]);
                $headCommands[] = self::$s3->getCommand('HeadObject', [
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                ]);
                $deleteObjects[] = ['Key' => $key];
            }

            $this->runCommandPool($putCommands, $concurrency);
            $this->runCommandPool($headCommands, $concurrency, function (ResultInterface $result) use ($objectBytes): void {
                $this->assertSame($objectBytes, $result['ContentLength']);
            });

            self::$s3->deleteObjects([
                'Bucket' => self::$bucket,
                'Delete' => ['Objects' => $deleteObjects],
            ]);
        }

        $tmpAfter = self::countFiles(self::$storagePath.'/.tmp');
        $this->assertLessThanOrEqual($tmpBefore, $tmpAfter, 'Production soak left stale temp files behind.');

        $rssAfter = self::serverRssBytes();
        if ($rssBefore !== null && $rssAfter !== null) {
            $this->assertLessThanOrEqual(
                $allowedGrowth,
                max(0, $rssAfter - $rssBefore),
                'Server RSS grew too much during production soak.',
            );
        }
    }

    /**
     * @param list<\Aws\CommandInterface> $commands
     */
    private function runCommandPool(array $commands, int $concurrency, ?callable $fulfilled = null): void
    {
        $errors = [];
        $pool = new CommandPool(self::$s3, $commands, [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResultInterface $result) use ($fulfilled): void {
                if ($fulfilled !== null) {
                    $fulfilled($result);
                }
            },
            'rejected' => static function ($reason) use (&$errors): void {
                $errors[] = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
            },
        ]);

        $pool->promise()->wait();

        $this->assertSame([], $errors, self::serverLogs());
    }

    private static function createDeterministicFile(string $prefix, int $bytes, string $seed = 'A'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            self::fail('Could not create temporary file.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            self::fail('Could not open temporary file for writing.');
        }

        $chunkSize = 1024 * 1024;
        $chunk = str_repeat($seed, $chunkSize);
        $remaining = $bytes;

        while ($remaining > 0) {
            $write = min($chunkSize, $remaining);
            fwrite($handle, substr($chunk, 0, $write));
            $remaining -= $write;
        }

        fclose($handle);

        return $path;
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return max(1, (int) $value);
    }

    private static function envBool(string $name): bool
    {
        $value = getenv($name);

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function countFiles(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
