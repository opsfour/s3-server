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

        $statements = $this->policyStatements($template);
        self::assertSame([
            'effect' => 'Allow',
            'actions' => ['s3:GetBucketVersioning', 's3:ListBucket'],
            'resources' => ['!GetAtt ProfileBucket.Arn'],
            'principals' => [],
            'conditions' => [],
        ], $statements['ReadBucketAdmissionState']);
        self::assertSame([
            'effect' => 'Allow',
            'actions' => ['s3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:AbortMultipartUpload'],
            'resources' => [
                "!Sub '\${ProfileBucket.Arn}/\${DataPrefix}/*'",
                "!Sub '\${ProfileBucket.Arn}/\${ReservedPrefix}/*'",
            ],
            'principals' => [],
            'conditions' => [],
        ], $statements['MutateOnlyIsolatedPrefixes']);
        self::assertSame([
            'effect' => 'Deny',
            'actions' => ['s3:*'],
            'resources' => ['!GetAtt ProfileBucket.Arn', "!Sub '\${ProfileBucket.Arn}/*'"],
            'principals' => ['*'],
            'conditions' => ['Bool.aws:SecureTransport' => 'false'],
        ], $statements['DenyInsecureTransport']);
        self::assertSame([
            'effect' => 'Deny',
            'actions' => ['s3:PutBucketVersioning'],
            'resources' => ['!GetAtt ProfileBucket.Arn'],
            'principals' => ['*'],
            'conditions' => [],
        ], $statements['KeepVersioningDisabled']);
        self::assertSame([
            'effect' => 'Deny',
            'actions' => ['s3:PutLifecycleConfiguration'],
            'resources' => ['!GetAtt ProfileBucket.Arn'],
            'principals' => ['*'],
            'conditions' => [],
        ], $statements['KeepLifecycleConfigurationAbsent']);
        self::assertMatchesRegularExpression(
            '/AssumeRolePolicyDocument:.*?Effect: Allow\n\s+Principal:\n\s+AWS: !Ref RuntimePrincipalArn\n\s+Action: sts:AssumeRole/s',
            $template,
        );
        self::assertMatchesRegularExpression('/ProfileBucketPolicy:\n    Type: AWS::S3::BucketPolicy\n    DeletionPolicy: Retain\n    UpdateReplacePolicy: Retain/', $template);

        $bucket = $this->resourceBlock($template, 'ProfileBucket', 'RuntimeRole');
        self::assertSame(1, preg_match(
            '/BucketEncryption:\n\s+ServerSideEncryptionConfiguration:\n\s+- ServerSideEncryptionByDefault:\n\s+SSEAlgorithm: AES256$/m',
            $bucket,
        ));
        self::assertSame(1, preg_match(
            '/OwnershipControls:\n\s+Rules:\n\s+- ObjectOwnership: BucketOwnerEnforced$/m',
            $bucket,
        ));
        preg_match_all(
            '/^\s+(BlockPublicAcls|BlockPublicPolicy|IgnorePublicAcls|RestrictPublicBuckets): (true|false)$/m',
            $bucket,
            $publicAccess,
            PREG_SET_ORDER,
        );
        self::assertSame([
            'BlockPublicAcls' => 'true',
            'BlockPublicPolicy' => 'true',
            'IgnorePublicAcls' => 'true',
            'RestrictPublicBuckets' => 'true',
        ], array_column($publicAccess, 2, 1));
        self::assertStringNotContainsString('s3:GetBucketLocation', $template);
    }

    public function test_prefix_domains_are_runtime_compatible_disjoint_and_exclude_iam_wildcards(): void
    {
        $template = file_get_contents(dirname(__DIR__, 3) . '/deploy/aws-s3-profile.cloudformation.yaml');
        self::assertIsString($template);
        $data = $this->parameterPattern($template, 'DataPrefix');
        $reserved = $this->parameterPattern($template, 'ReservedPrefix');

        foreach (['data', 'data/archive-1', 'data/a.b_c'] as $candidate) {
            self::assertSame(1, preg_match('~' . $data . '~D', $candidate));
        }
        foreach (['.opsfour', '.opsfour/attempts', '.opsfour/seals-1'] as $candidate) {
            self::assertSame(1, preg_match('~' . $reserved . '~D', $candidate));
        }
        foreach (['data*', 'data?', 'data//x', 'data/../x', 'other'] as $candidate) {
            self::assertSame(0, preg_match('~' . $data . '~D', $candidate));
        }
        foreach (['.opsfour*', '.opsfour?', '.opsfour//x', '.opsfour/../x', 'data'] as $candidate) {
            self::assertSame(0, preg_match('~' . $reserved . '~D', $candidate));
        }
    }

    /**
     * @return array<string, array{
     *   effect: string,
     *   actions: list<string>,
     *   resources: list<string>,
     *   principals: list<string>,
     *   conditions: array<string, string>
     * }>
     */
    private function policyStatements(string $template): array
    {
        preg_match_all(
            '/^\s+- Sid: ([A-Za-z0-9]+)\n(.*?)(?=^\s+- Sid: |^Outputs:)/ms',
            $template,
            $matches,
            PREG_SET_ORDER,
        );
        $statements = [];
        foreach ($matches as $match) {
            preg_match('/^\s+Effect: (Allow|Deny)$/m', $match[2], $effect);
            preg_match_all('/^\s+- (s3:[A-Za-z*]+)$/m', $match[2], $actions);
            if (preg_match('/^\s+Action: (s3:[A-Za-z*]+)$/m', $match[2], $scalarAction) === 1) {
                $actions[1][] = $scalarAction[1];
            }
            $resources = [];
            if (preg_match('/^\s+Resource: (.+)$/m', $match[2], $scalarResource) === 1) {
                $resources[] = trim($scalarResource[1]);
            } elseif (preg_match('/^\s+Resource:\n((?:\s+- .+(?:\n|$))+)/m', $match[2], $resourceBlock) === 1) {
                preg_match_all('/^\s+- (.+)$/m', $resourceBlock[1], $resourceMatches);
                $resources = array_map('trim', $resourceMatches[1]);
            }
            $principals = [];
            if (preg_match('/^\s+Principal: (.+)$/m', $match[2], $scalarPrincipal) === 1) {
                $principals[] = trim($scalarPrincipal[1], " \t\n\r\0\x0B'\"");
            } elseif (preg_match('/^\s+Principal:\n\s+AWS: (.+)$/m', $match[2], $awsPrincipal) === 1) {
                $principals[] = trim($awsPrincipal[1]);
            }
            $conditions = [];
            if (preg_match(
                '/^\s+Condition:\n\s+Bool:\n\s+aws:SecureTransport: (true|false)$/m',
                $match[2],
                $secureTransport,
            ) === 1) {
                $conditions['Bool.aws:SecureTransport'] = $secureTransport[1];
            }
            $statements[$match[1]] = [
                'effect' => $effect[1] ?? '',
                'actions' => $actions[1],
                'resources' => $resources,
                'principals' => $principals,
                'conditions' => $conditions,
            ];
        }

        return $statements;
    }

    private function parameterPattern(string $template, string $parameter): string
    {
        self::assertSame(1, preg_match(
            '/^  ' . preg_quote($parameter, '/') . ":\\n(?:    .*\\n)*?    AllowedPattern: '([^']+)'$/m",
            $template,
            $match,
        ));

        return $match[1] ?? throw new \RuntimeException('CloudFormation parameter pattern is missing.');
    }

    private function resourceBlock(string $template, string $resource, string $nextResource): string
    {
        self::assertSame(1, preg_match(
            '/^  ' . preg_quote($resource, '/') . ":\\n(.*?)(?=^  " . preg_quote($nextResource, '/') . ':$)/ms',
            $template,
            $match,
        ));

        return $match[1] ?? throw new \RuntimeException('CloudFormation resource block is missing.');
    }
}
