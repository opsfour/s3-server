<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Quota\QuotaProvider;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnonymousMutationOwnershipTest extends TestCase
{
    private const string ALL_USERS = 'http://acs.amazonaws.com/groups/global/AllUsers';

    /** @return iterable<string, array{string}> */
    public static function versioningModes(): iterable
    {
        yield 'enabled' => ['Enabled'];
        yield 'suspended' => ['Suspended'];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function deleteCases(): iterable
    {
        foreach (self::versioningModes() as $name => [$versioning]) {
            yield $name . ', direct' => [$versioning, false];
            yield $name . ', batch' => [$versioning, true];
        }
    }

    #[DataProvider('versioningModes')]
    public function test_anonymous_copy_uses_bucket_owner_for_object_acl_and_quota(string $versioning): void
    {
        [$metadata, $storage] = $this->copyFixture($versioning);
        $seenQuotaOwners = [];
        $provider = new class ($seenQuotaOwners) implements QuotaProvider {
            /** @param list<string> $seen */
            public function __construct(public array &$seen) {}

            public function quotaForOwner(string $ownerId): QuotaConfig
            {
                $this->seen[] = $ownerId;

                return new QuotaConfig();
            }

            public function hasQuotaForOwner(string $ownerId): bool
            {
                return true;
            }
        };
        $handler = new CopyObjectHandler($metadata, $storage, quotas: new QuotaManager($metadata, $provider));

        self::assertSame(200, $handler->handleRequest($this->copyRequest(''))->getStatus());

        $copied = $metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($copied);
        self::assertSame('bucket-owner', $copied->ownerId);
        self::assertNotContains('', $provider->seen);
        $acl = $metadata->getAcl('object', 'target/copied?versionId=' . rawurlencode($copied->versionId ?? 'null'));
        self::assertSame('bucket-owner', $acl[0]['granteeId'] ?? null);
    }

    #[DataProvider('versioningModes')]
    public function test_authenticated_cross_account_copy_keeps_requester_as_object_owner(string $versioning): void
    {
        [$metadata, $storage] = $this->copyFixture($versioning);

        self::assertSame(
            200,
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->copyRequest('writer'))->getStatus(),
        );

        $copied = $metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($copied);
        self::assertSame('writer', $copied->ownerId);
    }

    #[DataProvider('deleteCases')]
    public function test_anonymous_delete_marker_is_owned_by_bucket_owner(string $versioning, bool $batch): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', $versioning);
        $metadata->putBucketPolicy('bucket', $this->publicPolicy('s3:DeleteObject', 'bucket'));
        $metadata->putAccountPolicy('writer', $this->identityPolicy(['s3:DeleteObject'], 'bucket'));
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $tiers = StorageTierRegistry::single($storage);

        if ($batch) {
            $request = $this->request('POST', 'http://127.0.0.1/bucket?delete', '');
            $request->setBody(new ReadableBuffer('<Delete><Object><Key>key</Key></Object></Delete>'));
            (new DeleteObjectsHandler($metadata, $tiers))->handleRequest($request);
        } else {
            $request = $this->request('DELETE', 'http://127.0.0.1/bucket/key', '');
            $request->setAttribute('s3.key', 'key');
            (new DeleteObjectHandler($metadata, $tiers))->handleRequest($request);
        }

        $markers = $metadata->listObjectVersions('bucket')['deleteMarkers'];
        self::assertCount(1, $markers);
        self::assertSame('bucket-owner', $markers[0]->ownerId);
    }

    #[DataProvider('deleteCases')]
    public function test_authenticated_cross_account_delete_marker_keeps_requester_owner(string $versioning, bool $batch): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', $versioning);
        $metadata->putBucketPolicy('bucket', $this->publicPolicy('s3:DeleteObject', 'bucket'));
        $metadata->putAccountPolicy('writer', $this->identityPolicy(['s3:DeleteObject'], 'bucket'));
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $tiers = StorageTierRegistry::single($storage);

        if ($batch) {
            $request = $this->request('POST', 'http://127.0.0.1/bucket?delete', 'writer');
            $request->setBody(new ReadableBuffer('<Delete><Object><Key>key</Key></Object></Delete>'));
            (new DeleteObjectsHandler($metadata, $tiers))->handleRequest($request);
        } else {
            $request = $this->request('DELETE', 'http://127.0.0.1/bucket/key', 'writer');
            $request->setAttribute('s3.key', 'key');
            (new DeleteObjectHandler($metadata, $tiers))->handleRequest($request);
        }

        $markers = $metadata->listObjectVersions('bucket')['deleteMarkers'];
        self::assertCount(1, $markers);
        self::assertSame('writer', $markers[0]->ownerId);
    }

    #[DataProvider('deleteCases')]
    public function test_delete_marker_ownership_tracks_current_bucket_ownership_mode(string $versioning, bool $batch): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', $versioning);
        $metadata->putBucketPolicy('bucket', $this->publicPolicy('s3:DeleteObject', 'bucket'));
        $metadata->putAccountPolicy('writer', $this->identityPolicy(['s3:DeleteObject'], 'bucket'));
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $tiers = StorageTierRegistry::single($storage);

        $metadata->putBucketOwnershipControls('bucket', BucketOwnership::BUCKET_OWNER_ENFORCED);
        $this->deleteKey($metadata, $tiers, 'enforced', 'writer', $batch);

        $metadata->putBucketOwnershipControls('bucket', BucketOwnership::OBJECT_WRITER);
        $this->deleteKey($metadata, $tiers, 'writer-owned', 'writer', $batch);

        $ownersByKey = [];
        foreach ($metadata->listObjectVersions('bucket')['deleteMarkers'] as $marker) {
            $ownersByKey[$marker->key] = $marker->ownerId;
        }
        self::assertSame('bucket-owner', $ownersByKey['enforced'] ?? null);
        self::assertSame('writer', $ownersByKey['writer-owned'] ?? null);
    }

    #[DataProvider('deleteCases')]
    public function test_delete_marker_uses_mode_that_becomes_active_before_commit(string $versioning, bool $batch): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', $versioning);
        $metadata->putBucketPolicy('bucket', $this->publicPolicy('s3:DeleteObject', 'bucket'));
        $metadata->putAccountPolicy('writer', $this->identityPolicy(['s3:DeleteObject'], 'bucket'));
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $tiers = StorageTierRegistry::single($storage);
        $commitStore = $this->switchOwnershipBeforeTransaction(
            $metadata,
            BucketOwnership::BUCKET_OWNER_ENFORCED,
        );

        $this->deleteKey($commitStore, $tiers, 'key', 'writer', $batch);

        $markers = $metadata->listObjectVersions('bucket')['deleteMarkers'];
        self::assertCount(1, $markers);
        self::assertSame('bucket-owner', $markers[0]->ownerId);
    }

    /** @return array{SqliteMetadataStore, InMemoryBackend} */
    private function copyFixture(string $versioning): array
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('source-owner', 'source', 'us-east-1');
        $metadata->createBucket('bucket-owner', 'target', 'us-east-1');
        $metadata->setBucketVersioning('target', $versioning);
        $metadata->putBucketPolicy('source', $this->publicPolicy('s3:GetObject', 'source'));
        $metadata->putBucketPolicy('target', $this->publicPolicy('s3:PutObject', 'target'));
        $metadata->putAccountPolicy('writer', $this->identityPolicy(['s3:GetObject', 's3:PutObject'], '*'));
        $storage = new InMemoryBackend();
        $storage->createBucket('source');
        $storage->createBucket('target');
        $source = $storage->putObject('source', 'key', new ReadableBuffer('payload'));
        $metadata->putObjectMetadata(
            'source',
            'key',
            'source-owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'text/plain',
            $source->path,
        );
        $metadata->putAcl('object', 'source/key?versionId=null', 'source-owner', [[
            'granteeType' => 'Group',
            'granteeId' => self::ALL_USERS,
            'permission' => 'READ',
        ]]);
        $metadata->putAcl('bucket', 'target', 'bucket-owner', [[
            'granteeType' => 'Group',
            'granteeId' => self::ALL_USERS,
            'permission' => 'WRITE',
        ]]);

        return [$metadata, $storage];
    }

    private function copyRequest(string $ownerId): Request
    {
        $request = $this->request('PUT', 'http://127.0.0.1/target/copied', $ownerId, [
            'x-amz-copy-source' => '/source/key',
        ]);
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');

        return $request;
    }

    private function deleteKey(
        MetadataStore $metadata,
        StorageTierRegistry $tiers,
        string $key,
        string $ownerId,
        bool $batch,
    ): void {
        if ($batch) {
            $request = $this->request('POST', 'http://127.0.0.1/bucket?delete', $ownerId);
            $request->setBody(new ReadableBuffer(
                '<Delete><Object><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key></Object></Delete>',
            ));
            (new DeleteObjectsHandler($metadata, $tiers))->handleRequest($request);

            return;
        }

        $request = $this->request('DELETE', 'http://127.0.0.1/bucket/' . rawurlencode($key), $ownerId);
        $request->setAttribute('s3.key', $key);
        (new DeleteObjectHandler($metadata, $tiers))->handleRequest($request);
    }

    private function switchOwnershipBeforeTransaction(
        SqliteMetadataStore $metadata,
        string $mode,
    ): MetadataStore {
        $proxy = $this->createMock(MetadataStore::class);
        foreach ((new \ReflectionClass(MetadataStore::class))->getMethods() as $method) {
            $name = $method->getName();
            if ($name === 'transaction') {
                continue;
            }
            $proxy->method($name)->willReturnCallback(
                static fn(mixed ...$arguments): mixed => $metadata->{$name}(...$arguments),
            );
        }
        $proxy->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($metadata, $mode): mixed {
                $metadata->putBucketOwnershipControls('bucket', $mode);

                return $metadata->transaction($callback);
            },
        );

        return $proxy;
    }

    private function publicPolicy(string $action, string $bucket): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => $action,
                'Resource' => 'arn:aws:s3:::' . $bucket . '/*',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $actions */
    private function identityPolicy(array $actions, string $bucket): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => $actions,
                'Resource' => 'arn:aws:s3:::' . $bucket . '/*',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, string $uri, string $ownerId, array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new($uri), $headers);
        $request->setAttribute('s3.bucket', str_contains($uri, '/target') ? 'target' : 'bucket');
        $request->setAttribute('ownerId', $ownerId);
        $request->setAttribute('credential', null);

        return $request;
    }
}
