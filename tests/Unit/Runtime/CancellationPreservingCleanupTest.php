<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\CancelledException;
use OpsFour\S3Server\Runtime\CancellationPreservingCleanup;
use PHPUnit\Framework\TestCase;

final class CancellationPreservingCleanupTest extends TestCase
{
    public function test_failure_cleanup_keeps_original_cancellation_and_runs_every_cleanup(): void
    {
        $cancellation = new CancelledException();
        $calls = [];

        try {
            CancellationPreservingCleanup::afterFailure(
                $cancellation,
                static function () use (&$calls): void {
                    $calls[] = 'first';
                    throw new \RuntimeException('cleanup failed');
                },
                static function () use (&$calls): void {
                    $calls[] = 'second';
                },
            );
            throw $cancellation;
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }

        self::assertSame(['first', 'second'], $calls);
    }

    public function test_finally_cleanup_keeps_original_cancellation_and_runs_every_cleanup(): void
    {
        $cancellation = new CancelledException();
        $calls = [];

        CancellationPreservingCleanup::inFinally(
            $cancellation,
            static function () use (&$calls): void {
                $calls[] = 'first';
                throw new \RuntimeException('cleanup failed');
            },
            static function () use (&$calls): void {
                $calls[] = 'second';
            },
        );

        self::assertSame(['first', 'second'], $calls);
    }

    public function test_cleanup_failure_still_propagates_without_primary_cancellation(): void
    {
        $cleanupError = new \RuntimeException('cleanup failed');

        try {
            CancellationPreservingCleanup::inFinally(null, static function () use ($cleanupError): void {
                throw $cleanupError;
            });
            self::fail('A standalone cleanup failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame($cleanupError, $error);
        }
    }
}
