<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\QueueLease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueueLeaseTest extends TestCase
{
    #[DataProvider('batchSizes')]
    public function test_claim_batch_size_is_always_bounded(int $requested, int $expected): void
    {
        self::assertSame($expected, QueueLease::boundedBatchSize($requested));
    }

    /** @return iterable<string, array{int, int}> */
    public static function batchSizes(): iterable
    {
        yield 'negative' => [-1, 1];
        yield 'zero' => [0, 1];
        yield 'one' => [1, 1];
        yield 'within range' => [100, 100];
        yield 'maximum' => [1000, 1000];
        yield 'above maximum' => [1001, 1000];
        yield 'integer maximum' => [PHP_INT_MAX, 1000];
    }
}
