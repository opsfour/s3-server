<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XmlNamespaceTest extends TestCase
{
    #[DataProvider('foreignNamespaceDocuments')]
    public function test_mutating_xml_rejects_foreign_root_or_child_namespace(string $operation, string $xml): void
    {
        $this->expectException(MalformedXmlException::class);

        match ($operation) {
            'delete' => XmlRequestParser::parseDeleteObjects($xml),
            'retention' => XmlRequestParser::parseRetention($xml),
            'legal-hold' => XmlRequestParser::parseLegalHold($xml),
            'website' => XmlRequestParser::parseWebsiteConfiguration($xml),
            default => throw new \LogicException("Unknown XML operation: {$operation}"),
        };
    }

    /** @return array<string, array{string, string}> */
    public static function foreignNamespaceDocuments(): array
    {
        return [
            'delete root' => ['delete', '<Delete xmlns="urn:unexpected"><Object><Key>victim</Key></Object></Delete>'],
            'retention root' => ['retention', '<Retention xmlns="urn:unexpected"><Mode>GOVERNANCE</Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'],
            'legal hold root' => ['legal-hold', '<LegalHold xmlns="urn:unexpected"><Status>OFF</Status></LegalHold>'],
            'website root' => ['website', '<WebsiteConfiguration xmlns="urn:unexpected"><IndexDocument><Suffix>index.html</Suffix></IndexDocument></WebsiteConfiguration>'],
            'retention child' => ['retention', '<Retention xmlns:x="urn:unexpected"><x:Mode>GOVERNANCE</x:Mode><RetainUntilDate>2030-01-01T00:00:00Z</RetainUntilDate></Retention>'],
            'legal hold child' => ['legal-hold', '<LegalHold xmlns:x="urn:unexpected"><x:Status>OFF</x:Status></LegalHold>'],
            'website index child' => ['website', '<WebsiteConfiguration xmlns:x="urn:unexpected"><IndexDocument><x:Suffix>index.html</x:Suffix></IndexDocument></WebsiteConfiguration>'],
            'website redirect child' => ['website', '<WebsiteConfiguration xmlns:x="urn:unexpected"><RedirectAllRequestsTo><HostName>example.com</HostName><x:Protocol>https</x:Protocol></RedirectAllRequestsTo></WebsiteConfiguration>'],
        ];
    }

    public function test_same_s3_namespace_prefixes_are_read_in_object_lock_documents(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        self::assertSame([
            'mode' => 'COMPLIANCE',
            'retainUntilDate' => '2030-01-01T00:00:00Z',
        ], XmlRequestParser::parseRetention(
            '<Retention xmlns="' . $namespace . '" xmlns:s3="' . $namespace . '">'
            . '<s3:Mode>COMPLIANCE</s3:Mode><s3:RetainUntilDate>2030-01-01T00:00:00Z</s3:RetainUntilDate></Retention>',
        ));
        self::assertSame(['status' => 'ON'], XmlRequestParser::parseLegalHold(
            '<s3:LegalHold xmlns:s3="' . $namespace . '"><s3:Status>ON</s3:Status></s3:LegalHold>',
        ));
    }

    public function test_same_s3_namespace_prefixes_are_read_in_website_configuration(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        self::assertSame([
            'indexDocument' => 'home.html',
            'errorDocument' => 'errors/404.html',
            'redirectAllHost' => null,
            'redirectAllProtocol' => null,
            'routingRules' => [[
                'condition' => ['keyPrefixEquals' => 'old/'],
                'redirect' => ['replaceKeyPrefixWith' => 'new/'],
            ]],
        ], XmlRequestParser::parseWebsiteConfiguration(
            '<WebsiteConfiguration xmlns="' . $namespace . '" xmlns:s3="' . $namespace . '">'
            . '<s3:IndexDocument><s3:Suffix>home.html</s3:Suffix></s3:IndexDocument>'
            . '<s3:ErrorDocument><s3:Key>errors/404.html</s3:Key></s3:ErrorDocument>'
            . '<s3:RoutingRules><s3:RoutingRule><s3:Condition><s3:KeyPrefixEquals>old/</s3:KeyPrefixEquals></s3:Condition>'
            . '<s3:Redirect><s3:ReplaceKeyPrefixWith>new/</s3:ReplaceKeyPrefixWith></s3:Redirect>'
            . '</s3:RoutingRule></s3:RoutingRules></WebsiteConfiguration>',
        ));

        $redirect = XmlRequestParser::parseWebsiteConfiguration(
            '<s3:WebsiteConfiguration xmlns:s3="' . $namespace . '">'
            . '<s3:RedirectAllRequestsTo><s3:HostName>example.com</s3:HostName><s3:Protocol>https</s3:Protocol>'
            . '</s3:RedirectAllRequestsTo></s3:WebsiteConfiguration>',
        );
        self::assertSame('example.com', $redirect['redirectAllHost']);
        self::assertSame('https', $redirect['redirectAllProtocol']);
    }

    public function test_prefixed_create_bucket_and_lifecycle_documents_are_read_exactly(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        self::assertSame(['locationConstraint' => 'eu-west-1'], XmlRequestParser::parseCreateBucketConfiguration(
            '<s3:CreateBucketConfiguration xmlns:s3="' . $namespace . '">'
            . '<s3:LocationConstraint>eu-west-1</s3:LocationConstraint></s3:CreateBucketConfiguration>',
        ));

        $rules = XmlRequestParser::parseLifecycleConfiguration(
            '<s3:LifecycleConfiguration xmlns:s3="' . $namespace . '"><s3:Rule>'
            . '<s3:ID>archive</s3:ID><s3:Status>Enabled</s3:Status><s3:Filter><s3:Prefix>logs/</s3:Prefix></s3:Filter>'
            . '<s3:Transition><s3:Days>30</s3:Days><s3:StorageClass>GLACIER</s3:StorageClass></s3:Transition>'
            . '</s3:Rule></s3:LifecycleConfiguration>',
        );

        self::assertSame('archive', $rules[0]['id']);
        self::assertSame('logs/', $rules[0]['filter']['prefix'] ?? null);
        self::assertSame('GLACIER', $rules[0]['transitions'][0]['storageClass'] ?? null);
    }
}
