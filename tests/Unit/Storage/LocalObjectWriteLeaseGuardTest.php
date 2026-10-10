<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\DeferredFuture;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseSql;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class LocalObjectWriteLeaseGuardTest extends TestCase
{
    public function test_permit_precedes_reservation_and_arm_is_one_shot(): void
    {
        $planned = self::intent();
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->expects(self::once())->method('reserve')->willReturnCallback(
            static function () use ($admission, $planned): ObjectWriteIntent {
                self::assertSame(1, $admission->activeCount());
                return self::withLease($planned, 100.0);
            },
        );
        $sql->expects(self::once())->method('arm')->willReturnCallback(
            static fn(ObjectWriteIntent $current, string $dispatchId): ObjectWriteIntent => self::withLease($current, 200.0, $dispatchId),
        );
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);
        $renewer->expects(self::never())->method('renew');

        $guard = self::reserveGuard($planned, $sql, $renewer, $admission, 0.1, 0.5);
        $token = $guard->arm();
        self::assertSame($planned->attemptId, $token->attemptId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token->dispatchId);
        self::assertNotSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $token->dispatchId);
        try {
            $guard->arm();
            self::fail('A second dispatch was admitted.');
        } catch (\LogicException) {
            self::assertSame(1, $admission->activeCount());
        }
        self::assertSame($token->dispatchId, $guard->stopForCommit()->dispatchId);
        $guard->close();
        self::assertSame(0, $admission->activeCount());
    }

    public function test_slow_reservation_retains_permit_until_late_sql_result(): void
    {
        $planned = self::intent();
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $release = new DeferredFuture();
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->method('reserve')->willReturnCallback(static function () use ($release, $planned): ObjectWriteIntent {
            $release->getFuture()->await();
            return self::withLease($planned, 100.0);
        });
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);

        try {
            self::reserveGuard($planned, $sql, $renewer, $admission, 0.02, 0.5);
            self::fail('A late SQL reservation was accepted.');
        } catch (\Amp\CancelledException) {
            self::assertSame(1, $admission->activeCount());
            $this->expectException(SlowDownException::class);
            $admission->acquire();
        } finally {
            $release->complete();
            delay(0.001);
            self::assertSame(0, $admission->activeCount());
        }
    }

    public function test_timed_out_arm_stays_lost_and_keeps_permit_until_late_commit_finishes(): void
    {
        $planned = self::intent();
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $release = new DeferredFuture();
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->method('reserve')->willReturn(self::withLease($planned, 100.0));
        $sql->expects(self::once())->method('arm')->willReturnCallback(
            static function (ObjectWriteIntent $current, string $dispatchId) use ($release): ObjectWriteIntent {
                $release->getFuture()->await();
                return self::withLease($current, 200.0, $dispatchId);
            },
        );
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);
        $guard = self::reserveGuard($planned, $sql, $renewer, $admission, 0.02, 0.5);
        try {
            $guard->arm();
            self::fail('A timed-out arm returned a dispatch token.');
        } catch (\Amp\CancelledException) {
            self::assertTrue($guard->isLost());
            $guard->close();
            self::assertSame(1, $admission->activeCount());
            $release->complete();
            delay(0.001);
            self::assertSame(0, $admission->activeCount());
            try {
                $guard->stopForCommit();
                self::fail('A late arm success revived the lease.');
            } catch (\RuntimeException) {
                self::assertTrue($guard->isLost());
            }
        }
    }

    public function test_timed_out_renewal_never_revives_and_stop_cannot_publish(): void
    {
        $planned = self::intent();
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->method('reserve')->willReturn(self::withLease($planned, 100.0));
        $sql->method('arm')->willReturnCallback(
            static fn(ObjectWriteIntent $current, string $dispatchId): ObjectWriteIntent => self::withLease($current, 200.0, $dispatchId),
        );
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);
        $renewer->expects(self::once())->method('renew')->willReturnCallback(
            static function (ObjectWriteIntent $current) use ($entered, $release): ObjectWriteIntent {
                $entered->complete();
                $release->getFuture()->await();
                return self::withLease($current, 300.0, $current->dispatchId);
            },
        );
        $guard = self::reserveGuard($planned, $sql, $renewer, $admission, 0.02, 0.03);
        $guard->arm();
        $entered->getFuture()->await();
        delay(0.04);
        self::assertTrue($guard->isLost());
        try {
            $guard->stopForCommit();
            self::fail('A timed-out renewal allowed metadata commit.');
        } catch (\RuntimeException) {
            $guard->close();
            self::assertSame(1, $admission->activeCount());
            $release->complete();
            delay(0.001);
            self::assertSame(0, $admission->activeCount());
        }
    }

    public function test_close_during_arm_cannot_return_dispatch_authorization(): void
    {
        $planned = self::intent();
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->method('reserve')->willReturn(self::withLease($planned, 100.0));
        $sql->method('arm')->willReturnCallback(
            static function (ObjectWriteIntent $current, string $dispatchId) use ($entered, $release): ObjectWriteIntent {
                $entered->complete();
                $release->getFuture()->await();
                return self::withLease($current, 200.0, $dispatchId);
            },
        );
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);
        $guard = self::reserveGuard($planned, $sql, $renewer, $admission, 0.1, 0.5);
        $arming = async(static fn() => $guard->arm());
        $entered->getFuture()->await();
        $closing = async(static function () use ($guard): void {
            $guard->close();
        });
        delay(0.001);
        $release->complete();
        $closing->await();
        try {
            $arming->await();
            self::fail('A stopped guard returned a dispatch token.');
        } catch (\RuntimeException) {
            self::assertSame(0, $admission->activeCount());
        }
    }

    public function test_stop_waits_for_in_flight_renewal_before_returning_commit_token(): void
    {
        $planned = self::intent();
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->method('reserve')->willReturn(self::withLease($planned, 100.0));
        $sql->method('arm')->willReturnCallback(
            static fn(ObjectWriteIntent $current, string $dispatchId): ObjectWriteIntent => self::withLease($current, 200.0, $dispatchId),
        );
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);
        $renewer->expects(self::once())->method('renew')->willReturnCallback(
            static function (ObjectWriteIntent $current) use ($entered, $release): ObjectWriteIntent {
                $entered->complete();
                $release->getFuture()->await();
                return self::withLease($current, 300.0, $current->dispatchId);
            },
        );
        $guard = self::reserveGuard($planned, $sql, $renewer, $admission, 0.2, 0.01);
        $armed = $guard->arm();
        $entered->getFuture()->await();
        $commit = async(static fn() => $guard->stopForCommit());
        delay(0.002);
        self::assertFalse($commit->isComplete());
        self::assertSame(1, $admission->activeCount());
        $release->complete();
        self::assertSame($armed->dispatchId, $commit->await()->dispatchId);
        $guard->close();
        self::assertSame(0, $admission->activeCount());
    }

    private static function intent(): ObjectWriteIntent
    {
        return new ObjectWriteIntent('11111111111111111111111111111111', 'bucket', 'generation', 'owner', 'STANDARD', '/objects/path', 'put');
    }

    private static function reserveGuard(
        ObjectWriteIntent $planned,
        LocalObjectWriteLeaseSql $sql,
        LocalObjectWriteLeaseRenewer $renewer,
        LocalObjectWriteLeaseAdmission $admission,
        float $timeout,
        float $interval,
    ): LocalObjectWriteLeaseGuard {
        // Production reserve() accepts only the concrete asynchronous Amp adapters.
        $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
        $guard = $method->invoke(null, $planned, $sql, $renewer, $admission, 2.0, $timeout, $interval);
        self::assertInstanceOf(LocalObjectWriteLeaseGuard::class, $guard);

        return $guard;
    }

    private static function withLease(ObjectWriteIntent $intent, float $leaseUntil, ?string $dispatchId = null): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            $intent->attemptId,
            $intent->bucket,
            $intent->bucketGeneration,
            $intent->ownerId,
            $intent->storageTier,
            $intent->storagePath,
            $intent->purpose,
            'active',
            $leaseUntil,
            0,
            $dispatchId,
        );
    }
}
