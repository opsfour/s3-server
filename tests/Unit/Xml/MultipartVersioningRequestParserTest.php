<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\TestCase;

final class MultipartVersioningRequestParserTest extends TestCase
{
    public function test_supported_s3_namespace_and_matching_prefix_remain_valid(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';

        self::assertSame(
            ['status' => 'Enabled'],
            XmlRequestParser::parseVersioningConfiguration(
                '<VersioningConfiguration xmlns="' . $namespace . '"><Status>Enabled</Status></VersioningConfiguration>',
            ),
        );
        self::assertSame(
            [['partNumber' => 1, 'etag' => '"etag"']],
            XmlRequestParser::parseCompleteMultipartUpload(
                '<s:CompleteMultipartUpload xmlns:s="' . $namespace . '"><s:Part><s:PartNumber>1</s:PartNumber><s:ETag>"etag"</s:ETag></s:Part></s:CompleteMultipartUpload>',
            ),
        );
    }
}
