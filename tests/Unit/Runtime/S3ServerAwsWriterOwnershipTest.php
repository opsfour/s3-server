<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Runtime\S3ServerRuntime;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3ObjectWriterShutdown;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class S3ServerAwsWriterOwnershipTest extends TestCase
{
    public function test_multi_writer_shutdown_retries_only_the_incomplete_writer(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $server = new S3Server(
            new S3ServerConfig(storagePath: sys_get_temp_dir()),
            $metadata,
            new InMemoryBackend(),
        );
        $runtime = new S3ServerRuntime($server, new NotificationDispatcher($metadata));
        $first = new CountingAwsWriterShutdown();
        $second = new CountingAwsWriterShutdown(failOnce: true);
        $runtime->attachAwsObjectWriter($first);
        $runtime->attachAwsObjectWriter($second);

        try {
            $runtime->stop();
            self::fail('The injected writer shutdown failure was not propagated.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('shutdown incomplete', $error->getMessage());
        }
        self::assertSame(0, $first->calls);
        self::assertSame(1, $second->calls);

        $runtime->stop();
        self::assertSame(1, $first->calls);
        self::assertSame(2, $second->calls);

        $runtime->stop();
        self::assertSame(1, $first->calls);
        self::assertSame(2, $second->calls);
    }
}

final class CountingAwsWriterShutdown implements AwsS3ObjectWriterShutdown
{
    public int $calls = 0;

    public function __construct(private bool $failOnce = false) {}

    public function shutdown(): void
    {
        ++$this->calls;
        if ($this->failOnce) {
            $this->failOnce = false;
            throw new \RuntimeException('injected shutdown failure');
        }
    }
}
