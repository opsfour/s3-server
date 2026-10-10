<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Xml\XmlResponseBuilder;
use PHPUnit\Framework\TestCase;

final class CopyObjectChecksumResponseTest extends TestCase
{
    public function test_full_object_checksums_follow_the_sdk_wire_shape(): void
    {
        $xml = new \SimpleXMLElement(XmlResponseBuilder::copyObjectResult(
            '"etag"',
            '2026-01-02T03:04:05.000Z',
            'crc32',
            'crc32c',
            'sha1',
            'sha256',
        ));

        self::assertSame(
            ['ETag', 'LastModified', 'ChecksumType', 'ChecksumCRC32', 'ChecksumCRC32C', 'ChecksumSHA1', 'ChecksumSHA256'],
            array_map(
                static fn(\SimpleXMLElement $child): string => $child->getName(),
                $xml->xpath('./*') ?: [],
            ),
        );
        self::assertSame('FULL_OBJECT', (string) $xml->ChecksumType);
        self::assertSame('crc32', (string) $xml->ChecksumCRC32);
        self::assertSame('crc32c', (string) $xml->ChecksumCRC32C);
        self::assertSame('sha1', (string) $xml->ChecksumSHA1);
        self::assertSame('sha256', (string) $xml->ChecksumSHA256);
    }

    public function test_legacy_copy_result_omits_checksum_values_and_type(): void
    {
        $body = XmlResponseBuilder::copyObjectResult('"etag"', '2026-01-02T03:04:05.000Z');

        self::assertStringNotContainsString('<ChecksumType>', $body);
        self::assertStringNotContainsString('<ChecksumCRC32>', $body);
        self::assertStringNotContainsString('<ChecksumCRC32C>', $body);
        self::assertStringNotContainsString('<ChecksumSHA1>', $body);
        self::assertStringNotContainsString('<ChecksumSHA256>', $body);
    }
}
