<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\KeyTooLongException;
use OpsFour\S3Server\Http\CopySourceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CopySourceParserTest extends TestCase
{
    /** @param class-string<\Throwable> $exception */
    #[DataProvider('invalidKeys')]
    public function test_rejects_invalid_decoded_source_keys(string $source, string $exception): void
    {
        $this->expectException($exception);
        CopySourceParser::parse($source);
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function invalidKeys(): iterable
    {
        yield 'invalid UTF-8' => ['/bucket/object%FF', InvalidArgumentException::class];
        yield 'XML control' => ['/bucket/object%0B', InvalidArgumentException::class];
        yield 'above byte limit' => ['/bucket/' . str_repeat('a', 1025), KeyTooLongException::class];
    }

    public function test_accepts_valid_unicode_and_version_id(): void
    {
        self::assertSame(
            ['bucket', "\u{2603}/\u{6587}\u{4EF6}", 'version-1'],
            CopySourceParser::parse('/bucket/%E2%98%83/%E6%96%87%E4%BB%B6?versionId=version-1'),
        );
    }
}
