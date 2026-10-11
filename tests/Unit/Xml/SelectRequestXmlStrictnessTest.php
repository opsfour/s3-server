<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SelectRequestXmlStrictnessTest extends TestCase
{
    #[DataProvider('ambiguousDocuments')]
    public function test_select_rejects_schema_unknown_attributes_and_mixed_container_text(string $xml): void
    {
        $method = new \ReflectionMethod(SelectObjectContentHandler::class, 'parseSelectRequest');

        $this->expectException(MalformedXmlException::class);
        $method->invoke(null, $xml);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousDocuments(): iterable
    {
        yield 'root attribute' => [self::request(' source="client"')];
        yield 'nested attribute' => [str_replace('<CSV/>', '<CSV dialect="custom"/>', self::request())];
        yield 'root mixed text' => [str_replace('<Expression>', 'ignored<Expression>', self::request())];
        yield 'serialization mixed text' => [str_replace('<InputSerialization>', '<InputSerialization>ignored', self::request())];
        yield 'CSV mixed text' => [str_replace('<CSV/>', '<CSV>ignored</CSV>', self::request())];
    }

    private static function request(string $rootAttributes = ''): string
    {
        return '<SelectObjectContentRequest' . $rootAttributes . '>'
            . '<Expression>SELECT * FROM s3object</Expression><ExpressionType>SQL</ExpressionType>'
            . '<InputSerialization><CSV/></InputSerialization>'
            . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>';
    }
}
