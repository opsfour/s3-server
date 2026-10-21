<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\ObjectLock;

use OpsFour\S3Server\Exception\ObjectLockedException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\ObjectLock\ObjectLockChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectLockCheckerTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    private ObjectLockChecker $checker;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-object-lock-checker-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->checker = new ObjectLockChecker($this->metadata);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    #[DataProvider('unsafeStoredProtection')]
    public function test_unknown_or_malformed_stored_protection_fails_closed(
        ?string $legalHold,
        ?string $retentionMode,
        ?string $retainUntilDate,
    ): void {
        if ($legalHold !== null) {
            $this->metadata->putObjectLegalHold('bucket', 'key', $legalHold, 'version');
        }
        if ($retentionMode !== null && $retainUntilDate !== null) {
            $this->metadata->putObjectRetention('bucket', 'key', $retentionMode, $retainUntilDate, 'version');
        }

        $this->expectException(ObjectLockedException::class);
        $this->checker->checkProtection('bucket', 'key', 'version', true);
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function unsafeStoredProtection(): iterable
    {
        yield 'unknown legal hold' => ['UNKNOWN', null, null];
        yield 'case-variant legal hold' => ['off', null, null];
        yield 'unknown expired retention mode' => [null, 'UNKNOWN', '2020-01-01T00:00:00Z'];
        yield 'relative retention timestamp' => [null, 'GOVERNANCE', 'yesterday'];
        yield 'impossible retention timestamp' => [null, 'COMPLIANCE', '2030-02-30T00:00:00Z'];
    }

    public function test_expired_valid_retention_and_explicit_off_hold_do_not_block_deletion(): void
    {
        $this->metadata->putObjectLegalHold('bucket', 'key', 'OFF', 'version');
        $this->metadata->putObjectRetention(
            'bucket',
            'key',
            'COMPLIANCE',
            '2020-01-01T00:00:00Z',
            'version',
        );

        $this->checker->checkProtection('bucket', 'key', 'version');
        self::addToAssertionCount(1);
    }

    public function test_governance_bypass_never_bypasses_compliance_or_legal_hold(): void
    {
        $future = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $this->metadata->putObjectRetention('bucket', 'key', 'COMPLIANCE', $future, 'compliance');
        $this->metadata->putObjectLegalHold('bucket', 'key', 'ON', 'held');

        foreach (['compliance', 'held'] as $versionId) {
            try {
                $this->checker->checkProtection('bucket', 'key', $versionId, true);
                self::fail("Governance bypass removed {$versionId} protection.");
            } catch (ObjectLockedException $exception) {
                self::assertSame(403, $exception->getHttpStatus());
                self::assertSame('AccessDenied', $exception->getErrorCode());
                self::assertSame('Access Denied', $exception->getMessage());
            }
        }
    }
}
