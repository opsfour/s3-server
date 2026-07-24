<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Logging;

use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class AccessLogWriterTest extends TestCase
{
    public function test_metadata_failure_deletes_or_queues_written_log_object_and_retains_buffer(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $storage = $this->createMock(StorageBackend::class);

        $metadata->method('getBucketLogging')->willReturn([
            'targetBucket' => 'log-target',
            'targetPrefix' => 'access/',
        ]);
        $metadata->method('getBucketOwner')->willReturn('owner');
        $metadata->method('putObjectMetadata')->willThrowException(new \RuntimeException('metadata unavailable'));
        $storage->method('putObject')->willReturn(new StorageWriteResult('/remote/log-object', 10, 'abc'));
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('storage unavailable'));
        $metadata->expects(self::once())
            ->method('enqueueStorageGarbage')
            ->with('log-target', 'HOT', '/remote/log-object');

        $writer = new AccessLogWriter($metadata, $storage, storageTier: 'HOT');
        $writer->log('source', 'object.txt', 'GetObject', 200, 10);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
    }
}
