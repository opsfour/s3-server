<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Notification;

use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use PHPUnit\Framework\TestCase;

final class QualifiedNotificationDispatcherTest extends TestCase
{
    private const string RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string SOURCE_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string BOOT_ID = '12345678-1234-1234-1234-123456789abc';

    private const int NOW_NS = 10_000_000_000_000;

    private string $base;

    private string $evidenceDirectory;

    private string $storageRoot;

    private string $evidencePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        parent::setUp();
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->base = $temporaryRoot . '/s3-notification-evidence-' . bin2hex(random_bytes(8));
        $this->evidenceDirectory = $this->base . '/evidence';
        $this->storageRoot = $this->base . '/storage';
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-postgres.json';
        mkdir($this->base, 0700);
        mkdir($this->evidenceDirectory, 0755);
        mkdir($this->storageRoot, 0700);
        $storage = stat($this->storageRoot);
        self::assertIsArray($storage);
        file_put_contents($this->evidencePath, json_encode([
            'schemaVersion' => 1,
            'runId' => self::RUN_ID,
            'efsId' => 'fs-12345678',
            'sourceSha256' => self::SOURCE_SHA,
            'node' => 'api-one',
            'driver' => 'postgres',
            'containerId' => str_repeat('c', 64),
            'containerPid' => 1234,
            'relayPort' => 20049,
            'relayPid' => 5432,
            'hostMountId' => '39',
            'storageDev' => $storage['dev'],
            'storageIno' => $storage['ino'],
            'bootId' => self::BOOT_ID,
            'epoch' => str_repeat('e', 32),
            'issuedMonotonicNs' => self::NOW_NS,
            'expiresMonotonicNs' => self::NOW_NS + 20_000_000_000,
        ], JSON_THROW_ON_ERROR) . "\n");
        chmod($this->evidencePath, 0644);
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        if (isset($this->base) && is_dir($this->base)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->base);
        }
        parent::tearDown();
    }

    public function test_candidate_requires_exact_resources_and_rejects_internal_listener_registration(): void
    {
        $reader = $this->reader();
        $dispatcher = new NotificationDispatcher($this->metadata, efsEvidence: $reader);
        $event = new S3Event('s3:ObjectCreated:Put', 'bucket', 'key', bucketGeneration: $this->generation());

        $this->assertThrows(\LogicException::class, fn() => $dispatcher->listen('s3:*', static function (): void {}));
        $this->assertThrows(\LogicException::class, fn() => $dispatcher->removeListeners());
        $this->assertThrows(\LogicException::class, fn() => $dispatcher->dispatchEvent($event));
        $this->assertThrows(\RuntimeException::class, fn() => $dispatcher->assertBoundTo(new SqliteMetadataStore(':memory:'), $reader));
        $this->assertThrows(\RuntimeException::class, fn() => $dispatcher->assertBoundTo($this->metadata, $this->reader()));

        $dispatcher->assertBoundTo($this->metadata, $reader);
        $this->assertThrows(\LogicException::class, fn() => $dispatcher->listen('s3:*', static function (): void {}));
        $this->assertThrows(\LogicException::class, fn() => $dispatcher->removeListeners('s3:*'));
        $dispatcher->dispatchEvent($event, enqueueWebhooks: false);
    }

    public function test_tripped_watcher_rejects_event_before_sql_or_listener_dispatch(): void
    {
        $reader = $this->reader();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $metadata->expects(self::never())->method('transaction');
        $dispatcher = new NotificationDispatcher($metadata, efsEvidence: $reader);
        $dispatcher->assertBoundTo($metadata, $reader);
        self::assertTrue(unlink($this->evidencePath));
        $event = new S3Event('s3:ObjectCreated:Put', 'bucket', 'key', bucketGeneration: 'generation');

        $this->assertThrows(\RuntimeException::class, fn() => $dispatcher->enqueueWebhooks($event));
        // This post-commit call has no side effects because candidate listeners
        // are forbidden; a watcher trip must not change the committed response.
        $dispatcher->dispatchInternalEvent($event);
        $dispatcher->dispatchEvent($event, enqueueWebhooks: false);
    }

    public function test_fresh_sealed_candidate_can_enqueue_webhook_with_its_bound_store(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'configured',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);
        $reader = $this->reader();
        $dispatcher = new NotificationDispatcher($this->metadata, efsEvidence: $reader);
        $dispatcher->assertBoundTo($this->metadata, $reader);
        $dispatcher->enqueueWebhooks(new S3Event('s3:ObjectCreated:Put', 'bucket', 'key', bucketGeneration: $this->generation()));

        self::assertCount(1, $this->metadata->dequeueNotifications(1));
    }

    private function generation(): string
    {
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        return $bucket->generation;
    }

    private function reader(): EfsHostEvidenceReader
    {
        return new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            'postgres',
            self::SOURCE_SHA,
            static fn(): int => self::NOW_NS,
            static fn(): string => self::BOOT_ID,
            static function (string $path): array|false {
                $entry = @lstat($path);
                if (is_array($entry)) {
                    $entry['uid'] = 0;
                }
                return $entry;
            },
            static function ($handle): array|false {
                $entry = @fstat($handle);
                if (is_array($entry)) {
                    $entry['uid'] = 0;
                }
                return $entry;
            },
            fn(): string => "1 0 8:1 / / rw - ext4 /dev/root rw\n"
                . "2 1 0:42 / {$this->evidenceDirectory} ro - tmpfs tmpfs rw\n",
        );
    }

    /**
     * @param class-string<\Throwable> $class
     * @param \Closure(): void $operation
     */
    private function assertThrows(string $class, \Closure $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$class}.");
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);
        }
    }
}
