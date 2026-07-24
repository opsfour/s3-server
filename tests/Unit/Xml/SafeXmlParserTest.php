<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\SafeXmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeXmlParserTest extends TestCase
{
    public function test_parses_normal_control_document(): void
    {
        $element = SafeXmlParser::parse('<Tagging><Value>a &amp; b</Value></Tagging>');

        self::assertSame('a & b', (string) $element->Value);
    }

    #[DataProvider('unsafeDocuments')]
    public function test_rejects_dtd_and_entity_declarations(string $xml): void
    {
        $this->expectException(MalformedXmlException::class);

        SafeXmlParser::parse($xml);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeDocuments(): array
    {
        return [
            'external entity' => [
                '<!DOCTYPE x [<!ENTITY payload SYSTEM "file:///etc/passwd">]><x>&payload;</x>',
            ],
            'internal expansion' => [
                '<!DOCTYPE x [<!ENTITY a "123"><!ENTITY b "&a;&a;&a;">]><x>&b;</x>',
            ],
            'case-insensitive declaration guard' => [
                '<!doctype x><x/>',
            ],
        ];
    }
}
