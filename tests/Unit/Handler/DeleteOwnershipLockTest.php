<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Policy\ObjectDeletePolicyGuard;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeleteOwnershipLockTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    private StorageTierRegistry $tiers;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-delete-owner-lock-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
        $this->tiers = StorageTierRegistry::single($this->storage);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    /** @return iterable<string, array{bool}> */
    public static function deleteCases(): iterable
    {
        yield 'single' => [false];
        yield 'batch' => [true];
    }

    #[DataProvider('deleteCases')]
    public function test_delete_locks_persisted_owner_and_releases_its_usage(bool $batch): void
    {
        $this->put('key', 'foreign-owner', 'payload');
        self::assertSame(['objectCount' => 1, 'bytesUsed' => 7], $this->metadata->getOwnerStorageStats('foreign-owner'));
        $lockedOwners = [];
        $recordingStore = $this->proxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );

        $this->delete($recordingStore, $batch);

        self::assertSame(['bucket-owner', 'foreign-owner'], $lockedOwners);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $this->metadata->getOwnerStorageStats('foreign-owner'));
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
    }

    #[DataProvider('deleteCases')]
    public function test_explicit_version_delete_locks_historical_owner_and_releases_usage(bool $batch): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $write = $this->storage->putObject('bucket', 'key', new ReadableBuffer('historical'));
        $versionId = $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'historical-owner',
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
        $currentWrite = $this->storage->putObject('bucket', 'key', new ReadableBuffer('current'));
        $currentVersionId = $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'current-owner',
            $currentWrite->size,
            '"' . $currentWrite->md5Hex . '"',
            'text/plain',
            $currentWrite->path,
        );
        $lockedOwners = [];
        $recordingStore = $this->proxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );

        if ($batch) {
            $request = $this->request('POST', '/bucket?delete');
            $request->setBody(new ReadableBuffer(
                '<Delete><Object><Key>key</Key><VersionId>' . $versionId . '</VersionId></Object></Delete>',
            ));
            (new DeleteObjectsHandler($recordingStore, $this->tiers))->handleRequest($request);
        } else {
            (new DeleteObjectHandler($recordingStore, $this->tiers))->handleRequest(
                $this->request('DELETE', '/bucket/key?versionId=' . rawurlencode($versionId)),
            );
        }

        self::assertSame(['bucket-owner', 'historical-owner'], $lockedOwners);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $this->metadata->getOwnerStorageStats('historical-owner'));
        self::assertSame('current-owner', $this->metadata
            ->getObjectMetadataByVersion('bucket', 'key', $currentVersionId)?->ownerId);
    }

    #[DataProvider('deleteCases')]
    public function test_suspended_delete_locks_foreign_null_owner_before_replacement(bool $batch): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->put('key', 'null-owner', 'null payload');
        $lockedOwners = [];
        $recordingStore = $this->proxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );

        $this->delete($recordingStore, $batch);

        self::assertSame(['bucket-owner', 'null-owner'], $lockedOwners);
        $marker = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($marker);
        self::assertTrue($marker->isDeleteMarker);
        self::assertSame('bucket-owner', $marker->ownerId);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $this->metadata->getOwnerStorageStats('null-owner'));
    }

    #[DataProvider('deleteCases')]
    public function test_delete_aborts_before_mutation_when_post_lock_target_has_unlocked_owner(bool $batch): void
    {
        $this->put('key', 'observed-owner', 'observed');
        $replacement = $this->storage->putObject('bucket', 'key', new ReadableBuffer('replacement'));
        $replaced = false;
        $racingStore = $this->proxy(
            transaction: function (callable $callback) use (&$replaced, $replacement): mixed {
                if (! $replaced) {
                    $replaced = true;
                    $this->metadata->putObjectMetadata(
                        'bucket',
                        'key',
                        'replacement-owner',
                        $replacement->size,
                        '"' . $replacement->md5Hex . '"',
                        'text/plain',
                        $replacement->path,
                    );
                }

                return $this->metadata->transaction($callback);
            },
        );

        try {
            $this->delete($racingStore, $batch);
            self::fail('A replacement owned by an unlocked principal was deleted.');
        } catch (OperationAbortedException) {
            $current = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($current);
            self::assertSame('replacement-owner', $current->ownerId);
            self::assertSame($replacement->path, $current->systemMetadata['storagePath']);
        }
    }

    public function test_batch_excludes_denied_target_owners_and_reuses_each_phase_observation(): void
    {
        $this->put('allowed', 'requester', 'allowed');
        $this->put('blocked', 'unrelated-owner', 'blocked');
        $this->metadata->putAccountPolicy('requester', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/allowed',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'requester'],
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/allowed',
            ]],
        ], JSON_THROW_ON_ERROR));
        $lockedOwners = [];
        $lookups = [];
        $recordingStore = $this->proxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
            getObjectMetadata: function (string $bucket, string $key) use (&$lookups): mixed {
                $lookups[$key] = ($lookups[$key] ?? 0) + 1;

                return $this->metadata->getObjectMetadata($bucket, $key);
            },
        );
        $request = $this->request('POST', '/bucket?delete', ownerId: 'requester');
        $request->setBody(new ReadableBuffer(
            '<Delete><Object><Key>allowed</Key></Object><Object><Key>blocked</Key></Object></Delete>',
        ));

        $response = (new DeleteObjectsHandler($recordingStore, $this->tiers))->handleRequest($request);

        self::assertSame(['bucket-owner', 'requester'], $lockedOwners);
        self::assertSame(['allowed' => 2, 'blocked' => 2], $lookups);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'allowed'));
        self::assertSame('unrelated-owner', $this->metadata->getObjectMetadata('bucket', 'blocked')?->ownerId);
        self::assertStringContainsString('<Key>blocked</Key>', ByteStream\buffer($response->getBody()));
    }

    public function test_batch_delete_acquires_all_affected_owner_locks_in_global_order(): void
    {
        $this->put('one', 'owner-z', 'one');
        $this->put('two', 'owner-a', 'two');
        $lockedOwners = [];
        $recordingStore = $this->proxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );
        $request = $this->request('POST', '/bucket?delete');
        $request->setBody(new ReadableBuffer(
            '<Delete><Object><Key>one</Key></Object><Object><Key>two</Key></Object></Delete>',
        ));

        (new DeleteObjectsHandler($recordingStore, $this->tiers))->handleRequest($request);

        self::assertSame(['bucket-owner', 'owner-a', 'owner-z'], $lockedOwners);
    }

    public function test_batch_named_policy_preflight_uses_an_unlocked_snapshot(): void
    {
        $this->put('key', 'requester', 'payload');
        $this->metadata->putNamedPolicy('delete-key', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'requester'],
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
            ]],
        ], JSON_THROW_ON_ERROR));
        $request = $this->request('POST', '/bucket?delete', ownerId: 'requester');
        $request->setAttribute(
            'credential',
            new Credential('requester-key', 'secret', 'requester', policyNames: ['delete-key']),
        );
        $request->setBody(new ReadableBuffer('<Delete><Object><Key>key</Key></Object></Delete>'));

        $response = (new DeleteObjectsHandler($this->metadata, $this->tiers))->handleRequest($request);

        self::assertSame(200, $response->getStatus());
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
    }

    public function test_suspended_delete_without_null_version_ignores_current_numbered_tags(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $write = $this->storage->putObject('bucket', 'key', new ReadableBuffer('numbered'));
        $versionId = $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'requester',
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
        $this->metadata->putObjectTagging(
            'bucket',
            'key',
            [['key' => 'classification', 'value' => 'protected']],
            $versionId,
        );
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->metadata->putAccountPolicy('requester', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => 'requester'],
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ],
                [
                    'Effect' => 'Deny',
                    'Principal' => ['AWS' => 'requester'],
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                    'Condition' => [
                        'StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertFalse((new ObjectDeletePolicyGuard($this->metadata))->check(
            $this->request('DELETE', '/bucket/key', ownerId: 'requester'),
            'bucket',
            'key',
            null,
            'requester',
            'bucket-owner',
            resolvedTarget: null,
            targetResolved: true,
        ));
    }

    /** @return iterable<string, array{string, string, string|null, string|null}> */
    public static function mismatchedResolvedTargets(): iterable
    {
        yield 'bucket' => ['other-bucket', 'key', null, null];
        yield 'key' => ['bucket', 'other-key', null, null];
        yield 'explicit version' => ['bucket', 'key', 'other-version', 'requested-version'];
    }

    #[DataProvider('mismatchedResolvedTargets')]
    public function test_guard_rejects_a_mismatched_resolved_target(
        string $targetBucket,
        string $targetKey,
        ?string $targetVersionId,
        ?string $requestedVersionId,
    ): void {
        $target = new ObjectInfo(
            $targetBucket,
            $targetKey,
            1,
            '"etag"',
            ownerId: 'bucket-owner',
            versionId: $targetVersionId,
        );

        $this->expectException(\LogicException::class);
        (new ObjectDeletePolicyGuard($this->metadata))->check(
            $this->request('DELETE', '/bucket/key', ownerId: 'bucket-owner'),
            'bucket',
            'key',
            $requestedVersionId,
            'bucket-owner',
            'bucket-owner',
            $target,
            targetResolved: true,
        );
    }

    private function put(string $key, string $ownerId, string $body): void
    {
        $write = $this->storage->putObject('bucket', $key, new ReadableBuffer($body));
        $this->metadata->putObjectMetadata(
            'bucket',
            $key,
            $ownerId,
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
    }

    private function delete(MetadataStore $metadata, bool $batch): void
    {
        if ($batch) {
            $request = $this->request('POST', '/bucket?delete');
            $request->setBody(new ReadableBuffer('<Delete><Object><Key>key</Key></Object></Delete>'));
            (new DeleteObjectsHandler($metadata, $this->tiers))->handleRequest($request);

            return;
        }

        (new DeleteObjectHandler($metadata, $this->tiers))->handleRequest(
            $this->request('DELETE', '/bucket/key'),
        );
    }

    private function proxy(
        ?callable $transaction = null,
        ?callable $lockOwner = null,
        ?callable $getObjectMetadata = null,
        ?callable $getObjectMetadataByVersion = null,
    ): MetadataStore {
        $proxy = $this->createMock(MetadataStore::class);
        $overridden = ['transaction', 'lockOwnerForUpdate'];
        if ($getObjectMetadata !== null) {
            $overridden[] = 'getObjectMetadata';
        }
        if ($getObjectMetadataByVersion !== null) {
            $overridden[] = 'getObjectMetadataByVersion';
        }
        foreach ((new \ReflectionClass(MetadataStore::class))->getMethods() as $method) {
            $name = $method->getName();
            if (in_array($name, $overridden, true)) {
                continue;
            }
            $proxy->method($name)->willReturnCallback(
                fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments),
            );
        }
        $proxy->method('transaction')->willReturnCallback(
            $transaction ?? fn(callable $callback): mixed => $this->metadata->transaction($callback),
        );
        $proxy->method('lockOwnerForUpdate')->willReturnCallback(
            $lockOwner ?? function (string $ownerId): void {
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );
        if ($getObjectMetadata !== null) {
            $proxy->method('getObjectMetadata')->willReturnCallback($getObjectMetadata);
        }
        if ($getObjectMetadataByVersion !== null) {
            $proxy->method('getObjectMetadataByVersion')->willReturnCallback($getObjectMetadataByVersion);
        }

        return $proxy;
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $uri, string $ownerId = 'bucket-owner'): Request
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, $method, Http::new('http://127.0.0.1' . $uri));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', $ownerId);
        $request->setAttribute('credential', null);

        return $request;
    }
}
