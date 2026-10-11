<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use PHPUnit\Framework\TestCase;

final class LocalObjectWriteLeaseAdmissionTest extends TestCase
{
    public function test_contention_is_rejected_without_queuing_and_release_restores_capacity(): void
    {
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $permit = $admission->acquire();
        self::assertSame(1, $admission->activeCount());
        try {
            $admission->acquire();
            self::fail('A second concurrent write was admitted.');
        } catch (SlowDownException) {
        }

        $permit->release();
        $permit->release();
        self::assertSame(0, $admission->activeCount());
        $next = $admission->acquire();
        self::assertSame(1, $admission->activeCount());
        $next->release();
        self::assertSame(0, $admission->activeCount());
    }
}
