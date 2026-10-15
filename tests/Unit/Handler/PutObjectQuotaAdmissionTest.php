<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Quota\QuotaProvider;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class PutObjectQuotaAdmissionTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
    }

    public function test_known_length_over_bucket_owner_quota_fails_before_backend_for_foreign_writer(): void
    {
        $this->metadata->putObjectMetadata('bucket', 'existing', 'bucket-owner', 5, '"old"', 'text/plain', 'old-path');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $this->expectException(QuotaExceededException::class);
        $this->handler($storage, [
            'bucket-owner' => new QuotaConfig(maxBytesPerBucket: 5),
        ])->handleRequest($this->request('writer', 'new', 'x'));
    }

    public function test_known_length_over_writer_owner_quota_fails_before_backend_across_buckets(): void
    {
        $this->metadata->createBucket('writer', 'writer-bucket', 'us-east-1');
        $this->metadata->putObjectMetadata('writer-bucket', 'existing', 'writer', 5, '"old"', 'text/plain', 'old-path');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $this->expectException(QuotaExceededException::class);
        $this->handler($storage, [
            'writer' => new QuotaConfig(maxBytesPerOwner: 5),
        ])->handleRequest($this->request('writer', 'new', 'x'));
    }

    public function test_unversioned_overwrite_uses_size_delta_during_preflight(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $old = $storage->putObject('bucket', 'key', new ByteStream\ReadableBuffer('old!!'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'bucket-owner',
            $old->size,
            '"' . $old->md5Hex . '"',
            'text/plain',
            $old->path,
        );

        $response = $this->handler($storage, [
            'bucket-owner' => new QuotaConfig(maxObjectsPerBucket: 1, maxBytesPerBucket: 5, maxBytesPerOwner: 5),
        ])->handleRequest($this->request('bucket-owner', 'key', 'new!!'));

        self::assertSame(200, $response->getStatus());
        self::assertSame(5, $this->metadata->getObjectMetadata('bucket', 'key')?->size);
    }

    public function test_enabled_versioning_counts_same_key_as_a_new_object_before_backend(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectVersioned('bucket', 'key', 'bucket-owner', 1, '"old"', 'text/plain', 'old-path');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $this->expectException(QuotaExceededException::class);
        $this->handler($storage, [
            'bucket-owner' => new QuotaConfig(maxObjectsPerBucket: 1),
        ])->handleRequest($this->request('bucket-owner', 'key', 'x'));
    }

    public function test_suspended_versioning_does_not_treat_numbered_current_version_as_replaceable(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectVersioned('bucket', 'key', 'bucket-owner', 1, '"old"', 'text/plain', 'old-path');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $this->expectException(QuotaExceededException::class);
        $this->handler($storage, [
            'bucket-owner' => new QuotaConfig(maxObjectsPerBucket: 1),
        ])->handleRequest($this->request('bucket-owner', 'key', 'x'));
    }

    public function test_final_transaction_check_rejects_quota_consumed_after_preflight_and_cleans_write(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            function (string $bucket, string $key, ByteStream\ReadableStream $body): StorageWriteResult {
                self::assertSame('12345', ByteStream\buffer($body));
                $this->metadata->putObjectMetadata(
                    $bucket,
                    'racing-winner',
                    'bucket-owner',
                    1,
                    '"winner"',
                    'text/plain',
                    'winner-path',
                );

                return new StorageWriteResult('rejected-path', 5, md5('12345'));
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->with('rejected-path', 'bucket');

        try {
            $this->handler($storage, [
                'bucket-owner' => new QuotaConfig(maxBytesPerBucket: 5),
            ])->handleRequest($this->request('bucket-owner', 'loser', '12345'));
            self::fail('The final quota check must reject capacity consumed after preflight.');
        } catch (QuotaExceededException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'loser'));
            self::assertSame(1, $this->metadata->getObjectMetadata('bucket', 'racing-winner')?->size);
        }
    }

    /** @param array<string, QuotaConfig> $quotas */
    private function handler(StorageBackend $storage, array $quotas): PutObjectHandler
    {
        $provider = $this->createMock(QuotaProvider::class);
        $provider->method('quotaForOwner')->willReturnCallback(
            static fn(string $ownerId): QuotaConfig => $quotas[$ownerId] ?? new QuotaConfig(),
        );
        $provider->method('hasQuotaForOwner')->willReturnCallback(
            static fn(string $ownerId): bool => ($quotas[$ownerId] ?? new QuotaConfig())->enabled(),
        );

        return new PutObjectHandler(
            $this->metadata,
            $storage,
            quotas: new QuotaManager($this->metadata, $provider),
        );
    }

    private function request(string $ownerId, string $key, string $body): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/' . $key),
            ['content-length' => (string) strlen($body)],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', $key);
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', $ownerId);

        return $request;
    }
}
