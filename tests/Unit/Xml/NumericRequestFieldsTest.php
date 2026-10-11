<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NumericRequestFieldsTest extends TestCase
{
    public function test_malformed_complete_part_number_is_rejected(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseCompleteMultipartUpload(
            '<CompleteMultipartUpload><Part><PartNumber>1junk</PartNumber><ETag>"etag"</ETag></Part></CompleteMultipartUpload>',
        );
    }

    public function test_malformed_restore_days_is_rejected(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseRestoreRequest('<RestoreRequest><Days>1junk</Days></RestoreRequest>');
    }

    public function test_complete_part_number_above_s3_limit_is_rejected(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseCompleteMultipartUpload(
            '<CompleteMultipartUpload><Part><PartNumber>10001</PartNumber><ETag>"etag"</ETag></Part></CompleteMultipartUpload>',
        );
    }

    #[DataProvider('overflowingNumbers')]
    public function test_xml_numeric_fields_reject_values_outside_native_integer_range(string $xml, string $parser): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::$parser($xml);
    }

    /** @return array<string, array{string, string}> */
    public static function overflowingNumbers(): array
    {
        $tooLarge = '999999999999999999999999999999999999';

        return [
            'complete part number' => [
                '<CompleteMultipartUpload><Part><PartNumber>' . $tooLarge . '</PartNumber><ETag>"etag"</ETag></Part></CompleteMultipartUpload>',
                'parseCompleteMultipartUpload',
            ],
            'restore days' => ['<RestoreRequest><Days>' . $tooLarge . '</Days></RestoreRequest>', 'parseRestoreRequest'],
            'lifecycle expiration days' => [
                '<LifecycleConfiguration><Rule><Status>Enabled</Status><Filter/><Expiration><Days>' . $tooLarge . '</Days></Expiration></Rule></LifecycleConfiguration>',
                'parseLifecycleConfiguration',
            ],
        ];
    }

    public function test_leading_zero_decimal_values_keep_their_numeric_meaning(): void
    {
        self::assertSame(1, XmlRequestParser::parseRestoreRequest('<RestoreRequest><Days>0001</Days></RestoreRequest>')['days']);
    }

    public function test_restore_days_above_portable_expiry_range_are_rejected(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseRestoreRequest(
            '<RestoreRequest><Days>' . (XmlRequestParser::MAX_RESTORE_DAYS + 1) . '</Days></RestoreRequest>',
        );
    }

    public function test_lifecycle_days_above_safe_cutoff_range_are_rejected(): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><Status>Enabled</Status><Filter/><Expiration><Days>'
            . (XmlRequestParser::MAX_LIFECYCLE_DAYS + 1)
            . '</Days></Expiration></Rule></LifecycleConfiguration>',
        );
    }

    public function test_current_version_transition_accepts_zero_days(): void
    {
        $rules = XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><Status>Enabled</Status><Filter/><Transition>'
            . '<Days>0</Days><StorageClass>GLACIER</StorageClass>'
            . '</Transition></Rule></LifecycleConfiguration>',
        );

        self::assertSame(0, $rules[0]['transitions'][0]['days'] ?? null);
    }

    public function test_current_version_transition_accepts_leading_zero_decimal_notation(): void
    {
        $rules = XmlRequestParser::parseLifecycleConfiguration(
            '<LifecycleConfiguration><Rule><Status>Enabled</Status><Filter/><Transition>'
            . '<Days>000</Days><StorageClass>GLACIER</StorageClass>'
            . '</Transition></Rule></LifecycleConfiguration>',
        );

        self::assertSame(0, $rules[0]['transitions'][0]['days'] ?? null);
    }

    #[DataProvider('oversizedDefaultRetentionPeriods')]
    public function test_default_retention_periods_above_safe_ranges_are_rejected(string $period): void
    {
        $this->expectException(MalformedXmlException::class);

        XmlRequestParser::parseObjectLockConfiguration(
            '<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled><Rule><DefaultRetention>'
            . '<Mode>COMPLIANCE</Mode>' . $period
            . '</DefaultRetention></Rule></ObjectLockConfiguration>',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function oversizedDefaultRetentionPeriods(): iterable
    {
        yield 'days' => ['<Days>' . (XmlRequestParser::MAX_DEFAULT_RETENTION_DAYS + 1) . '</Days>'];
        yield 'years' => ['<Years>' . (XmlRequestParser::MAX_DEFAULT_RETENTION_YEARS + 1) . '</Years>'];
    }
}
