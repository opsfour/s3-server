<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Object\PutObjectRetentionHandler;
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

    private function request(string $mode, string $date): Request
    {
        $body = '<Retention><Mode>' . $mode . '</Mode><RetainUntilDate>' . $date . '</RetainUntilDate></Retention>';
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?retention&versionId=' . rawurlencode($this->versionId)),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObjectRetention);
        $request->setAttribute('s3.canBypassGovernanceRetention', false);

        return $request;
    }

    private function date(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
