<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Http\ObjectTagValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectTagValidatorTest extends TestCase
{
    public function test_validate_stored_maps_legacy_corruption_to_internal_error(): void
    {
        foreach ([
            [['key' => 'legacy', 'value' => "control\u{000B}"]],
            [['key' => 'legacy*', 'value' => 'value']],
            [['key' => 'duplicate', 'value' => 'first'], ['key' => 'duplicate', 'value' => 'second']],
            [['key' => 'missing-value']],
        ] as $tags) {
            try {
                ObjectTagValidator::validateStored($tags, 10);
                self::fail('Corrupt stored tags were accepted.');
            } catch (InternalErrorException $error) {
                self::assertSame('InternalError', $error->getErrorCode());
                self::assertSame('Stored tag metadata is invalid.', $error->getMessage());
                self::assertInstanceOf(InvalidArgumentException::class, $error->getPrevious());
            }
        }
    }

    public function test_validate_stored_preserves_valid_tags(): void
    {
        $tags = [['key' => 'release', 'value' => 'stable']];

        self::assertSame($tags, ObjectTagValidator::validateStored($tags, 10));
    }

    public function test_validate_stored_preserves_legacy_aws_reserved_tag_keys(): void
    {
        $tags = [
            ['key' => 'aws:managed', 'value' => 'legacy'],
            ['key' => 'AWS:Imported', 'value' => 'legacy'],
        ];

        self::assertSame($tags, ObjectTagValidator::validateStored($tags, 10));
    }

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

    public function test_validate_accepts_only_the_s3_tag_character_set(): void
    {
        $key = 'Relação 42+-. _:/=@';
        $value = '東京 ७';

        self::assertSame([['key' => $key, 'value' => $value]], ObjectTagValidator::validate([
            ['key' => $key, 'value' => $value],
        ], 10));
        self::assertSame(
            [['key' => $key, 'value' => $value]],
            ObjectTagValidator::parseHeader(rawurlencode($key) . '=' . rawurlencode($value)),
        );
        self::assertSame([['key' => 'empty', 'value' => '']], ObjectTagValidator::validate([
            ['key' => 'empty', 'value' => ''],
        ], 10));
    }

    public function test_validate_rejects_aws_reserved_tag_keys_across_body_and_header_writes(): void
    {
        foreach ([
            static fn() => ObjectTagValidator::validate([['key' => 'aws:managed', 'value' => 'value']], 10),
            static fn() => ObjectTagValidator::parseHeader('AWS%3AManaged=value'),
        ] as $validate) {
            try {
                $validate();
                self::fail('A client tag using the reserved aws: prefix was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('reserved aws: prefix', $error->getMessage());
            }
        }
    }

    #[DataProvider('invalidTagCharacters')]
    public function test_validate_rejects_characters_outside_the_s3_and_xml_character_sets(string $value): void
    {
        foreach ([
            ['key' => 'invalid' . $value, 'value' => 'stable'],
            ['key' => 'release', 'value' => 'invalid' . $value],
        ] as $tag) {
            try {
                ObjectTagValidator::validate([$tag], 10);
                self::fail('A tag character outside the documented S3 set was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('Unicode letters', $error->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTagCharacters(): iterable
    {
        yield 'asterisk' => ['*'];
        yield 'XML control' => ["\u{000B}"];
        yield 'Unicode noncharacter' => ["\u{FDD0}"];
        yield 'terminal Unicode noncharacter' => ["\u{10FFFF}"];
        yield 'combining mark outside letter class' => ["\u{0301}"];
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
        $key = str_repeat("\u{10400}", 64);
        $value = str_repeat("\u{10400}", 128);
        self::assertSame([['key' => $key, 'value' => $value]], ObjectTagValidator::validate([
            ['key' => $key, 'value' => $value],
        ], 10));
        self::assertSame([['key' => str_repeat('a', 126) . "\u{10400}", 'value' => '']], ObjectTagValidator::validate([
            ['key' => str_repeat('a', 126) . "\u{10400}", 'value' => ''],
        ], 10));

        foreach ([
            ['key' => $key . "\u{10400}", 'value' => ''],
            ['key' => 'release', 'value' => $value . "\u{10400}"],
            ['key' => str_repeat('a', 127) . "\u{10400}", 'value' => ''],
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
