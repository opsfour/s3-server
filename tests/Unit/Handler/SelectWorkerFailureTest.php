<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\DeferredFuture;
use Amp\ByteStream\ReadableBuffer;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Cancellation;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Select\SelectSqlException;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class SelectWorkerFailureTest extends TestCase
{
    #[DataProvider('mismatchedSelectBodies')]
    public function test_select_rejects_physical_object_bytes_that_disagree_with_metadata(string $storedBody): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer($storedBody));
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?select&select-type=2'),
            [],
            '<SelectObjectContentRequest><Expression>SELECT * FROM s3object</Expression>'
            . '<ExpressionType>SQL</ExpressionType><InputSerialization><CSV/></InputSerialization>'
            . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InternalErrorException::class);

        (new SelectObjectContentHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchedSelectBodies(): iterable
    {
        yield 'larger than metadata' => ["a,b\nEXTRA"];
        yield 'shorter than metadata' => ['ab'];
    }

    public function test_invalid_sql_is_rejected_before_object_storage_is_read(): void
    {
        $bucket = new BucketInfo('bucket', 'owner');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            1024 * 1024,
            '"etag"',
            systemMetadata: ['storagePath' => 'large-object'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?select&select-type=2'),
            [],
            '<SelectObjectContentRequest><Expression>NOT SQL</Expression>'
            . '<ExpressionType>SQL</ExpressionType><InputSerialization><CSV/></InputSerialization>'
            . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQL expression');

        (new SelectObjectContentHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_only_sql_syntax_failure_is_reported_as_invalid_sql(): void
    {
        $sqlFailure = new TaskFailureException(SelectSqlException::class, 'Expected SELECT', 0, __FILE__, 1, []);
        $handler = $this->handler();

        try {
            $this->process($handler, $this->poolFor(Future::error($sqlFailure)));
            self::fail('Expected SQL error.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid SQL expression', $e->getMessage());
        }

        $executionFailure = new TaskFailureException(\RuntimeException::class, 'secret worker state', 0, __FILE__, 1, []);
        try {
            $this->process($handler, $this->poolFor(Future::error($executionFailure)));
            self::fail('Expected worker failure.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(InvalidArgumentException::class, $e);
            self::assertSame('S3 Select worker failed.', $e->getMessage());
        }
    }

    public function test_timed_out_worker_is_killed_and_returns_retryable_error(): void
    {
        $future = (new DeferredFuture())->getFuture();
        $worker = $this->createMock(Worker::class);
        $worker->expects(self::once())->method('submit')->willReturn(new Execution(
            new \OpsFour\S3Server\Parallel\SelectProcessingTask('', 'SELECT * FROM s3object', [], []),
            $this->createMock(Channel::class),
            $future,
        ));
        $worker->expects(self::once())->method('kill');

        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->method('getIdleWorkerCount')->willReturn(1);
        $pool->method('getWorker')->willReturn($worker);
        $handler = $this->handler(0.01);

        $this->expectException(SlowDownException::class);
        // TimeoutCancellation unrefs its own timer. A real worker supplies a
        // referenced I/O watcher; this fake pending future needs one too.
        $watcher = EventLoop::delay(0.1, static function (): void {});
        try {
            $this->process($handler, $pool);
        } finally {
            EventLoop::cancel($watcher);
        }
    }

    public function test_worker_startup_wait_has_a_timeout(): void
    {
        LateSelectWorker::$destroyed = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->method('getIdleWorkerCount')->willReturn(0);
        $pool->expects(self::once())->method('getWorker')->willReturnCallback(function (): Worker {
            \Amp\delay(0.1);

            return new LateSelectWorker();
        });

        $started = microtime(true);
        try {
            $this->process($this->handler(0.01), $pool);
            self::fail('Expected worker acquisition timeout.');
        } catch (SlowDownException $e) {
            self::assertLessThan(0.09, microtime(true) - $started);
            self::assertSame(503, $e->getHttpStatus());
        }

        // Let the delayed acquisition finish; the abandoned Future must not
        // leave an unhandled result or an active worker task behind.
        \Amp\delay(0.11);
        gc_collect_cycles();
        self::assertSame(1, LateSelectWorker::$destroyed);
    }

    public function test_client_close_cancellation_is_registered_once_per_connection(): void
    {
        $onClose = null;
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('onClose')->willReturnCallback(
            static function (\Closure $callback) use (&$onClose): void {
                $onClose = $callback;
            },
        );
        $client->method('isClosed')->willReturn(false);
        $request = new Request($client, 'POST', Http::new('http://127.0.0.1/bucket/key?select&select-type=2'));
        $handler = $this->handler();
        $method = new \ReflectionMethod($handler, 'clientCancellation');
        $first = $method->invoke($handler, $request);
        $second = $method->invoke($handler, $request);

        self::assertSame($first, $second);
        self::assertFalse($first->isRequested());
        self::assertNotNull($onClose);
        $onClose();
        self::assertTrue($second->isRequested());
    }

    public function test_client_cancellation_kills_an_active_worker(): void
    {
        $worker = $this->createMock(Worker::class);
        $worker->method('submit')->willReturn(new Execution(
            new \OpsFour\S3Server\Parallel\SelectProcessingTask('', 'SELECT * FROM s3object', [], []),
            $this->createMock(Channel::class),
            (new DeferredFuture())->getFuture(),
        ));
        $worker->expects(self::once())->method('kill');
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->method('getIdleWorkerCount')->willReturn(1);
        $pool->method('getWorker')->willReturn($worker);
        $close = new DeferredCancellation();
        $watcher = EventLoop::delay(0.01, static function () use ($close): void {
            $close->cancel();
        });
        try {
            $this->process($this->handler(), $pool, clientCancellation: $close->getCancellation());
            self::fail('Expected request cancellation.');
        } catch (SlowDownException $e) {
            self::assertSame('S3 Select request was cancelled.', $e->getMessage());
        } finally {
            EventLoop::cancel($watcher);
        }
    }

    public function test_real_worker_does_not_misreport_bad_input_as_invalid_sql(): void
    {
        $pool = new ContextWorkerPool(1);
        try {
            try {
                $this->process($this->handler(), $pool, '{invalid json', [
                    'expression' => 'SELECT * FROM s3object',
                    'inputSerialization' => ['format' => 'JSON', 'jsonType' => 'DOCUMENT'],
                    'outputSerialization' => ['format' => 'JSON'],
                ]);
                self::fail('Expected JSON parser failure.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid JSON input for S3 Select.', $e->getMessage());
            }

            try {
                $this->process($this->handler(), $pool, 'name,Ada', [
                    'expression' => 'NOT SQL',
                    'inputSerialization' => ['format' => 'CSV'],
                    'outputSerialization' => ['format' => 'CSV'],
                ]);
                self::fail('Expected SQL parser failure.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid SQL expression', $e->getMessage());
            }
        } finally {
            $pool->shutdown();
        }
    }

    public function test_real_timeout_kills_worker_and_pool_accepts_a_later_query(): void
    {
        $pool = new ContextWorkerPool(1);
        $workerExitWarnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$workerExitWarnings): bool {
            if ($severity === E_USER_WARNING && str_contains($message, 'Worker in pool crashed with exception on shutdown: The worker was killed')) {
                $workerExitWarnings[] = $message;

                return true;
            }

            return false;
        });
        try {
            $this->process($this->handler(), $pool);
            try {
                $this->process($this->handler(0.01), $pool, str_repeat("name,Ada\n", 100_000));
                self::fail('Expected worker timeout.');
            } catch (SlowDownException $e) {
                self::assertSame(503, $e->getHttpStatus());
            }

            $this->process($this->handler(), $pool);
            self::assertTrue($pool->isRunning());
            self::assertCount(1, $workerExitWarnings);
        } finally {
            $pool->kill();
            restore_error_handler();
        }
    }

    private function handler(float $timeout = 300.0): SelectObjectContentHandler
    {
        return new SelectObjectContentHandler(
            $this->createMock(MetadataStore::class),
            $this->createMock(StorageBackend::class),
            workerTimeoutSeconds: $timeout,
        );
    }

    /** @param Future<mixed> $future */
    private function poolFor(Future $future): WorkerPool
    {
        $worker = $this->createMock(Worker::class);
        $worker->method('submit')->willReturn(new Execution(
            new \OpsFour\S3Server\Parallel\SelectProcessingTask('', 'SELECT * FROM s3object', [], []),
            $this->createMock(Channel::class),
            $future,
        ));
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->method('getIdleWorkerCount')->willReturn(1);
        $pool->method('getWorker')->willReturn($worker);

        return $pool;
    }

    /**
     * @param array{expression: string, inputSerialization: array<string, mixed>, outputSerialization: array<string, mixed>}|null $config
     */
    private function process(
        SelectObjectContentHandler $handler,
        WorkerPool $pool,
        string $data = 'name,Ada',
        ?array $config = null,
        ?Cancellation $clientCancellation = null,
    ): void {
        $method = new \ReflectionMethod($handler, 'processInWorker');
        $method->invoke($handler, $pool, $data, $config ?? [
            'expression' => 'SELECT * FROM s3object',
            'inputSerialization' => ['format' => 'CSV'],
            'outputSerialization' => ['format' => 'CSV'],
        ], $clientCancellation);
    }
}

final class LateSelectWorker implements Worker
{
    public static int $destroyed = 0;

    public function __destruct()
    {
        self::$destroyed++;
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        throw new \LogicException('The timed-out request must not submit a task.');
    }

    public function shutdown(): void {}

    public function kill(): void {}
}
