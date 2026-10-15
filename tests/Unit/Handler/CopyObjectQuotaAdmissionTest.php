<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\ConfigQuotaProvider;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Quota\QuotaProvider;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CopyObjectQuotaAdmissionTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('target-owner', 'source', 'us-east-1');
        $this->metadata->createBucket('target-owner', 'target', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('source');
        $this->storage->createBucket('target');
        $this->store('source', 'key', 'target-owner', 'payload');
    }

    public function test_known_impossible_copy_is_rejected_before_destination_storage(): void
    {
        $storage = new CallbackStorageBackend($this->storage);
        $quotas = new QuotaManager(
            $this->metadata,
            new ConfigQuotaProvider(new QuotaConfig(maxBytesPerBucket: 6)),
        );

        try {
            (new CopyObjectHandler($this->metadata, $storage, quotas: $quotas))
                ->handleRequest($this->request());
            self::fail('The known over-quota copy must be rejected.');
        } catch (QuotaExceededException) {
            self::assertSame(0, $storage->copyCalls);
            self::assertSame(0, $storage->putCalls);
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function replacingModes(): iterable
    {
        yield 'unversioned' => [null];
        yield 'suspended' => ['Suspended'];
    }

    #[DataProvider('replacingModes')]
    public function test_replacing_null_version_uses_only_the_net_quota_delta(?string $versioning): void
    {
        if ($versioning !== null) {
            $this->metadata->setBucketVersioning('target', $versioning);
        }
        $this->store('target', 'copied', 'target-owner', '1234567');
        $quotas = new QuotaManager(
            $this->metadata,
            new ConfigQuotaProvider(new QuotaConfig(maxObjectsPerBucket: 1, maxBytesPerBucket: 7)),
        );

        $response = (new CopyObjectHandler($this->metadata, $this->storage, quotas: $quotas))
            ->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        self::assertSame(7, $this->metadata->getObjectMetadata('target', 'copied')?->size);
    }

    public function test_enabled_versioning_preflight_counts_the_new_version(): void
    {
        $this->metadata->setBucketVersioning('target', 'Enabled');
        $this->storeVersioned('target', 'copied', 'target-owner', '1234567');
        $storage = new CallbackStorageBackend($this->storage);
        $quotas = new QuotaManager(
            $this->metadata,
            new ConfigQuotaProvider(new QuotaConfig(maxObjectsPerBucket: 1, maxBytesPerBucket: 7)),
        );

        $this->expectException(QuotaExceededException::class);
        try {
            (new CopyObjectHandler($this->metadata, $storage, quotas: $quotas))
                ->handleRequest($this->request());
        } finally {
            self::assertSame(0, $storage->copyCalls);
            self::assertSame(0, $storage->putCalls);
        }
    }

    public function test_final_transaction_check_rejects_quota_that_tightens_after_preflight(): void
    {
        $provider = new class implements QuotaProvider {
            private int $reads = 0;

            public function quotaForOwner(string $ownerId): QuotaConfig
            {
                $this->reads++;

                return new QuotaConfig(maxBytesPerBucket: $this->reads <= 2 ? 7 : 6);
            }

            public function hasQuotaForOwner(string $ownerId): bool
            {
                return true;
            }
        };
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new CopyObjectHandler(
                $this->metadata,
                $storage,
                quotas: new QuotaManager($this->metadata, $provider),
            ))->handleRequest($this->request());
            self::fail('The final transaction check must observe the tightened quota.');
        } catch (QuotaExceededException) {
            self::assertSame(1, $storage->copyCalls);
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
            self::assertNull($storage->lastWrite === null
                ? null
                : $this->readPathOrNull($storage->lastWrite->path));
        }
    }

    private function store(string $bucket, string $key, string $owner, string $payload): void
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($payload));
        $this->metadata->putObjectMetadata(
            $bucket,
            $key,
            $owner,
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
    }

    private function storeVersioned(string $bucket, string $key, string $owner, string $payload): void
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($payload));
        $this->metadata->putObjectVersioned(
            $bucket,
            $key,
            $owner,
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
    }

    private function readPathOrNull(string $path): ?string
    {
        try {
            return \Amp\ByteStream\buffer($this->storage->getObjectByPath($path));
        } catch (\Throwable) {
            return null;
        }
    }

    private function request(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/target/copied'),
            ['x-amz-copy-source' => '/source/key'],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'target-owner');

        return $request;
    }
}
