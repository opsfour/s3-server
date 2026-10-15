<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeleteObjectsRequestParserTest extends TestCase
{
    #[DataProvider('ambiguousDocuments')]
    public function test_rejects_ambiguous_or_unsupported_delete_documents(string $xml): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseDeleteObjects($xml);
    }

    /** @return array<string, array{string}> */
    public static function ambiguousDocuments(): array
    {
        return [
            'missing object' => ['<Delete/>'],
            'unknown root element' => ['<Delete><Object><Key>a</Key></Object><Extra/></Delete>'],
            'duplicate quiet' => ['<Delete><Object><Key>a</Key></Object><Quiet>true</Quiet><Quiet>false</Quiet></Delete>'],
            'invalid quiet' => ['<Delete><Object><Key>a</Key></Object><Quiet>yes</Quiet></Delete>'],
            'duplicate key' => ['<Delete><Object><Key>a</Key><Key>b</Key></Object></Delete>'],
            'missing key' => ['<Delete><Object><VersionId>v1</VersionId></Object></Delete>'],
            'key above 1024 bytes' => ['<Delete><Object><Key>' . str_repeat('a', 1025) . '</Key></Object></Delete>'],
            'multibyte key above 1024 bytes' => ['<Delete><Object><Key>' . str_repeat("\u{00E9}", 513) . '</Key></Object></Delete>'],
            'duplicate version' => ['<Delete><Object><Key>a</Key><VersionId>v1</VersionId><VersionId>v2</VersionId></Object></Delete>'],
            'empty version' => ['<Delete><Object><Key>a</Key><VersionId/></Object></Delete>'],
            'unsupported conditional delete field' => ['<Delete><Object><Key>a</Key><ETag>"etag"</ETag></Object></Delete>'],
            'prefixed unsupported field' => ['<Delete><Object><Key>a</Key><x:ETag xmlns:x="urn:extension">"etag"</x:ETag></Object></Delete>'],
            'prefixed duplicate key' => ['<Delete><Object><Key>a</Key><x:Key xmlns:x="urn:extension">b</x:Key></Object></Delete>'],
            'foreign namespace version' => ['<Delete xmlns:x="urn:unexpected"><Object><Key>a</Key><x:VersionId>fake</x:VersionId></Object></Delete>'],
            'foreign namespace object' => ['<Delete xmlns:x="urn:unexpected"><x:Object><Key>a</Key></x:Object></Delete>'],
            'nested key' => ['<Delete><Object><Key>a<Other>b</Other></Key></Object></Delete>'],
            'nested version' => ['<Delete><Object><Key>a</Key><VersionId>v1<Other>v2</Other></VersionId></Object></Delete>'],
            'nested quiet' => ['<Delete><Object><Key>a</Key></Object><Quiet>true<Other>false</Other></Quiet></Delete>'],
        ];
    }

    public function test_accepts_explicit_quiet_false_and_specific_version(): void
    {
        self::assertSame([
            'quiet' => false,
            'objects' => [['key' => 'a', 'versionId' => 'v1']],
        ], XmlRequestParser::parseDeleteObjects(
            '<Delete><Quiet>false</Quiet><Object><Key>a</Key><VersionId>v1</VersionId></Object></Delete>',
        ));
    }

    public function test_accepts_key_at_1024_byte_boundary(): void
    {
        $key = str_repeat("\u{00E9}", 512);

        self::assertSame([
            'quiet' => false,
            'objects' => [['key' => $key, 'versionId' => null]],
        ], XmlRequestParser::parseDeleteObjects(
            '<Delete><Object><Key>' . $key . '</Key></Object></Delete>',
        ));
    }

    public function test_accepts_prefixed_children_in_the_same_s3_namespace(): void
    {
        self::assertSame([
            'quiet' => true,
            'objects' => [['key' => 'a', 'versionId' => 'v1']],
        ], XmlRequestParser::parseDeleteObjects(
            '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/" xmlns:s3="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<s3:Quiet>true</s3:Quiet><s3:Object><s3:Key>a</s3:Key><s3:VersionId>v1</s3:VersionId></s3:Object></Delete>',
        ));
    }
}
