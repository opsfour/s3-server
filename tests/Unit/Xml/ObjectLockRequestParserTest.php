<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectLockRequestParserTest extends TestCase
{
    #[DataProvider('invalidRetentionDocuments')]
    public function test_retention_rejects_ambiguous_or_unsupported_children(string $xml): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseRetention($xml);
    }

    /** @return array<string, array{string}> */
    public static function invalidRetentionDocuments(): array
    {
        return [
            'duplicate mode' => ['<Retention><Mode>GOVERNANCE</Mode><Mode>COMPLIANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'],
            'duplicate date' => ['<Retention><Mode>GOVERNANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate><RetainUntilDate>2031-01-01T00:00:00Z</RetainUntilDate></Retention>'],
            'unknown child' => ['<Retention><Mode>GOVERNANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate><Bypass>true</Bypass></Retention>'],
        ];
    }

    #[DataProvider('invalidLegalHoldDocuments')]
    public function test_legal_hold_rejects_ambiguous_or_unsupported_children(string $xml): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseLegalHold($xml);
    }

    /** @return array<string, array{string}> */
    public static function invalidLegalHoldDocuments(): array
    {
        return [
            'duplicate status' => ['<LegalHold><Status>OFF</Status><Status>ON</Status></LegalHold>'],
            'unknown child' => ['<LegalHold><Status>OFF</Status><Reason>expired</Reason></LegalHold>'],
        ];
    }

    public function test_namespaced_documents_keep_their_supported_values(): void
    {
        self::assertSame(
            ['mode' => 'COMPLIANCE', 'retainUntilDate' => '2030-01-01T00:00:00Z'],
            XmlRequestParser::parseRetention('<Retention xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Mode>COMPLIANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'),
        );
        self::assertSame(
            ['status' => 'ON'],
            XmlRequestParser::parseLegalHold('<LegalHold xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>ON</Status></LegalHold>'),
        );
    }
}
