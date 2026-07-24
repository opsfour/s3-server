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

    public function test_validate_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ObjectTagValidator::validate([['key' => "\xC3\x28", 'value' => 'stable']], 10);
    }
}
