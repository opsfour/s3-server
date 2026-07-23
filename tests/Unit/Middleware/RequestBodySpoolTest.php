<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await;

final class RequestBodySpoolTest extends TestCase
{
    public function test_concurrency_above_worker_count_does_not_exhaust_pool(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0o755, true);
        $pool = new ContextWorkerPool(2);
        $spool = new RequestBodySpool($pool, $tempDir);

        try {
            $futures = [];
            for ($i = 0; $i < 20; $i++) {
                $futures[] = async(static function () use ($spool, $i): string {
                    $payload = str_repeat("payload-{$i}-", 10_000);
                    $path = $spool->create('concurrent-');

                    try {
                        $spool->append($path, $payload);
                        $stream = $spool->openReadable($path);
                        $actual = '';
                        while (($chunk = $stream->read()) !== null) {
                            $actual .= $chunk;
                        }

                        return $actual;
                    } finally {
                        $spool->delete($path);
                    }
                });
            }

            $results = await($futures, new TimeoutCancellation(15));
            self::assertCount(20, $results);
            foreach ($results as $i => $result) {
                self::assertSame(str_repeat("payload-{$i}-", 10_000), $result);
            }
            self::assertSame([], array_values(array_diff(scandir($tempDir) ?: [], ['.', '..'])));
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }
}
