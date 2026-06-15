<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use OpsFour\S3Server\Xml\XmlResponseBuilder;
use PHPUnit\Framework\TestCase;

final class AclXmlParsingTest extends TestCase
{
    // -----------------------------------------------------------------
    // Parsing: XmlRequestParser::parseAccessControlPolicy
    // -----------------------------------------------------------------

    public function test_parse_canonical_user_grant(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">
                        <ID>user-a</ID>
                    </Grantee>
                    <Permission>READ</Permission>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame('owner-1', $result['ownerId']);
        $this->assertCount(1, $result['grants']);
        $this->assertSame('CanonicalUser', $result['grants'][0]['granteeType']);
        $this->assertSame('user-a', $result['grants'][0]['granteeId']);
        $this->assertSame('READ', $result['grants'][0]['permission']);
    }

    public function test_parse_group_grant(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group">
                        <URI>http://acs.amazonaws.com/groups/global/AllUsers</URI>
                    </Grantee>
                    <Permission>READ</Permission>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertCount(1, $result['grants']);
        $this->assertSame('Group', $result['grants'][0]['granteeType']);
        $this->assertSame('http://acs.amazonaws.com/groups/global/AllUsers', $result['grants'][0]['granteeId']);
        $this->assertSame('READ', $result['grants'][0]['permission']);
    }

    public function test_parse_mixed_grants(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">
                        <ID>owner-1</ID>
                    </Grantee>
                    <Permission>FULL_CONTROL</Permission>
                </Grant>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group">
                        <URI>http://acs.amazonaws.com/groups/global/AllUsers</URI>
                    </Grantee>
                    <Permission>READ</Permission>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertCount(2, $result['grants']);
        $this->assertSame('CanonicalUser', $result['grants'][0]['granteeType']);
        $this->assertSame('Group', $result['grants'][1]['granteeType']);
    }

    public function test_parse_fallback_canonical_user_without_xsi_type(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee><ID>user-a</ID></Grantee>
                    <Permission>WRITE</Permission>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame('CanonicalUser', $result['grants'][0]['granteeType']);
        $this->assertSame('user-a', $result['grants'][0]['granteeId']);
    }

    public function test_parse_fallback_group_without_xsi_type(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee><URI>http://acs.amazonaws.com/groups/global/AuthenticatedUsers</URI></Grantee>
                    <Permission>READ</Permission>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame('Group', $result['grants'][0]['granteeType']);
        $this->assertSame('http://acs.amazonaws.com/groups/global/AuthenticatedUsers', $result['grants'][0]['granteeId']);
    }

    public function test_parse_owner_extraction(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>my-owner-id</ID></Owner>
            <AccessControlList></AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame('my-owner-id', $result['ownerId']);
    }

    public function test_parse_missing_owner_element(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <AccessControlList></AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame('', $result['ownerId']);
    }

    public function test_parse_empty_access_control_list(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList></AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame([], $result['grants']);
    }

    public function test_parse_missing_permission_throws(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">
                        <ID>user-a</ID>
                    </Grantee>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $this->expectException(MalformedXmlException::class);
        XmlRequestParser::parseAccessControlPolicy($xml);
    }

    public function test_parse_multiple_permissions(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Owner><ID>owner-1</ID></Owner>
            <AccessControlList>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">
                        <ID>owner-1</ID>
                    </Grantee>
                    <Permission>FULL_CONTROL</Permission>
                </Grant>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">
                        <ID>user-a</ID>
                    </Grantee>
                    <Permission>READ</Permission>
                </Grant>
                <Grant>
                    <Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">
                        <ID>user-b</ID>
                    </Grantee>
                    <Permission>WRITE</Permission>
                </Grant>
            </AccessControlList>
        </AccessControlPolicy>
        XML;

        $result = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertCount(3, $result['grants']);
        $this->assertSame('FULL_CONTROL', $result['grants'][0]['permission']);
        $this->assertSame('READ', $result['grants'][1]['permission']);
        $this->assertSame('WRITE', $result['grants'][2]['permission']);
    }

    // -----------------------------------------------------------------
    // Serialization: XmlResponseBuilder::aclResult
    // -----------------------------------------------------------------

    public function test_serialize_canonical_user_grant(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'FULL_CONTROL'],
        ];

        $xml = XmlResponseBuilder::aclResult('owner-1', 'Owner Display', $grants);

        $this->assertStringContainsString('<Owner>', $xml);
        $this->assertStringContainsString('<ID>owner-1</ID>', $xml);
        $this->assertStringContainsString('<DisplayName>Owner Display</DisplayName>', $xml);
        $this->assertStringContainsString('xsi:type="CanonicalUser"', $xml);
        $this->assertStringContainsString('<ID>user-a</ID>', $xml);
        $this->assertStringContainsString('<Permission>FULL_CONTROL</Permission>', $xml);
    }

    public function test_serialize_group_grant(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers', 'permission' => 'READ'],
        ];

        $xml = XmlResponseBuilder::aclResult('owner-1', '', $grants);

        $this->assertStringContainsString('xsi:type="Group"', $xml);
        $this->assertStringContainsString('<URI>http://acs.amazonaws.com/groups/global/AllUsers</URI>', $xml);
        $this->assertStringContainsString('<Permission>READ</Permission>', $xml);
    }

    public function test_serialize_empty_grants(): void
    {
        $xml = XmlResponseBuilder::aclResult('owner-1', '', []);

        $this->assertStringContainsString('<AccessControlList/>', $xml);
        $this->assertStringNotContainsString('<Grant>', $xml);
    }

    public function test_parse_serialize_round_trip(): void
    {
        $originalGrants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'owner-1', 'permission' => 'FULL_CONTROL'],
            ['granteeType' => 'Group', 'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers', 'permission' => 'READ'],
        ];

        // Serialize.
        $xml = XmlResponseBuilder::aclResult('owner-1', 'Display', $originalGrants);

        // Parse back.
        $parsed = XmlRequestParser::parseAccessControlPolicy($xml);

        $this->assertSame('owner-1', $parsed['ownerId']);
        $this->assertCount(2, $parsed['grants']);
        $this->assertSame('CanonicalUser', $parsed['grants'][0]['granteeType']);
        $this->assertSame('owner-1', $parsed['grants'][0]['granteeId']);
        $this->assertSame('FULL_CONTROL', $parsed['grants'][0]['permission']);
        $this->assertSame('Group', $parsed['grants'][1]['granteeType']);
        $this->assertSame('http://acs.amazonaws.com/groups/global/AllUsers', $parsed['grants'][1]['granteeId']);
        $this->assertSame('READ', $parsed['grants'][1]['permission']);
    }
}
