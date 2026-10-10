<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class DeleteObjectsTransactionTest extends TestCase
{
    public function test_unexpected_error_rolls_back_all_batch_metadata_changes(): void
    {
        $path = sys_get_temp_dir() . '/s3-delete-rollback-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', 'Enabled');
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');

        try {
            $goodVersion = $metadata->putObjectVersioned('bucket', 'good.txt', 'owner', 1, 'etag', 'text/plain', '');
            $badVersion = $metadata->putObjectVersioned('bucket', 'bad.txt', 'owner', 1, 'etag', 'text/plain', '');
            $metadata->putObjectTagging('bucket', 'bad.txt', [['key' => 'fault', 'value' => 'trigger']], $badVersion);

            $connection = new \PDO('sqlite:' . $path);
            $connection->exec(<<<'SQL'
                CREATE TRIGGER fail_tag_delete BEFORE DELETE ON s3_tagging
                WHEN OLD.key_name = 'bad.txt'
                BEGIN SELECT RAISE(FAIL, 'simulated metadata failure'); END
                SQL);

            $client = $this->createMock(Client::class);
            $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
            $body = '<Delete><Object><Key>good.txt</Key><VersionId>' . $goodVersion . '</VersionId></Object>'
                . '<Object><Key>bad.txt</Key><VersionId>' . $badVersion . '</VersionId></Object></Delete>';
            $request = new Request($client, 'POST', Http::new('http://127.0.0.1/bucket?delete'), [], $body);
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            try {
                (new DeleteObjectsHandler($metadata, StorageTierRegistry::single($storage)))->handleRequest($request);
                self::fail('Expected the metadata failure to abort the batch.');
            } catch (\PDOException $exception) {
                self::assertStringContainsString('simulated metadata failure', $exception->getMessage());
            }

            self::assertNotNull($metadata->getObjectMetadataByVersion('bucket', 'good.txt', $goodVersion));
            self::assertNotNull($metadata->getObjectMetadataByVersion('bucket', 'bad.txt', $badVersion));
            self::assertSame([['key' => 'fault', 'value' => 'trigger']], $metadata->getObjectTagging('bucket', 'bad.txt', $badVersion));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
