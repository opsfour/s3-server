<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteScalarStrictnessTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nestedScalars(): iterable
    {
        yield 'index suffix' => ['<IndexDocument><Suffix>index<Ignored/>.html</Suffix></IndexDocument>'];
        yield 'error document key' => ['<IndexDocument><Suffix>index.html</Suffix></IndexDocument><ErrorDocument><Key>error<Ignored/>.html</Key></ErrorDocument>'];
        yield 'redirect all host' => ['<RedirectAllRequestsTo><HostName>example.com<Ignored/></HostName></RedirectAllRequestsTo>'];
        yield 'redirect all protocol' => ['<RedirectAllRequestsTo><HostName>example.com</HostName><Protocol>https<Ignored/></Protocol></RedirectAllRequestsTo>'];
        yield 'routing condition prefix' => [self::routingRule('<KeyPrefixEquals>docs/<Ignored/></KeyPrefixEquals>', '<ReplaceKeyWith>new.html</ReplaceKeyWith>')];
        yield 'routing condition error' => [self::routingRule('<HttpErrorCodeReturnedEquals>404<Ignored/></HttpErrorCodeReturnedEquals>', '<ReplaceKeyWith>new.html</ReplaceKeyWith>')];
        yield 'routing replace prefix' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<ReplaceKeyPrefixWith>new/<Ignored/></ReplaceKeyPrefixWith>')];
        yield 'routing replace key' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<ReplaceKeyWith>new<Ignored/>.html</ReplaceKeyWith>')];
        yield 'routing protocol' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<Protocol>https<Ignored/></Protocol>')];
        yield 'routing host' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<HostName>example.com<Ignored/></HostName>')];
        yield 'routing redirect code' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<HttpRedirectCode>301<Ignored/></HttpRedirectCode>')];
    }

    #[DataProvider('nestedScalars')]
    public function test_website_scalar_fields_reject_nested_elements(string $children): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseWebsiteConfiguration('<WebsiteConfiguration>' . $children . '</WebsiteConfiguration>');
    }

    #[DataProvider('oversizedKeyFields')]
    public function test_website_key_fields_are_limited_to_s3_object_key_size(string $children): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseWebsiteConfiguration('<WebsiteConfiguration>' . $children . '</WebsiteConfiguration>');
    }

    /** @return iterable<string, array{string}> */
    public static function oversizedKeyFields(): iterable
    {
        $oversized = str_repeat('a', 1_025);

        yield 'index suffix' => ['<IndexDocument><Suffix>' . $oversized . '</Suffix></IndexDocument>'];
        yield 'error document key' => [
            '<IndexDocument><Suffix>index.html</Suffix></IndexDocument>'
            . '<ErrorDocument><Key>' . $oversized . '</Key></ErrorDocument>',
        ];
        yield 'routing condition prefix' => [self::routingRule(
            '<KeyPrefixEquals>' . $oversized . '</KeyPrefixEquals>',
            '<ReplaceKeyWith>new.html</ReplaceKeyWith>',
        )];
        yield 'routing replacement prefix' => [self::routingRule(
            '<KeyPrefixEquals>docs/</KeyPrefixEquals>',
            '<ReplaceKeyPrefixWith>' . $oversized . '</ReplaceKeyPrefixWith>',
        )];
        yield 'routing replacement key' => [self::routingRule(
            '<KeyPrefixEquals>docs/</KeyPrefixEquals>',
            '<ReplaceKeyWith>' . $oversized . '</ReplaceKeyWith>',
        )];
    }

    public function test_prefixed_website_scalar_values_remain_valid(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        $configuration = XmlRequestParser::parseWebsiteConfiguration(
            '<s:WebsiteConfiguration xmlns:s="' . $namespace . '">'
            . '<s:IndexDocument><s:Suffix>index.html</s:Suffix></s:IndexDocument>'
            . '<s:RoutingRules><s:RoutingRule><s:Condition><s:KeyPrefixEquals>docs/</s:KeyPrefixEquals></s:Condition>'
            . '<s:Redirect><s:ReplaceKeyPrefixWith>new/</s:ReplaceKeyPrefixWith></s:Redirect>'
            . '</s:RoutingRule></s:RoutingRules></s:WebsiteConfiguration>',
        );

        self::assertSame('index.html', $configuration['indexDocument']);
        self::assertSame('docs/', $configuration['routingRules'][0]['condition']['keyPrefixEquals'] ?? null);
        self::assertSame('new/', $configuration['routingRules'][0]['redirect']['replaceKeyPrefixWith'] ?? null);
    }

    public function test_error_document_key_must_not_be_empty(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseWebsiteConfiguration(
            '<WebsiteConfiguration><IndexDocument><Suffix>index.html</Suffix></IndexDocument>'
            . '<ErrorDocument><Key/></ErrorDocument></WebsiteConfiguration>',
        );
    }

    public function test_website_rejects_more_than_fifty_routing_rules(): void
    {
        $this->expectException(MalformedXmlException::class);

        $rule = '<RoutingRule><Redirect><HostName>example.com</HostName></Redirect></RoutingRule>';
        XmlRequestParser::parseWebsiteConfiguration(
            '<WebsiteConfiguration><IndexDocument><Suffix>index.html</Suffix></IndexDocument>'
            . '<RoutingRules>' . str_repeat($rule, 51) . '</RoutingRules></WebsiteConfiguration>',
        );
    }

    #[DataProvider('invalidRedirectHosts')]
    public function test_website_rejects_hosts_that_delivery_cannot_use(string $children): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseWebsiteConfiguration('<WebsiteConfiguration>' . $children . '</WebsiteConfiguration>');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRedirectHosts(): iterable
    {
        yield 'redirect-all alphabetic port' => ['<RedirectAllRequestsTo><HostName>example.com:http</HostName></RedirectAllRequestsTo>'];
        yield 'redirect-all zero port' => ['<RedirectAllRequestsTo><HostName>example.com:0</HostName></RedirectAllRequestsTo>'];
        yield 'redirect-all zero-padded zero port' => ['<RedirectAllRequestsTo><HostName>example.com:00000</HostName></RedirectAllRequestsTo>'];
        yield 'redirect-all port above maximum' => ['<RedirectAllRequestsTo><HostName>example.com:65536</HostName></RedirectAllRequestsTo>'];
        yield 'redirect-all six digit port' => ['<RedirectAllRequestsTo><HostName>example.com:123456</HostName></RedirectAllRequestsTo>'];
        yield 'routing port above maximum' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<HostName>example.com:99999</HostName>')];
        yield 'routing IPv6 literal' => [self::routingRule('<KeyPrefixEquals>docs/</KeyPrefixEquals>', '<HostName>[::1]</HostName>')];
    }

    public function test_website_accepts_maximum_redirect_port(): void
    {
        $configuration = XmlRequestParser::parseWebsiteConfiguration(
            '<WebsiteConfiguration><RedirectAllRequestsTo><HostName>example.com:65535</HostName>'
            . '<Protocol>https</Protocol></RedirectAllRequestsTo></WebsiteConfiguration>',
        );

        self::assertSame('example.com:65535', $configuration['redirectAllHost']);
    }

    private static function routingRule(string $condition, string $redirect): string
    {
        return '<IndexDocument><Suffix>index.html</Suffix></IndexDocument><RoutingRules><RoutingRule>'
            . '<Condition>' . $condition . '</Condition><Redirect>' . $redirect . '</Redirect>'
            . '</RoutingRule></RoutingRules>';
    }
}
