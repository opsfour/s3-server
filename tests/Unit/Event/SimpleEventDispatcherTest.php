<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Event;

use Amp\DeferredFuture;
use OpsFour\S3Server\Event\SimpleEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SimpleEventDispatcherTest extends TestCase
{
    public function test_dispatch_does_not_wait_for_a_concurrency_slot(): void
    {
        $firstStarted = new DeferredFuture();
        $release = new DeferredFuture();
        $calls = 0;
        $dispatcher = new SimpleEventDispatcher(1);
        $dispatcher->listen('event', static function () use (&$calls, $firstStarted, $release): void {
            $calls++;
            if ($calls === 1) {
                $firstStarted->complete();
                $release->getFuture()->await();
            }
        });

        $dispatcher->dispatch('event');
        $firstStarted->getFuture()->await(new \Amp\TimeoutCancellation(1));
        $secondDispatch = \Amp\async(static fn() => $dispatcher->dispatch('event'));
        $secondDispatch->await(new \Amp\TimeoutCancellation(0.1));
        self::assertSame(1, $calls);

        $release->complete();
        $dispatcher->shutdown(1);
        self::assertSame(2, $calls);
    }

    public function test_queue_limit_drops_excess_listener_without_payload_logging(): void
    {
        $release = new DeferredFuture();
        $logger = new EventArrayLogger();
        $calls = 0;
        $dispatcher = new SimpleEventDispatcher(1, 2, $logger);
        $dispatcher->listen('event', static function () use (&$calls, $release): void {
            $calls++;
            $release->getFuture()->await();
        });

        $dispatcher->dispatch('event', ['secret' => 'do-not-log']);
        $dispatcher->dispatch('event', ['secret' => 'do-not-log']);
        $dispatcher->dispatch('event', ['secret' => 'do-not-log']);
        $release->complete();
        $dispatcher->shutdown(1);

        self::assertSame(2, $calls);
        self::assertCount(1, $logger->records);
        self::assertSame('listener_task_dropped', $logger->records[0]['context']['event']);
        self::assertStringNotContainsString('do-not-log', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_shutdown_drains_nested_dispatch_and_throwing_logger_cannot_strand_queue(): void
    {
        $nestedCalls = 0;
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('private-logger-failure');
            }
        };
        $dispatcher = new SimpleEventDispatcher(1, 10, $logger);
        $dispatcher->listen('first', static function () use ($dispatcher): void {
            $dispatcher->dispatch('second');
            throw new \RuntimeException('private-listener-failure');
        });
        $dispatcher->listen('second', static function () use (&$nestedCalls): void {
            $nestedCalls++;
        });

        $dispatcher->dispatch('first');
        $dispatcher->shutdown(1);

        self::assertSame(1, $nestedCalls);
        $dispatcher->dispatch('second');
        $dispatcher->shutdown(1);
        self::assertSame(2, $nestedCalls);
    }

    public function test_existing_constructor_and_interface_shape_remain_usable(): void
    {
        self::assertInstanceOf(\OpsFour\S3Server\Contracts\EventDispatcher::class, new SimpleEventDispatcher());
        self::assertInstanceOf(\OpsFour\S3Server\Contracts\EventDispatcher::class, new SimpleEventDispatcher(1));
    }
}

final class EventArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
