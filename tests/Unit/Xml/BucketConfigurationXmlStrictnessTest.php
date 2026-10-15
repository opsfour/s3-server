<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketConfigurationXmlStrictnessTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function malformedConfigurations(): iterable
    {
        yield 'CORS duplicate maximum age' => [
            'parseCorsConfiguration',
            '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '<MaxAgeSeconds>5</MaxAgeSeconds><MaxAgeSeconds>0</MaxAgeSeconds></CORSRule></CORSConfiguration>',
        ];
        yield 'CORS nested origin' => [
            'parseCorsConfiguration',
            '<CORSConfiguration xmlns:x="urn:foreign"><CORSRule><AllowedOrigin>https://good.invalid<x:Ignored/></AllowedOrigin>'
            . '<AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>',
        ];
        yield 'CORS origin with multiple wildcards' => [
            'parseCorsConfiguration',
            '<CORSConfiguration><CORSRule><AllowedOrigin>https://*.*.example</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '</CORSRule></CORSConfiguration>',
        ];
        yield 'CORS allowed header with multiple wildcards' => [
            'parseCorsConfiguration',
            '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '<AllowedHeader>x-*-*</AllowedHeader></CORSRule></CORSConfiguration>',
        ];
        foreach (['X-Ok&#10;Bad', 'X-Ok&#13;Bad', 'X-Ok: Bad', 'X Bad', 'X-Ünicode'] as $header) {
            yield 'CORS invalid allowed header ' . $header => [
                'parseCorsConfiguration',
                '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
                . '<AllowedHeader>' . $header . '</AllowedHeader></CORSRule></CORSConfiguration>',
            ];
        }
        yield 'CORS maximum age beyond signed 32-bit storage' => [
            'parseCorsConfiguration',
            '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '<MaxAgeSeconds>2147483648</MaxAgeSeconds></CORSRule></CORSConfiguration>',
        ];
        yield 'CORS identifier beyond 255 characters' => [
            'parseCorsConfiguration',
            '<CORSConfiguration><CORSRule><ID>' . str_repeat('x', 256) . '</ID><AllowedOrigin>*</AllowedOrigin>'
            . '<AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>',
        ];
        yield 'tag without value element' => [
            'parseTagging',
            '<Tagging><TagSet><Tag><Key>present</Key></Tag></TagSet></Tagging>',
        ];
        yield 'versioning invalid MFA delete value' => [
            'parseVersioningConfiguration',
            '<VersioningConfiguration><Status>Enabled</Status><MfaDelete>enable</MfaDelete></VersioningConfiguration>',
        ];
        foreach (['X-Ok&#10;Bad', 'X-Ok&#13;Bad', 'X-Ok: Bad', 'X Bad', 'X-Ünicode'] as $header) {
            yield 'CORS invalid exposed header ' . $header => [
                'parseCorsConfiguration',
                '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
                . '<ExposeHeader>' . $header . '</ExposeHeader></CORSRule></CORSConfiguration>',
            ];
        }
        yield 'encryption duplicate rule' => [
            'parseEncryptionConfiguration',
            '<ServerSideEncryptionConfiguration><Rule><ApplyServerSideEncryptionByDefault><SSEAlgorithm>AES256</SSEAlgorithm>'
            . '</ApplyServerSideEncryptionByDefault></Rule><Rule><ApplyServerSideEncryptionByDefault>'
            . '<SSEAlgorithm>aws:kms</SSEAlgorithm></ApplyServerSideEncryptionByDefault></Rule></ServerSideEncryptionConfiguration>',
        ];
        yield 'AES256 with KMS key ID' => [
            'parseEncryptionConfiguration',
            '<ServerSideEncryptionConfiguration><Rule><ApplyServerSideEncryptionByDefault><SSEAlgorithm>AES256</SSEAlgorithm>'
            . '<KMSMasterKeyID>alias/unused</KMSMasterKeyID></ApplyServerSideEncryptionByDefault></Rule></ServerSideEncryptionConfiguration>',
        ];
        yield 'AES256 with enabled bucket key' => [
            'parseEncryptionConfiguration',
            '<ServerSideEncryptionConfiguration><Rule><ApplyServerSideEncryptionByDefault><SSEAlgorithm>AES256</SSEAlgorithm>'
            . '</ApplyServerSideEncryptionByDefault><BucketKeyEnabled>true</BucketKeyEnabled></Rule></ServerSideEncryptionConfiguration>',
        ];
        yield 'AES256 with both KMS fields' => [
            'parseEncryptionConfiguration',
            '<ServerSideEncryptionConfiguration><Rule><ApplyServerSideEncryptionByDefault><SSEAlgorithm>AES256</SSEAlgorithm>'
            . '<KMSMasterKeyID>alias/unused</KMSMasterKeyID></ApplyServerSideEncryptionByDefault>'
            . '<BucketKeyEnabled>true</BucketKeyEnabled></Rule></ServerSideEncryptionConfiguration>',
        ];
        yield 'notification foreign-only configuration' => [
            'parseNotificationConfiguration',
            '<NotificationConfiguration xmlns:x="urn:foreign"><x:TopicConfiguration><x:Topic>https://notify.invalid/hook</x:Topic>'
            . '<x:Event>s3:ObjectCreated:Put</x:Event></x:TopicConfiguration></NotificationConfiguration>',
        ];
        yield 'notification nested event' => [
            'parseNotificationConfiguration',
            '<NotificationConfiguration xmlns:x="urn:foreign"><TopicConfiguration><Topic>https://notify.invalid/hook</Topic>'
            . '<Event>s3:ObjectCreated:Put<x:Ignored/></Event></TopicConfiguration></NotificationConfiguration>',
        ];
    }

    #[DataProvider('malformedConfigurations')]
    public function test_ambiguous_bucket_configuration_xml_is_rejected(string $method, string $xml): void
    {
        $this->expectException(MalformedXmlException::class);
        XmlRequestParser::{$method}($xml);
    }

    public function test_valid_prefixed_s3_elements_remain_accepted(): void
    {
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        self::assertCount(1, XmlRequestParser::parseCorsConfiguration(
            '<s:CORSConfiguration xmlns:s="' . $namespace . '"><s:CORSRule><s:AllowedOrigin>*</s:AllowedOrigin>'
            . '<s:AllowedMethod>GET</s:AllowedMethod></s:CORSRule></s:CORSConfiguration>',
        ));
        self::assertSame('AES256', XmlRequestParser::parseEncryptionConfiguration(
            '<s:ServerSideEncryptionConfiguration xmlns:s="' . $namespace . '"><s:Rule>'
            . '<s:ApplyServerSideEncryptionByDefault><s:SSEAlgorithm>AES256</s:SSEAlgorithm>'
            . '</s:ApplyServerSideEncryptionByDefault></s:Rule></s:ServerSideEncryptionConfiguration>',
        )['sseAlgorithm']);
        self::assertSame([], XmlRequestParser::parseNotificationConfiguration(
            '<s:NotificationConfiguration xmlns:s="' . $namespace . '"/>',
        ));
        self::assertCount(1, XmlRequestParser::parseNotificationConfiguration(
            '<s:NotificationConfiguration xmlns:s="' . $namespace . '"><s:TopicConfiguration>'
            . '<s:Topic>https://notify.invalid/hook</s:Topic><s:Event>s3:ObjectCreated:Put</s:Event>'
            . '</s:TopicConfiguration></s:NotificationConfiguration>',
        ));
    }

    public function test_disabled_bucket_key_does_not_change_aes256_configuration(): void
    {
        self::assertSame([
            'sseAlgorithm' => 'AES256',
            'kmsMasterKeyId' => null,
            'bucketKeyEnabled' => false,
        ], XmlRequestParser::parseEncryptionConfiguration(
            '<ServerSideEncryptionConfiguration><Rule><ApplyServerSideEncryptionByDefault><SSEAlgorithm>AES256</SSEAlgorithm>'
            . '</ApplyServerSideEncryptionByDefault><BucketKeyEnabled>false</BucketKeyEnabled></Rule></ServerSideEncryptionConfiguration>',
        ));
    }

    public function test_valid_cors_exposed_header_names_are_preserved(): void
    {
        $rules = XmlRequestParser::parseCorsConfiguration(
            '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '<ExposeHeader>ETag</ExposeHeader><ExposeHeader>x-amz-meta_title</ExposeHeader>'
            . '</CORSRule></CORSConfiguration>',
        );

        self::assertSame(['ETag', 'x-amz-meta_title'], $rules[0]['exposeHeaders']);
    }

    public function test_cors_id_and_explicit_zero_maximum_age_are_preserved(): void
    {
        $rules = XmlRequestParser::parseCorsConfiguration(
            '<CORSConfiguration><CORSRule><ID>sdk-rule</ID><AllowedOrigin>https://*.example</AllowedOrigin>'
            . '<AllowedMethod>GET</AllowedMethod><AllowedHeader>x-amz-*</AllowedHeader>'
            . '<MaxAgeSeconds>0</MaxAgeSeconds></CORSRule></CORSConfiguration>',
        );

        self::assertSame('sdk-rule', $rules[0]['id'] ?? null);
        self::assertSame(0, $rules[0]['maxAgeSeconds']);
    }

    public function test_cors_identifier_limit_counts_unicode_characters(): void
    {
        $id = str_repeat('é', 255);
        $rules = XmlRequestParser::parseCorsConfiguration(
            '<CORSConfiguration><CORSRule><ID>' . $id . '</ID><AllowedOrigin>https://example.test</AllowedOrigin>'
            . '<AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>',
        );

        self::assertSame($id, $rules[0]['id'] ?? null);
    }

    public function test_cors_maximum_age_accepts_leading_zero_decimal_notation(): void
    {
        $rules = XmlRequestParser::parseCorsConfiguration(
            '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '<MaxAgeSeconds>00060</MaxAgeSeconds></CORSRule></CORSConfiguration>',
        );

        self::assertSame(60, $rules[0]['maxAgeSeconds']);
    }

    public function test_tagging_requires_value_element_but_accepts_empty_value(): void
    {
        self::assertSame([['key' => 'present', 'value' => '']], XmlRequestParser::parseTagging(
            '<Tagging><TagSet><Tag><Key>present</Key><Value/></Tag></TagSet></Tagging>',
        ));
    }

    public function test_cors_rejects_empty_allowed_origin(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseCorsConfiguration(
            '<CORSConfiguration><CORSRule><AllowedOrigin/><AllowedMethod>GET</AllowedMethod>'
            . '</CORSRule></CORSConfiguration>',
        );
    }

    public function test_cors_rejects_more_than_one_hundred_rules(): void
    {
        $this->expectException(MalformedXmlException::class);

        $rule = '<CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod></CORSRule>';
        XmlRequestParser::parseCorsConfiguration('<CORSConfiguration>' . str_repeat($rule, 101) . '</CORSConfiguration>');
    }
}
