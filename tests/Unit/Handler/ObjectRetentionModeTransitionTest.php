<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Handler\Object\PutObjectRetentionHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class ObjectRetentionModeTransitionTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    private string $versionId;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-retention-mode-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectLockConfig('bucket', ['objectLockEnabled' => 'Enabled']);
        $this->versionId = $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'owner',
            1,
            '"etag"',
            'application/octet-stream',
            'path',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_governance_can_be_strengthened_to_later_compliance_without_bypass(): void
    {
        $this->metadata->putObjectRetention('bucket', 'key', 'GOVERNANCE', $this->date('+2 days'), $this->versionId);

        $response = (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request('COMPLIANCE', $this->date('+3 days')),
        );

        self::assertSame(200, $response->getStatus());
        self::assertSame('COMPLIANCE', $this->metadata->getObjectRetention('bucket', 'key', $this->versionId)['mode'] ?? null);
    }

    public function test_shorter_governance_still_requires_bypass(): void
    {
        $this->metadata->putObjectRetention('bucket', 'key', 'GOVERNANCE', $this->date('+3 days'), $this->versionId);

        $this->expectException(AccessDeniedException::class);
        (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request('GOVERNANCE', $this->date('+2 days')),
        );
    }

    public function test_compliance_cannot_be_changed_back_to_governance(): void
    {
        $this->metadata->putObjectRetention('bucket', 'key', 'COMPLIANCE', $this->date('+2 days'), $this->versionId);

        $this->expectException(AccessDeniedException::class);
        (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request('GOVERNANCE', $this->date('+3 days')),
        );
    }

    public function test_governance_retention_can_be_removed_with_authorized_bypass(): void
    {
        $this->metadata->putObjectRetention('bucket', 'key', 'GOVERNANCE', $this->date('+2 days'), $this->versionId);

        $response = (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request(null, null, canBypass: true),
        );

        self::assertSame(200, $response->getStatus());
        self::assertNull($this->metadata->getObjectRetention('bucket', 'key', $this->versionId));
    }

    public function test_governance_retention_removal_without_bypass_is_denied(): void
    {
        $retainUntil = $this->date('+2 days');
        $this->metadata->putObjectRetention('bucket', 'key', 'GOVERNANCE', $retainUntil, $this->versionId);

        try {
            (new PutObjectRetentionHandler($this->metadata))->handleRequest($this->request(null, null));
            self::fail('Governance retention was removed without an authorized bypass.');
        } catch (AccessDeniedException) {
            self::assertSame(
                $retainUntil,
                $this->metadata->getObjectRetention('bucket', 'key', $this->versionId)['retainUntilDate'] ?? null,
            );
        }
    }

    public function test_compliance_retention_cannot_be_removed_with_governance_bypass(): void
    {
        $retainUntil = $this->date('+2 days');
        $this->metadata->putObjectRetention('bucket', 'key', 'COMPLIANCE', $retainUntil, $this->versionId);

        try {
            (new PutObjectRetentionHandler($this->metadata))->handleRequest(
                $this->request(null, null, canBypass: true),
            );
            self::fail('Governance bypass removed COMPLIANCE retention.');
        } catch (AccessDeniedException) {
            self::assertSame(
                'COMPLIANCE',
                $this->metadata->getObjectRetention('bucket', 'key', $this->versionId)['mode'] ?? null,
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retentionModes')]
    public function test_expired_retention_can_be_removed_without_bypass(string $mode): void
    {
        $this->metadata->putObjectRetention('bucket', 'key', $mode, $this->date('-1 day'), $this->versionId);

        $response = (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request(null, null),
        );

        self::assertSame(200, $response->getStatus());
        self::assertNull($this->metadata->getObjectRetention('bucket', 'key', $this->versionId));
    }

    /** @return iterable<string, array{string}> */
    public static function retentionModes(): iterable
    {
        yield 'governance' => ['GOVERNANCE'];
        yield 'compliance' => ['COMPLIANCE'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expiredRetentionTransitions')]
    public function test_expired_retention_can_be_replaced_without_bypass(
        string $existingMode,
        string $newMode,
    ): void {
        $this->metadata->putObjectRetention(
            'bucket',
            'key',
            $existingMode,
            $this->date('-1 day'),
            $this->versionId,
        );
        $retainUntil = $this->date('+2 days');

        $response = (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request($newMode, $retainUntil),
        );

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            ['mode' => $newMode, 'retainUntilDate' => $retainUntil],
            $this->metadata->getObjectRetention('bucket', 'key', $this->versionId),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function expiredRetentionTransitions(): iterable
    {
        yield 'governance to governance' => ['GOVERNANCE', 'GOVERNANCE'];
        yield 'governance to compliance' => ['GOVERNANCE', 'COMPLIANCE'];
        yield 'compliance to governance' => ['COMPLIANCE', 'GOVERNANCE'];
        yield 'compliance to compliance' => ['COMPLIANCE', 'COMPLIANCE'];
    }

    public function test_external_store_without_retention_deletion_capability_returns_not_implemented(): void
    {
        $bucket = new BucketInfo('bucket', 'owner', generation: str_repeat('b', 32));
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getObjectLockConfig')->willReturn(['objectLockEnabled' => 'Enabled']);
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->method('getObjectMetadataByVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            1,
            '"etag"',
            ownerId: 'owner',
            versionId: $this->versionId,
        ));
        $metadata->method('getObjectRetention')->willReturn([
            'mode' => 'GOVERNANCE',
            'retainUntilDate' => $this->date('-1 day'),
        ]);

        $this->expectException(NotImplementedException::class);
        (new PutObjectRetentionHandler($metadata))->handleRequest($this->request(null, null));
    }

    public function test_empty_retention_is_idempotent_when_the_version_has_no_retention(): void
    {
        $response = (new PutObjectRetentionHandler($this->metadata))->handleRequest(
            $this->request(null, null, canBypass: true),
        );

        self::assertSame(200, $response->getStatus());
        self::assertNull($this->metadata->getObjectRetention('bucket', 'key', $this->versionId));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStoredRetentions')]
    public function test_invalid_stored_retention_cannot_be_replaced(
        string $mode,
        string $retainUntilDate,
    ): void {
        $this->metadata->putObjectRetention('bucket', 'key', $mode, $retainUntilDate, $this->versionId);

        try {
            (new PutObjectRetentionHandler($this->metadata))->handleRequest(
                $this->request('GOVERNANCE', $this->date('+3 days')),
            );
            self::fail('Invalid stored retention must not be replaced through the public API.');
        } catch (AccessDeniedException) {
            $stored = $this->metadata->getObjectRetention('bucket', 'key', $this->versionId);
            self::assertSame($mode, $stored['mode'] ?? null);
            self::assertSame($retainUntilDate, $stored['retainUntilDate'] ?? null);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidStoredRetentions(): iterable
    {
        yield 'unknown mode' => ['UNKNOWN', '2030-01-01T00:00:00Z'];
        yield 'relative date' => ['GOVERNANCE', 'tomorrow'];
        yield 'impossible date' => ['COMPLIANCE', '2030-02-30T00:00:00Z'];
    }

    private function request(?string $mode, ?string $date, bool $canBypass = false): Request
    {
        $body = $mode === null || $date === null
            ? '<Retention/>'
            : '<Retention><Mode>' . $mode . '</Mode><RetainUntilDate>' . $date . '</RetainUntilDate></Retention>';
        $headers = $canBypass ? ['x-amz-bypass-governance-retention' => 'true'] : [];
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?retention&versionId=' . rawurlencode($this->versionId)),
            $headers,
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObjectRetention);
        $request->setAttribute('s3.canBypassGovernanceRetention', $canBypass);

        return $request;
    }

    private function date(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
