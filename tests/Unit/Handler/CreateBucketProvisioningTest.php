<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\BucketAlreadyOwnedByYouException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Handler\Bucket\CreateBucketHandler;
use OpsFour\S3Server\Handler\Bucket\HeadBucketHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Quota\QuotaProvider;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\TestCase;

final class CreateBucketProvisioningTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-bucket-provision-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_failed_first_provision_does_not_delete_a_successful_same_owner_retry(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $handler = $this->handler($storage);
        $calls = 0;
        $retryStatus = null;
        $storage->method('createBucket')->willReturnCallback(function (string $bucket) use (&$calls, &$retryStatus, $handler): void {
            self::assertSame('bucket', $bucket);
            $calls++;
            if ($calls === 1) {
                $retryStatus = $handler->handleRequest($this->request())->getStatus();
                throw new \RuntimeException('first provisioning attempt failed');
            }
        });

        try {
            $handler->handleRequest($this->request());
            self::fail('The first provisioning attempt should fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('first provisioning attempt failed', $exception->getMessage());
        }

        self::assertSame(2, $calls);
        self::assertSame(200, $retryStatus);
        self::assertSame('owner', $this->metadata->getBucketOwner('bucket'));
    }

    public function test_reentrant_create_cannot_report_object_lock_success_without_enabling_it(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $handler = $this->handler($storage);
        $calls = 0;
        $storage->method('createBucket')->willReturnCallback(function () use (&$calls, $handler): void {
            if (++$calls === 1) {
                self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
            }
        });

        $this->expectException(BucketAlreadyOwnedByYouException::class);
        try {
            $handler->handleRequest($this->request(['x-amz-bucket-object-lock-enabled' => 'true']));
        } finally {
            self::assertSame(2, $calls);
            self::assertSame('owner', $this->metadata->getBucketOwner('bucket'));
            self::assertNull($this->metadata->getObjectLockConfig('bucket'));
            self::assertSame('', $this->metadata->getBucketVersioning('bucket'));
        }
    }

    public function test_reentrant_create_cannot_report_enforced_ownership_without_enabling_it(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $handler = $this->handler($storage);
        $calls = 0;
        $storage->method('createBucket')->willReturnCallback(function () use (&$calls, $handler): void {
            if (++$calls === 1) {
                self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
            }
        });

        $this->expectException(BucketAlreadyOwnedByYouException::class);
        try {
            $handler->handleRequest($this->request(['x-amz-object-ownership' => 'BucketOwnerEnforced']));
        } finally {
            self::assertSame(2, $calls);
            self::assertSame('owner', $this->metadata->getBucketOwner('bucket'));
            self::assertSame('ObjectWriter', $this->metadata->getBucketOwnershipControls('bucket'));
        }
    }

    public function test_existing_bucket_with_different_region_is_not_reported_as_created(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('createBucket');
        $handler = $this->handler($storage);
        $this->metadata->createBucket('owner', 'bucket', 'us-west-2');

        try {
            $handler->handleRequest($this->request(body: $this->locationConstraint('eu-west-1')));
            self::fail('A conflicting bucket region must not be reported as created.');
        } catch (BucketAlreadyOwnedByYouException $exception) {
            self::assertStringContainsString('different region', $exception->getMessage());
        }

        self::assertSame('us-west-2', $this->metadata->getBucket('bucket')?->region);
    }

    public function test_reentrant_create_with_different_region_is_not_reported_as_created(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $handler = $this->handler($storage);
        $calls = 0;
        $storage->method('createBucket')->willReturnCallback(function () use (&$calls, $handler): void {
            if (++$calls === 1) {
                self::assertSame(200, $handler->handleRequest(
                    $this->request(body: $this->locationConstraint('eu-west-1')),
                )->getStatus());
            }
        });

        try {
            $handler->handleRequest($this->request(body: $this->locationConstraint('us-west-2')));
            self::fail('A concurrent bucket created in another region must reject the stale request.');
        } catch (BucketAlreadyOwnedByYouException $exception) {
            self::assertStringContainsString('different region', $exception->getMessage());
        }

        self::assertSame(2, $calls);
        self::assertSame('eu-west-1', $this->metadata->getBucket('bucket')?->region);
    }

    public function test_reentrant_create_with_matching_protection_options_remains_idempotent(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $handler = $this->handler($storage);
        $headers = [
            'x-amz-bucket-object-lock-enabled' => 'true',
            'x-amz-object-ownership' => 'BucketOwnerEnforced',
        ];
        $calls = 0;
        $storage->method('createBucket')->willReturnCallback(function () use (&$calls, $handler, $headers): void {
            if (++$calls === 1) {
                self::assertSame(200, $handler->handleRequest($this->request($headers))->getStatus());
            }
        });

        self::assertSame(200, $handler->handleRequest($this->request($headers))->getStatus());
        self::assertSame(2, $calls);
        self::assertNotNull($this->metadata->getObjectLockConfig('bucket'));
        self::assertSame('Enabled', $this->metadata->getBucketVersioning('bucket'));
        self::assertSame('BucketOwnerEnforced', $this->metadata->getBucketOwnershipControls('bucket'));
    }

    public function test_failed_provision_is_not_visible_and_later_retry_creates_bucket(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $physical = new InMemoryBackend();
        $handler = $this->handler($storage);
        $calls = 0;
        $storage->expects(self::never())->method('deleteBucket');
        $storage->method('bucketExists')->willReturnCallback($physical->bucketExists(...));
        $storage->method('createBucket')->willReturnCallback(function (string $bucket) use (&$calls, $physical): void {
            $calls++;
            $physical->createBucket($bucket);
            if ($calls === 1) {
                throw new \RuntimeException('backend failed after partial namespace creation');
            }
        });

        try {
            $handler->handleRequest($this->request());
            self::fail('The backend failure should reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('backend failed after partial namespace creation', $exception->getMessage());
        }

        self::assertTrue($storage->bucketExists('bucket'));
        self::assertNull($this->metadata->getBucketOwner('bucket'));
        try {
            (new HeadBucketHandler($this->metadata))->handleRequest($this->headRequest());
            self::fail('A failed storage provision must not make HeadBucket succeed.');
        } catch (NoSuchBucketException) {
            // The failed create has not been published in metadata.
        }
        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        self::assertSame('owner', $this->metadata->getBucketOwner('bucket'));
        self::assertSame(2, $calls);
    }

    public function test_late_quota_failure_keeps_physical_namespace_for_safe_retry(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $physical = new InMemoryBackend();
        $storage->expects(self::never())->method('deleteBucket');
        $storage->method('bucketExists')->willReturnCallback($physical->bucketExists(...));
        $calls = 0;
        $storage->method('createBucket')->willReturnCallback(function (string $bucket) use (&$calls, $physical): void {
            $calls++;
            $physical->createBucket($bucket);
            if ($calls === 1) {
                // Another bucket consumes the last owner slot while the first
                // request is provisioning its physical namespace.
                $this->metadata->createBucket('owner', 'other-bucket', 'us-east-1');
            }
        });
        $quotaProvider = $this->createMock(QuotaProvider::class);
        $quotaProvider->method('quotaForOwner')->willReturn(new QuotaConfig(maxBucketsPerOwner: 1));
        $handler = new CreateBucketHandler(
            $this->metadata,
            $storage,
            new S3ServerConfig(storagePath: sys_get_temp_dir()),
            new QuotaManager($this->metadata, $quotaProvider),
        );

        try {
            $handler->handleRequest($this->request());
            self::fail('The late owner quota check must reject the bucket.');
        } catch (QuotaExceededException) {
            self::assertNull($this->metadata->getBucketOwner('bucket'));
            self::assertSame('owner', $this->metadata->getBucketOwner('other-bucket'));
            self::assertTrue($physical->bucketExists('bucket'));
        }

        $this->metadata->deleteBucket('owner', 'other-bucket');
        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        self::assertSame('owner', $this->metadata->getBucketOwner('bucket'));
        self::assertSame(2, $calls);
    }

    public function test_another_owner_winning_during_provision_is_not_overwritten(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('createBucket')->willReturnCallback(function (): void {
            $this->metadata->createBucket('other-owner', 'bucket', 'us-east-1');
        });

        try {
            $this->handler($storage)->handleRequest($this->request());
            self::fail('The final name check must reject the concurrent owner.');
        } catch (BucketAlreadyExistsException) {
            self::assertSame('other-owner', $this->metadata->getBucketOwner('bucket'));
        }
    }

    public function test_retry_after_partial_provision_does_not_touch_a_new_owner_namespace(): void
    {
        $physical = new InMemoryBackend();
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('createBucket')->willReturnCallback(
            static function (string $bucket) use ($physical): void {
                $physical->createBucket($bucket);
                throw new \RuntimeException('partial provision');
            },
        );
        $storage->expects(self::never())->method('deleteBucket');
        $handler = $this->handler($storage);

        try {
            $handler->handleRequest($this->request());
            self::fail('The partial provision must fail.');
        } catch (\RuntimeException) {
            self::assertTrue($physical->bucketExists('bucket'));
        }

        $this->metadata->createBucket('other-owner', 'bucket', 'us-east-1');
        try {
            $handler->handleRequest($this->request());
            self::fail('The old owner must not retry into the new namespace.');
        } catch (BucketAlreadyExistsException) {
            self::assertSame('other-owner', $this->metadata->getBucketOwner('bucket'));
            self::assertTrue($physical->bucketExists('bucket'));
        }
    }

    private function handler(StorageBackend $storage): CreateBucketHandler
    {
        return new CreateBucketHandler(
            $this->metadata,
            $storage,
            new S3ServerConfig(storagePath: sys_get_temp_dir()),
        );
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(array $headers = [], string $body = ''): Request
    {
        $request = new Request(new CreateBucketProvisioningTestClient(), 'PUT', Http::new('http://127.0.0.1/bucket'), $headers, $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    private function locationConstraint(string $region): string
    {
        return '<CreateBucketConfiguration><LocationConstraint>' . $region
            . '</LocationConstraint></CreateBucketConfiguration>';
    }

    private function headRequest(): Request
    {
        $request = new Request(new CreateBucketProvisioningTestClient(), 'HEAD', Http::new('http://127.0.0.1/bucket'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}

final class CreateBucketProvisioningTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}
