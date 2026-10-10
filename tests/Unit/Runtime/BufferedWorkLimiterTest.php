<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\BufferedWorkLimiter;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class BufferedWorkLimiterTest extends TestCase
{
    public function test_contending_requests_fail_before_the_active_operation_finishes(): void
    {
        $first = BufferedWorkLimiter::acquire();
        try {
            $released = false;
            $release = async(static function () use ($first, &$released): void {
                delay(0.1);
                $first->release();
                $released = true;
            });

            $contenders = [];
            foreach (range(1, 100) as $_) {
                $contenders[] = async(static function (): bool {
                    try {
                        $lock = BufferedWorkLimiter::acquire();
                        $lock->release();

                        return false;
                    } catch (SlowDownException) {
                        return true;
                    }
                });
            }
            self::assertSame(array_fill(0, 100, true), array_map(
                static fn(\Amp\Future $future): bool => $future->await(),
                $contenders,
            ));
            self::assertFalse($released);
            $release->await();
        } finally {
            $first->release();
        }

        $next = BufferedWorkLimiter::acquire();
        $next->release();
    }

    public function test_releasing_a_lock_twice_does_not_admit_parallel_work(): void
    {
        $first = BufferedWorkLimiter::acquire();
        $first->release();
        $second = BufferedWorkLimiter::acquire();

        try {
            $first->release();
            $this->expectException(SlowDownException::class);
            BufferedWorkLimiter::acquire();
        } finally {
            $second->release();
        }
    }

    public function test_s3_select_returns_retryable_error_when_buffered_slot_is_busy(): void
    {
        $path = sys_get_temp_dir() . '/s3-buffered-limit-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $storage = new InMemoryBackend();
            $storage->createBucket('bucket');
            $write = $storage->putObject('bucket', 'table.csv', new ReadableBuffer("name\nAda\n"));
            $metadata->putObjectMetadata(
                bucket: 'bucket',
                key: 'table.csv',
                ownerId: 'owner',
                size: $write->size,
                etag: '"' . $write->md5Hex . '"',
                contentType: 'text/csv',
                storagePath: $write->path,
            );
            $handler = new SelectObjectContentHandler($metadata, $storage);
            $request = function (): Request {
                $request = new Request(
                    $this->createMock(Client::class),
                    'POST',
                    Http::new('http://127.0.0.1/bucket/table.csv?select&select-type=2'),
                    [],
                    '<SelectObjectContentRequest xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                    . '<Expression>SELECT * FROM s3object</Expression><ExpressionType>SQL</ExpressionType>'
                    . '<InputSerialization><CSV/></InputSerialization>'
                    . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>',
                );
                $request->setAttribute('s3.bucket', 'bucket');
                $request->setAttribute('s3.key', 'table.csv');
                $request->setAttribute('ownerId', 'owner');

                return $request;
            };

            $lock = BufferedWorkLimiter::acquire();
            try {
                $handler->handleRequest($request());
                self::fail('A buffered Select request must reject contention.');
            } catch (SlowDownException $error) {
                self::assertSame(503, $error->getHttpStatus());
            } finally {
                $lock->release();
            }

            self::assertSame(200, $handler->handleRequest($request())->getStatus());
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
