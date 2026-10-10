<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

/** A backing S3 service can commit a received PUT after the client times out. */
final class RemoteMultipartLateWriteFaultTest extends TestCase
{
    public function test_retained_part_intent_catches_remote_commit_after_timeout_and_absence_check(): void
    {
        $temp = sys_get_temp_dir() . '/s3-late-write-' . bin2hex(random_bytes(6));
        mkdir($temp, 0o700, true);
        $databasePath = $temp . '/metadata.sqlite';
        $port = self::unusedPort();
        $origin = new DelayedMultipartPutOrigin();
        $server = SocketHttpServer::createForDirectAccess(new NullLogger(), enableCompression: false);
        $server->expose("127.0.0.1:{$port}");
        $server->start($origin, new DefaultErrorHandler());
        $backend = null;

        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $uploadId = bin2hex(random_bytes(16));
            $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
            $generation = $metadata->getBucket('bucket')?->generation;
            self::assertNotNull($generation);
            $backend = new ParallelFlysystemBackend(
                new AwsS3FlysystemFilesystemFactory(
                    remoteBucket: 'remote-bucket',
                    region: 'us-east-1',
                    accessKeyId: 'local-key',
                    secretAccessKey: 'local-secret',
                    endpoint: "http://127.0.0.1:{$port}",
                    pathStyle: true,
                    connectTimeoutSeconds: 1,
                    requestTimeoutSeconds: 1,
                    allowInsecureHttpForTests: true,
                ),
                $temp,
                1,
            );
            $path = $backend->planMultipartPartPath('bucket', $generation, $uploadId, 1, bin2hex(random_bytes(16)));
            $metadata->createMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);

            $write = async(static fn() => $backend->putPartAtPath(
                'bucket',
                'key',
                $uploadId,
                1,
                new ReadableBuffer('ciphertext'),
                $path,
            ));
            $origin->waitForAcceptedPut();
            try {
                $write->await(new \Amp\TimeoutCancellation(20));
                self::fail('The backing PUT unexpectedly completed before the remote service replied.');
            } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
                self::assertGreaterThanOrEqual(1, $origin->acceptedPutCount());
            }

            self::forceDue($databasePath, $path);
            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend));
            self::assertSame(1, $collector->collect());
            self::assertFalse($origin->hasObject($path));
            self::assertSame(1, self::intentCount($databasePath, $path));

            // The remote service had accepted the request and now commits it
            // after the client failure, inline delete, and collector probe.
            $origin->releasePendingPuts();
            $origin->waitForAllPendingWrites();
            $origin->waitForObject($path);
            self::assertSame('ciphertext', buffer($backend->getObjectByPath($path)));
            self::forceDue($databasePath, $path);
            self::assertSame(1, $collector->collect());
            self::assertFalse($origin->hasObject($path));
            self::assertSame(1, self::intentCount($databasePath, $path));

            $recoveryPath = $backend->planMultipartPartPath('bucket', $generation, $uploadId, 2, bin2hex(random_bytes(16)));
            $recovered = $backend->putPartAtPath('bucket', 'key', $uploadId, 2, new ReadableBuffer('recovered'), $recoveryPath);
            self::assertSame($recoveryPath, $recovered->path);
            self::assertSame('recovered', buffer($backend->getObjectByPath($recoveryPath)));
            $backend->deleteObjectByPath($recoveryPath, 'bucket');
            self::assertFalse($origin->hasObject($recoveryPath));
        } finally {
            $origin->releasePendingPuts();
            $backend?->shutdown();
            $server->stop();
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($temp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($temp);
        }
    }

    private static function forceDue(string $databasePath, string $path): void
    {
        $query = (new \PDO('sqlite:' . $databasePath))->prepare(
            "UPDATE s3_storage_garbage SET status = 'pending', next_attempt_at = 0
             WHERE operation = 'part_write_intent' AND storage_path = ?",
        );
        self::assertInstanceOf(\PDOStatement::class, $query);
        $query->execute([$path]);
    }

    private static function intentCount(string $databasePath, string $path): int
    {
        $query = (new \PDO('sqlite:' . $databasePath))->prepare(
            "SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND storage_path = ?",
        );
        self::assertInstanceOf(\PDOStatement::class, $query);
        $query->execute([$path]);

        return (int) $query->fetchColumn();
    }

    private static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}

/** Loopback S3 endpoint with an accepted PUT held past client timeout. */
final class DelayedMultipartPutOrigin implements RequestHandler
{
    /** @var array<string, string> */
    private array $objects = [];

    private int $acceptedPuts = 0;

    /** @var DeferredFuture<void> */
    private DeferredFuture $accepted;

    /** @var DeferredFuture<void> */
    private DeferredFuture $release;

    /** @var list<Future<void>> */
    private array $pending = [];

    public function __construct()
    {
        $this->accepted = new DeferredFuture();
        $this->release = new DeferredFuture();
    }

    public function handleRequest(Request $request): Response
    {
        $path = rawurldecode($request->getUri()->getPath());
        $path = substr($path, strlen('/remote-bucket/'));
        if ($request->getMethod() === 'PUT') {
            $body = buffer($request->getBody());
            $this->acceptedPuts++;
            if (! $this->accepted->isComplete()) {
                $this->accepted->complete();
            }
            $this->pending[] = async(function () use ($path, $body): void {
                $this->release->getFuture()->await();
                $this->objects[$path] = $body;
            });
            $this->release->getFuture()->await();

            return new Response(200, ['ETag' => '"' . md5($body) . '"']);
        }
        if ($request->getMethod() === 'DELETE') {
            unset($this->objects[$path]);

            return new Response(204);
        }
        if (! isset($this->objects[$path])) {
            return new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>');
        }
        $body = $this->objects[$path];

        return new Response(200, ['Content-Length' => (string) strlen($body)], $request->getMethod() === 'HEAD' ? '' : $body);
    }

    public function waitForAcceptedPut(): void
    {
        $this->accepted->getFuture()->await(new \Amp\TimeoutCancellation(5));
    }

    public function acceptedPutCount(): int
    {
        return $this->acceptedPuts;
    }

    public function releasePendingPuts(): void
    {
        if (! $this->release->isComplete()) {
            $this->release->complete();
        }
    }

    public function waitForAllPendingWrites(): void
    {
        foreach ($this->pending as $write) {
            $write->await(new \Amp\TimeoutCancellation(5));
        }
    }

    public function waitForObject(string $path): void
    {
        $deadline = microtime(true) + 5;
        while (! isset($this->objects[$path]) && microtime(true) < $deadline) {
            delay(0.01);
        }
        TestCase::assertArrayHasKey($path, $this->objects);
    }

    public function hasObject(string $path): bool
    {
        return isset($this->objects[$path]);
    }
}
