<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Storage\FilesystemBackend;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\Future\await as awaitFutures;

final class FilesystemBackendAmpFileRegressionTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/s3server-amp-file-regression-'.bin2hex(random_bytes(8));
        mkdir($this->basePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function test_parallel_puts_do_not_deadlock_amp_file_worker_pool_during_finalize(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');

        $futures = [];
        for ($i = 0; $i < 100; $i++) {
            $futures[] = async(static function () use ($backend, $i) {
                return $backend->putObject(
                    'bucket',
                    "concurrent/{$i}.bin",
                    new ReadableBuffer(str_repeat(chr(65 + ($i % 26)), 1024)),
                );
            });
        }

        $results = awaitFutures($futures, new TimeoutCancellation(10));

        self::assertCount(100, $results);
        foreach ($results as $result) {
            self::assertSame(1024, $result->size);
            self::assertFileExists($result->path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
