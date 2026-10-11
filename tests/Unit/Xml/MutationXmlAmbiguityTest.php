<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MutationXmlAmbiguityTest extends TestCase
{
    #[DataProvider('ambiguousDocuments')]
    public function test_rejects_ambiguous_mutating_xml(string $parser, string $xml): void
    {
        $this->expectException(MalformedXmlException::class);
        XmlRequestParser::$parser($xml);
    }

    /** @return array<string, array{string, string}> */
    public static function ambiguousDocuments(): array
    {
        return [
            'tagging duplicate key' => ['parseTagging', '<Tagging><TagSet><Tag><Key>public</Key><Key>private</Key><Value>1</Value></Tag></TagSet></Tagging>'],
            'tagging foreign field' => ['parseTagging', '<Tagging xmlns:x="urn:other"><TagSet><Tag><Key>public</Key><Value>1</Value><x:Value>2</x:Value></Tag></TagSet></Tagging>'],
            'tagging nested scalar' => ['parseTagging', '<Tagging><TagSet><Tag><Key>public<x:Ignored xmlns:x="urn:other"/></Key><Value>1</Value></Tag></TagSet></Tagging>'],
            'object lock duplicate period' => ['parseObjectLockConfiguration', '<ObjectLockConfiguration><Rule><DefaultRetention><Mode>GOVERNANCE</Mode><Days>2</Days><Days>3</Days></DefaultRetention></Rule></ObjectLockConfiguration>'],
            'object lock foreign rule' => ['parseObjectLockConfiguration', '<ObjectLockConfiguration xmlns:x="urn:other"><x:Rule><x:DefaultRetention><x:Mode>COMPLIANCE</x:Mode><x:Days>2</x:Days></x:DefaultRetention></x:Rule></ObjectLockConfiguration>'],
            'object lock nested period' => ['parseObjectLockConfiguration', '<ObjectLockConfiguration><Rule><DefaultRetention><Mode>GOVERNANCE</Mode><Days>2<x:Ignored xmlns:x="urn:other"/></Days></DefaultRetention></Rule></ObjectLockConfiguration>'],
            'retention nested mode' => ['parseRetention', '<Retention><Mode>GOVERNANCE<x:Ignored xmlns:x="urn:other"/></Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'],
            'legal hold nested status' => ['parseLegalHold', '<LegalHold><Status>ON<x:Ignored xmlns:x="urn:other"/></Status></LegalHold>'],
            'restore duplicate days' => ['parseRestoreRequest', '<RestoreRequest><Days>1</Days><Days>30</Days></RestoreRequest>'],
            'restore foreign root child' => ['parseRestoreRequest', '<RestoreRequest xmlns:x="urn:other"><Days>1</Days><x:GlacierJobParameters><x:Tier>Expedited</x:Tier></x:GlacierJobParameters></RestoreRequest>'],
            'restore unknown Glacier child' => ['parseRestoreRequest', '<RestoreRequest><Days>1</Days><GlacierJobParameters><Tier>Standard</Tier><Ignored>yes</Ignored></GlacierJobParameters></RestoreRequest>'],
            'ACL duplicate permission' => ['parseAccessControlPolicy', '<AccessControlPolicy><AccessControlList><Grant><Grantee><ID>owner</ID></Grantee><Permission>READ</Permission><Permission>WRITE</Permission></Grant></AccessControlList></AccessControlPolicy>'],
            'ACL nested identifier' => ['parseAccessControlPolicy', '<AccessControlPolicy><AccessControlList><Grant><Grantee><ID>owner<x:Ignored xmlns:x="urn:other"/></ID></Grantee><Permission>FULL_CONTROL</Permission></Grant></AccessControlList></AccessControlPolicy>'],
            'ACL conflicting identifiers' => ['parseAccessControlPolicy', '<AccessControlPolicy><AccessControlList><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group"><ID>owner</ID><URI>http://acs.amazonaws.com/groups/global/AllUsers</URI></Grantee><Permission>READ</Permission></Grant></AccessControlList></AccessControlPolicy>'],
            'delete mixed text' => ['parseDeleteObjects', '<Delete>junk<Object><Key>key</Key></Object></Delete>'],
            'multipart completion mixed text' => ['parseCompleteMultipartUpload', '<CompleteMultipartUpload>junk<Part><PartNumber>1</PartNumber><ETag>"etag"</ETag></Part></CompleteMultipartUpload>'],
            'retention mixed text' => ['parseRetention', '<Retention>junk<Mode>GOVERNANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'],
            'tagging mixed text' => ['parseTagging', '<Tagging>junk<TagSet><Tag><Key>key</Key><Value>value</Value></Tag></TagSet></Tagging>'],
        ];
    }

    public function test_supported_s3_namespace_and_prefixed_children_remain_valid(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';

        self::assertSame(
            [['key' => 'k', 'value' => 'v']],
            XmlRequestParser::parseTagging('<Tagging xmlns="' . $namespace . '"><TagSet><Tag><Key>k</Key><Value>v</Value></Tag></TagSet></Tagging>'),
        );
        self::assertSame(
            ['objectLockEnabled' => 'Enabled', 'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 2]]],
            XmlRequestParser::parseObjectLockConfiguration('<s:ObjectLockConfiguration xmlns:s="' . $namespace . '"><s:ObjectLockEnabled>Enabled</s:ObjectLockEnabled><s:Rule><s:DefaultRetention><s:Mode>GOVERNANCE</s:Mode><s:Days>2</s:Days></s:DefaultRetention></s:Rule></s:ObjectLockConfiguration>'),
        );
        self::assertSame(
            ['mode' => 'GOVERNANCE', 'retainUntilDate' => '2030-01-01T00:00:00Z'],
            XmlRequestParser::parseRetention('<Retention xmlns="' . $namespace . '"><Mode>GOVERNANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'),
        );
        self::assertSame(
            ['status' => 'ON'],
            XmlRequestParser::parseLegalHold('<s:LegalHold xmlns:s="' . $namespace . '"><s:Status>ON</s:Status></s:LegalHold>'),
        );
        self::assertSame(
            ['days' => 1, 'tier' => 'Standard'],
            XmlRequestParser::parseRestoreRequest('<s:RestoreRequest xmlns:s="' . $namespace . '"><s:Days>1</s:Days><s:GlacierJobParameters><s:Tier>Standard</s:Tier></s:GlacierJobParameters></s:RestoreRequest>'),
        );
        self::assertSame(
            ['ownerId' => 'owner', 'grants' => [['granteeType' => 'CanonicalUser', 'granteeId' => 'owner', 'permission' => 'FULL_CONTROL']]],
            XmlRequestParser::parseAccessControlPolicy('<AccessControlPolicy xmlns="' . $namespace . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Owner><ID>owner</ID></Owner><AccessControlList><Grant><Grantee xsi:type="CanonicalUser"><ID>owner</ID></Grantee><Permission>FULL_CONTROL</Permission></Grant></AccessControlList></AccessControlPolicy>'),
        );
    }
}
