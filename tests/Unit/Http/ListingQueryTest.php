<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\ListingQuery;
use OpsFour\S3Server\Http\QueryStringParser;
use PHPUnit\Framework\TestCase;

final class ListingQueryTest extends TestCase
{
    public function test_limits_and_markers_use_complete_decimal_values(): void
    {
        self::assertSame(1000, ListingQuery::limit([], 'max-parts'));
        self::assertSame(1000, ListingQuery::limit(['max-parts' => '1001'], 'max-parts'));
        self::assertSame(0, ListingQuery::limit(['max-parts' => '0'], 'max-parts'));
        self::assertSame(12, ListingQuery::marker(['part-number-marker' => '12'], 'part-number-marker'));

        foreach (['', '-1', '+1', '1junk', ' 1', '1.5', str_repeat('9', 40)] as $invalid) {
            try {
                ListingQuery::limit(['max-keys' => $invalid], 'max-keys');
                self::fail("Invalid limit '{$invalid}' was accepted.");
            } catch (InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }

            try {
                ListingQuery::marker(['part-number-marker' => $invalid], 'part-number-marker');
                self::fail("Invalid marker '{$invalid}' was accepted.");
            } catch (InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }
        }
    }

    public function test_query_parser_keeps_first_occurrence_even_without_equals_sign(): void
    {
        self::assertSame(['max-keys' => '1'], QueryStringParser::parse('max-keys=1&max-keys'));
        self::assertSame(['max-keys' => ''], QueryStringParser::parse('max-keys&max-keys=1'));
    }

    public function test_encoding_type_accepts_only_url(): void
    {
        self::assertNull(ListingQuery::encodingType([]));
        self::assertSame('url', ListingQuery::encodingType(['encoding-type' => 'url']));

        foreach (['', 'gzip', 'URL'] as $invalid) {
            try {
                ListingQuery::encodingType(['encoding-type' => $invalid]);
                self::fail("Invalid encoding type '{$invalid}' was accepted.");
            } catch (InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }
        }
    }

    public function test_boolean_query_is_strict_and_case_insensitive(): void
    {
        self::assertFalse(ListingQuery::boolean([], 'fetch-owner'));
        self::assertTrue(ListingQuery::boolean(['fetch-owner' => 'TrUe'], 'fetch-owner'));
        self::assertFalse(ListingQuery::boolean(['fetch-owner' => 'FALSE'], 'fetch-owner', true));

        foreach (['', '1', 'banana'] as $invalid) {
            try {
                ListingQuery::boolean(['fetch-owner' => $invalid], 'fetch-owner');
                self::fail("Invalid boolean '{$invalid}' was accepted.");
            } catch (InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }
        }
    }
}
