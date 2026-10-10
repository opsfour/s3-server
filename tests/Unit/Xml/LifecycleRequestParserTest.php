<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleRequestParserTest extends TestCase
{
    public function test_supported_tag_filter_and_midnight_dates_remain_accepted(): void
    {
        $rules = XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><ID>dated</ID><Status>Enabled</Status>'
            . '<Filter><And><Prefix>safe/</Prefix><Tag><Key>class</Key><Value>temp</Value></Tag></And></Filter>'
            . '<Expiration><Date>2026-12-01T00:00:00.000Z</Date></Expiration>'
            . '<Transition><Date>2026-11-01T00:00:00+00:00</Date><StorageClass>STANDARD_IA</StorageClass></Transition>'
            . '</Rule></LifecycleConfiguration>',
        );

        self::assertSame('safe/', $rules[0]['filter']['and']['prefix'] ?? null);
        self::assertSame([['key' => 'class', 'value' => 'temp']], $rules[0]['filter']['and']['tags'] ?? null);
        self::assertSame('2026-12-01T00:00:00.000Z', $rules[0]['expiration']['date'] ?? null);
        self::assertSame('2026-11-01T00:00:00+00:00', $rules[0]['transitions'][0]['date'] ?? null);

        $keyOnly = XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><Status>Enabled</Status>'
            . '<Filter><Tag><Key>flag</Key></Tag></Filter><Expiration><Days>7</Days></Expiration>'
            . '</Rule></LifecycleConfiguration>',
        );
        self::assertSame(['key' => 'flag', 'value' => ''], $keyOnly[0]['filter']['tag'] ?? null);
    }

    #[DataProvider('malformedFilterProvider')]
    public function test_filter_structure_cannot_be_weakened_by_ignored_or_ambiguous_xml(string $filter): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration xmlns:x="urn:extension"><Rule><Status>Enabled</Status>'
            . $filter
            . '<Expiration><Days>7</Days></Expiration></Rule></LifecycleConfiguration>',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFilterProvider(): iterable
    {
        yield 'foreign namespace prefix' => ['<Filter><x:Prefix>safe/</x:Prefix></Filter>'];
        yield 'nested prefix content' => ['<Filter><Prefix>safe/<Ignored/></Prefix></Filter>'];
        yield 'nested tag key content' => ['<Filter><Tag><Key>env<Ignored/></Key><Value>prod</Value></Tag></Filter>'];
        yield 'single And predicate' => ['<Filter><And><Prefix>safe/</Prefix></And></Filter>'];
        yield 'duplicate And tag key' => [
            '<Filter><And>'
            . '<Tag><Key>env</Key><Value>prod</Value></Tag>'
            . '<Tag><Key>env</Key><Value>dev</Value></Tag>'
            . '</And></Filter>',
        ];
        yield 'nested legacy prefix content' => ['<Prefix><Unexpected>safe/</Unexpected></Prefix>'];
    }

    #[DataProvider('nestedActionScalarProvider')]
    public function test_lifecycle_action_scalars_reject_nested_elements(string $ruleBody): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><ID>nested</ID><Status>Enabled</Status><Filter/>'
            . $ruleBody
            . '</Rule></LifecycleConfiguration>',
        );
    }

    public function test_lifecycle_status_rejects_nested_elements(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><ID>nested</ID><Status>Enabled<Ignored/></Status>'
            . '<Filter/><Expiration><Days>7</Days></Expiration></Rule></LifecycleConfiguration>',
        );
    }

    public function test_lifecycle_rule_requires_an_action(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><ID>empty</ID><Status>Enabled</Status><Filter/></Rule></LifecycleConfiguration>',
        );
    }

    public function test_lifecycle_configuration_rejects_more_than_one_thousand_rules(): void
    {
        $rules = '';
        for ($index = 0; $index <= 1_000; ++$index) {
            $rules .= '<Rule><ID>rule-' . $index . '</ID><Status>Disabled</Status><Filter/>'
                . '<Expiration><Days>1</Days></Expiration></Rule>';
        }

        $this->expectException(MalformedXmlException::class);
        XmlRequestParser::parseLifecycleConfiguration('<LifecycleConfiguration>' . $rules . '</LifecycleConfiguration>');
    }

    /** @return iterable<string, array{string}> */
    public static function nestedActionScalarProvider(): iterable
    {
        yield 'expiration days' => ['<Expiration><Days>7<Ignored/></Days></Expiration>'];
        yield 'expiration date' => ['<Expiration><Date>2026-12-01T00:00:00Z<Ignored/></Date></Expiration>'];
        yield 'delete marker flag' => ['<Expiration><ExpiredObjectDeleteMarker>true<Ignored/></ExpiredObjectDeleteMarker></Expiration>'];
        yield 'transition storage class' => ['<Transition><Days>7</Days><StorageClass>GLACIER<Ignored/></StorageClass></Transition>'];
        yield 'noncurrent days' => ['<NoncurrentVersionExpiration><NoncurrentDays>7<Ignored/></NoncurrentDays></NoncurrentVersionExpiration>'];
        yield 'abort days' => ['<AbortIncompleteMultipartUpload><DaysAfterInitiation>7<Ignored/></DaysAfterInitiation></AbortIncompleteMultipartUpload>'];
    }
}
