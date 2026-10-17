<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\ProductionEfsHostPin;
use OpsFour\S3Server\Runtime\ProductionEfsIdentity;
use OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionEfsWatcherEvidenceTest extends TestCase
{
    private const int NOW_NS = 1_000_000_000_000;

    public function test_exact_v2_heartbeat_is_only_an_inert_value(): void
    {
        $identity = self::identity();
        $pin = self::pin();
        $evidence = ProductionEfsWatcherEvidence::fromJson(self::json(self::validRecord()), $identity, $pin, self::NOW_NS);

        self::assertSame($identity, $evidence->identity);
        self::assertSame($pin, $evidence->hostPin);
        self::assertSame(self::NOW_NS - 1_000_000_000, $evidence->issuedMonotonicNs);
        self::assertSame(self::NOW_NS + 19_000_000_000, $evidence->expiresMonotonicNs);
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidRecordProvider')]
    public function test_identity_drift_or_invalid_lease_is_rejected(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductionEfsWatcherEvidence::fromJson(
            self::json(array_replace(self::validRecord(), $changes)),
            self::identity(),
            self::pin(),
            self::NOW_NS,
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRecordProvider(): iterable
    {
        yield 'v1 evidence' => [['schemaVersion' => 1, 'profile' => 'qualification']];
        yield 'test run field' => [['runId' => str_repeat('f', 32)]];
        yield 'deployment drift' => [['deploymentId' => str_repeat('f', 32)]];
        yield 'instance drift' => [['instanceId' => str_repeat('f', 32)]];
        yield 'EFS drift' => [['efsId' => 'fs-ffffffff']];
        yield 'artifact drift' => [['sourceSha256' => str_repeat('f', 64)]];
        yield 'metadata drift' => [['metadataDriver' => 'mysql']];
        yield 'mount path drift' => [['mountPoint' => '/other-efs']];
        yield 'storage path drift' => [['storageRoot' => '/other-efs/objects']];
        yield 'short container ID' => [['containerId' => substr(str_repeat('d', 64), 0, 12)]];
        yield 'container drift' => [['containerId' => str_repeat('f', 64)]];
        yield 'boot drift' => [['bootId' => '99999999-1234-1234-1234-123456789abc']];
        yield 'mount ID drift' => [['hostMountId' => '40']];
        yield 'storage device drift' => [['storageDev' => '2049']];
        yield 'storage inode drift' => [['storageIno' => '3002']];
        yield 'watcher epoch drift' => [['epoch' => str_repeat('f', 32)]];
        yield 'future issue' => [['issuedMonotonicNs' => self::NOW_NS + 1]];
        yield 'expired' => [['expiresMonotonicNs' => self::NOW_NS]];
        yield 'long lease' => [['expiresMonotonicNs' => self::NOW_NS + 20_000_000_001]];
        yield 'floating timestamp' => [['issuedMonotonicNs' => 999_000_000_000.5]];
        yield 'numeric timestamp string' => [['expiresMonotonicNs' => (string) (self::NOW_NS + 19_000_000_000)]];
        yield 'unsafe test override' => [['allowUnfencedStorageForTests' => true]];
    }

    public function test_missing_duplicate_escaped_and_oversize_records_are_rejected(): void
    {
        $record = self::validRecord();
        unset($record['epoch']);
        $valid = self::json(self::validRecord());
        $duplicate = substr($valid, 0, -2) . ',"instanceId":"' . str_repeat('f', 32) . '"}' . "\n";
        $escaped = str_replace('"/nonexistent-efs"', '"\/nonexistent-efs"', $valid);

        $rejected = 0;
        foreach ([self::json($record), $duplicate, $escaped, str_repeat(' ', 4097), '{}', '[]', rtrim($valid, "\n")] as $json) {
            try {
                ProductionEfsWatcherEvidence::fromJson($json, self::identity(), self::pin(), self::NOW_NS);
                self::fail('Malformed production heartbeat was accepted.');
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(7, $rejected);
    }

    public function test_host_pin_requires_complete_canonical_identity(): void
    {
        $rejected = 0;
        foreach ([
            ['containerId' => 'api-one'],
            ['bootId' => 'unknown'],
            ['hostMountId' => '039'],
            ['storageDev' => '0'],
            ['storageIno' => '-1'],
            ['epoch' => 'old'],
        ] as $changes) {
            try {
                self::pin($changes);
                self::fail('Malformed production host pin was accepted.');
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(6, $rejected);
    }

    /** @return array<string, mixed> */
    private static function validRecord(): array
    {
        return [
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs',
            'storageRoot' => '/nonexistent-efs/objects',
            'containerId' => str_repeat('d', 64),
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
            'issuedMonotonicNs' => self::NOW_NS - 1_000_000_000,
            'expiresMonotonicNs' => self::NOW_NS + 19_000_000_000,
        ];
    }

    private static function identity(): ProductionEfsIdentity
    {
        return ProductionEfsIdentity::fromJson(json_encode([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs',
            'storageRoot' => '/nonexistent-efs/objects',
            'scratchPath' => '/nonexistent-local-scratch',
            'evidenceDirectory' => '/run/s3gate/evidence',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $changes */
    private static function pin(array $changes = []): ProductionEfsHostPin
    {
        $fields = array_replace([
            'containerId' => str_repeat('d', 64),
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
        ], $changes);

        return new ProductionEfsHostPin(...$fields);
    }

    /** @param array<string, mixed> $record */
    private static function json(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
