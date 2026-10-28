<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

final class AwsS3ProfileTemplateTest extends TestCase
{
    public function test_template_is_isolated_tls_only_prefix_scoped_and_contains_no_credentials(): void
    {
        $template = file_get_contents(dirname(__DIR__, 3) . '/deploy/aws-s3-profile.cloudformation.yaml');
        self::assertIsString($template);

        self::assertSame(1, preg_match_all('/^    Type: AWS::S3::Bucket$/m', $template));
        self::assertSame(1, preg_match_all('/^    Type: AWS::S3::BucketPolicy$/m', $template));
        self::assertSame(1, preg_match_all('/^    Type: AWS::IAM::Role$/m', $template));
        self::assertStringContainsString('aws:SecureTransport: false', $template);
        self::assertStringContainsString('s3:PutBucketVersioning', $template);
        self::assertStringContainsString('s3:AbortMultipartUpload', $template);
        self::assertStringContainsString('KeepLifecycleConfigurationAbsent', $template);
        self::assertStringContainsString('s3:PutLifecycleConfiguration', $template);
        self::assertStringContainsString('ExpectedOwnerAccountId:', $template);
        self::assertStringContainsString('Value: !Ref AWS::AccountId', $template);
        self::assertStringContainsString('${DataPrefix}/*', $template);
        self::assertStringContainsString('${ReservedPrefix}/*', $template);
        self::assertStringContainsString('DeletionPolicy: Retain', $template);
        self::assertStringNotContainsString('AccessKey', $template);
        self::assertStringNotContainsString('Secret', $template);
        self::assertStringNotContainsString('AWS::S3::AccessPoint', $template);
        self::assertStringNotContainsString('LifecycleConfiguration:', $template);
    }
}
