<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Http\Iso8601Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Iso8601TimestampTest extends TestCase
{
    public function test_parses_utc_fractional_and_offset_timestamps(): void
    {
        self::assertNotNull(Iso8601Timestamp::parse('2030-02-28T12:34:56Z'));
        self::assertNotNull(Iso8601Timestamp::parse('2030-02-28T12:34:56.123456789Z'));
        self::assertNotNull(Iso8601Timestamp::parse('2030-02-28T12:34:56+01:30'));
    }

    public function test_utc_mode_rejects_offsets(): void
    {
        self::assertNull(Iso8601Timestamp::parse('2030-02-28T12:34:56+00:00', requireUtc: true));
    }

    #[DataProvider('invalidTimestampProvider')]
    public function test_rejects_invalid_timestamps(string $value): void
    {
        self::assertNull(Iso8601Timestamp::parse($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimestampProvider(): iterable
    {
        yield 'impossible calendar date' => ['2030-02-31T12:34:56Z'];
        yield 'invalid hour' => ['2030-02-28T24:00:00Z'];
        yield 'invalid minute' => ['2030-02-28T12:60:00Z'];
        yield 'invalid second' => ['2030-02-28T12:00:60Z'];
        yield 'invalid timezone' => ['2030-02-28T12:00:00+24:00'];
        yield 'missing timezone' => ['2030-02-28T12:00:00'];
        yield 'space separator' => ['2030-02-28 12:00:00Z'];
        yield 'trailing data' => ['2030-02-28T12:00:00Z trailing'];
        yield 'excessive precision' => ['2030-02-28T12:00:00.1234567890Z'];
    }
}
