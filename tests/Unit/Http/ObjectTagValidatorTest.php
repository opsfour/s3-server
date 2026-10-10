<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\ObjectTagValidator;
use PHPUnit\Framework\TestCase;

final class ObjectTagValidatorTest extends TestCase
{
    public function test_parse_header_rejects_malformed_percent_encoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ObjectTagValidator::parseHeader('release%ZZ=stable');
    }

    public function test_parse_header_requires_key_value_pairs(): void
    {
        foreach (['release', '<Tagging><TagSet/></Tagging>', 'release=stable&other'] as $header) {
            try {
                ObjectTagValidator::parseHeader($header);
                self::fail('Header without key=value pair was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('key=value', $error->getMessage());
            }
        }
        self::assertSame([['key' => 'release', 'value' => '']], ObjectTagValidator::parseHeader('release='));
    }

    public function test_validate_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ObjectTagValidator::validate([['key' => "\xC3\x28", 'value' => 'stable']], 10);
    }

    public function test_validate_rejects_invalid_utf8_in_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ObjectTagValidator::validate([['key' => 'release', 'value' => "\xC3\x28"]], 10);
    }

    public function test_bmp_tag_lengths_use_unicode_positions_instead_of_utf8_bytes(): void
    {
        $key = str_repeat("\u{00E9}", 128);
        $value = str_repeat("\u{00E9}", 256);
        self::assertSame([['key' => $key, 'value' => $value]], ObjectTagValidator::validate([
            ['key' => $key, 'value' => $value],
        ], 10));
        self::assertSame([['key' => $key, 'value' => '']], ObjectTagValidator::parseHeader(rawurlencode($key) . '='));

        foreach ([
            ['key' => $key . "\u{00E9}", 'value' => ''],
            ['key' => 'release', 'value' => $value . "\u{00E9}"],
        ] as $tag) {
            try {
                ObjectTagValidator::validate([$tag], 10);
                self::fail('A tag exceeding the UTF-16 position limit was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('exceeds the S3 limits', $error->getMessage());
            }
        }
    }

    public function test_supplementary_tag_characters_count_as_two_positions(): void
    {
        $key = str_repeat("\u{1F600}", 64);
        $value = str_repeat("\u{1F600}", 128);
        self::assertSame([['key' => $key, 'value' => $value]], ObjectTagValidator::validate([
            ['key' => $key, 'value' => $value],
        ], 10));
        self::assertSame([['key' => str_repeat('a', 126) . "\u{1F600}", 'value' => '']], ObjectTagValidator::validate([
            ['key' => str_repeat('a', 126) . "\u{1F600}", 'value' => ''],
        ], 10));

        foreach ([
            ['key' => $key . "\u{1F600}", 'value' => ''],
            ['key' => 'release', 'value' => $value . "\u{1F600}"],
            ['key' => str_repeat('a', 127) . "\u{1F600}", 'value' => ''],
        ] as $tag) {
            try {
                ObjectTagValidator::validate([$tag], 10);
                self::fail('A supplementary character beyond the UTF-16 limit was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('exceeds the S3 limits', $error->getMessage());
            }
        }
    }
}
