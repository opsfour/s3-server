<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\MultipartPartIntentCommand;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\StoragePath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MultipartPartIntentCommandTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-part-intent-inventory-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_inventory_is_bounded_owner_scoped_and_does_not_disclose_paths(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $firstPath = $this->reserve($metadata, 'owner-a', 'bucket-a', 1);
        $secondPath = $this->reserve($metadata, 'owner-a', 'bucket-a', 2);
        $otherPath = $this->reserve($metadata, 'owner-b', 'bucket-b', 1);

        $pageOne = $metadata->listMultipartPartWriteIntents('owner-a', 0, 1);
        self::assertCount(1, $pageOne);
        self::assertSame(hash('sha256', $firstPath), $pageOne[0]['path_sha256']);
        self::assertArrayNotHasKey('storage_path', $pageOne[0]);
        $pageTwo = $metadata->listMultipartPartWriteIntents('owner-a', $pageOne[0]['id'], 1);
        self::assertCount(1, $pageTwo);
        self::assertSame(hash('sha256', $secondPath), $pageTwo[0]['path_sha256']);
        self::assertSame([], $metadata->listMultipartPartWriteIntents('owner-a', $pageTwo[0]['id'], 1));
        self::assertSame(hash('sha256', $otherPath), $metadata->listMultipartPartWriteIntents('owner-b', 0, 1)[0]['path_sha256']);

        $tester = new CommandTester(new MultipartPartIntentCommand());
        $exit = $tester->execute([
            'owner-id' => 'owner-a',
            '--metadata-path' => $this->databasePath,
            '--limit' => '1',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString(hash('sha256', $firstPath), $tester->getDisplay());
        self::assertStringNotContainsString($firstPath, $tester->getDisplay());
        self::assertStringNotContainsString($secondPath, $tester->getDisplay());
        self::assertStringNotContainsString($otherPath, $tester->getDisplay());
        self::assertStringNotContainsString('bucket-b', $tester->getDisplay());
        self::assertStringContainsString('Rows remain durable', $tester->getDisplay());
        self::assertCount(2, $metadata->listMultipartPartWriteIntents('owner-a', 0, 10));
    }

    public function test_invalid_page_options_are_rejected_without_mutation(): void
    {
        foreach ([['--after-id' => '-1'], ['--limit' => '0'], ['--limit' => '501'], ['--limit' => 'abc']] as $option) {
            $tester = new CommandTester(new MultipartPartIntentCommand());
            $exit = $tester->execute([
                'owner-id' => 'owner-a',
                '--metadata-path' => $this->databasePath,
                ...$option,
            ]);
            self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        }
        self::assertFileDoesNotExist($this->databasePath);
    }

    private function reserve(SqliteMetadataStore $metadata, string $ownerId, string $bucket, int $partNumber): string
    {
        if ($metadata->getBucket($bucket) === null) {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
        }
        $generation = $metadata->getBucket($bucket)?->generation;
        self::assertNotNull($generation);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, $bucket, 'key', $ownerId);
        $path = StoragePath::forPlannedPart('', $bucket, $generation, $uploadId, $partNumber, bin2hex(random_bytes(16)));
        $metadata->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $path);

        return $path;
    }
}
