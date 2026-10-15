<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\ProductionEfsHostPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionEfsHostPinTest extends TestCase
{
    public function test_exact_v2_production_record_creates_an_inert_immutable_pin(): void
    {
        $pin = ProductionEfsHostPin::fromJson(self::json(self::validRecord()));

        self::assertSame(str_repeat('a', 32), $pin->deploymentId);
        self::assertSame(str_repeat('b', 32), $pin->instanceId);
        self::assertSame(str_repeat('c', 64), $pin->containerId);
        self::assertSame('11111111-2222-3333-4444-555555555555', $pin->bootId);
        self::assertSame('18446744073709551615', $pin->storageIno);
        self::assertSame(str_repeat('d', 32), $pin->epoch);
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidIdentifierProvider')]
    public function test_noncanonical_or_out_of_range_identifiers_are_rejected(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductionEfsHostPin::fromJson(self::json(array_replace(self::validRecord(), $changes)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'wrong schema' => [['schemaVersion' => 1]];
        yield 'wrong profile' => [['profile' => 'qualification']];
        yield 'deployment not lower hex' => [['deploymentId' => str_repeat('A', 32)]];
        yield 'short instance' => [['instanceId' => str_repeat('b', 31)]];
        yield 'short container' => [['containerId' => str_repeat('c', 63)]];
        yield 'container name' => [['containerId' => 'api-one']];
        yield 'malformed boot ID' => [['bootId' => '111111112222-3333-4444-555555555555']];
        yield 'zero mount ID' => [['hostMountId' => '0']];
        yield 'leading zero mount ID' => [['hostMountId' => '039']];
        yield 'negative device' => [['storageDev' => '-1']];
        yield 'uint64 overflow device' => [['storageDev' => '18446744073709551616']];
        yield '21 digit inode' => [['storageIno' => '100000000000000000000']];
        yield 'short epoch' => [['epoch' => str_repeat('d', 31)]];
        yield 'integer field' => [['hostMountId' => 39]];
        yield 'boolean field' => [['storageDev' => true]];
    }

    public function test_missing_unknown_duplicate_escaped_and_oversize_records_are_rejected(): void
    {
        $missing = self::validRecord();
        unset($missing['epoch']);
        $unknown = array_replace(self::validRecord(), ['secret' => 'value']);
        $valid = self::json(self::validRecord());
        $duplicate = substr($valid, 0, -1) . ',"instanceId":"' . str_repeat('e', 32) . '"}';
        $escapedKey = str_replace('"instanceId"', '"inst\\u0061nceId"', $valid);
        $escapedValue = str_replace(str_repeat('c', 64), str_repeat('c', 63) . '\\u0063', $valid);

        foreach ([
            self::json($missing), self::json($unknown), $duplicate, $escapedKey,
            $escapedValue, str_repeat(' ', 4097), '{}', '[]', '', '{',
        ] as $json) {
            try {
                ProductionEfsHostPin::fromJson($json);
                self::fail('Malformed production host pin was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function validRecord(): array
    {
        return [
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'containerId' => str_repeat('c', 64),
            'bootId' => '11111111-2222-3333-4444-555555555555',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '18446744073709551615',
            'epoch' => str_repeat('d', 32),
        ];
    }

    /** @param array<string, mixed> $record */
    private static function json(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
