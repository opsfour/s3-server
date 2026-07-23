<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use OpsFour\S3Server\Policy\PolicyEvaluator;
use PHPUnit\Framework\TestCase;

final class PolicyEvaluatorCompatibilityTest extends TestCase
{
    public function test_principal_action_resource_and_explicit_deny_precedence(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'tenant:acme'],
                'Action' => 's3:Get*',
                'Resource' => 'arn:aws:s3:::bucket/private/*',
            ],
            [
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/private/blocked.txt',
            ],
        ]);

        $this->assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/private/allowed.txt',
            'tenant:acme',
        ));
        $this->assertSame('Deny', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/private/blocked.txt',
            'tenant:acme',
        ));
    }

    public function test_source_ip_secure_transport_user_agent_prefix_and_object_tag_conditions(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:ListBucket',
                'Resource' => 'arn:aws:s3:::bucket',
                'Condition' => [
                    'IpAddress' => ['aws:SourceIp' => '192.0.2.0/24'],
                    'Bool' => ['aws:SecureTransport' => 'true'],
                    'StringLike' => ['aws:UserAgent' => 'aws-sdk-*', 's3:prefix' => 'public/*'],
                ],
            ],
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
                'Condition' => [
                    'StringEquals' => ['s3:ExistingObjectTag/classification' => 'public'],
                ],
            ],
        ]);

        $this->assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:ListBucket',
            'arn:aws:s3:::bucket',
            'anonymous',
            [
                'aws:SourceIp' => '192.0.2.15',
                'aws:SecureTransport' => 'true',
                'aws:UserAgent' => 'aws-sdk-php/3',
                's3:prefix' => 'public/images/',
            ],
        ));
        $this->assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/public/readme.txt',
            'anonymous',
            ['s3:ExistingObjectTag/classification' => 'public'],
        ));
    }

    public function test_string_not_equals_and_not_ip_address_conditions(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => [
                    'StringNotEquals' => ['aws:PrincipalArn' => 'tenant:trusted'],
                    'NotIpAddress' => ['aws:SourceIp' => '10.0.0.0/8'],
                ],
            ],
        ]);

        $this->assertSame('Deny', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key.txt',
            'tenant:other',
            ['aws:PrincipalArn' => 'tenant:other', 'aws:SourceIp' => '192.0.2.1'],
        ));
        $this->assertSame('Neutral', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key.txt',
            'tenant:trusted',
            ['aws:PrincipalArn' => 'tenant:trusted', 'aws:SourceIp' => '192.0.2.1'],
        ));
    }

    public function test_unsupported_condition_operator_fails_closed(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => [
                    'ArnLike' => ['aws:SourceArn' => 'arn:aws:s3:::bucket/*'],
                ],
            ],
        ]);

        $this->assertSame('Deny', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key.txt',
            'anonymous',
        ));
    }

    public function test_unsupported_condition_key_fails_closed(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => [
                    'StringEquals' => ['aws:PrincipalOrgID' => 'o-123'],
                ],
            ],
        ]);

        $this->assertSame('Deny', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key.txt',
            'anonymous',
        ));
    }

    public function test_identity_policy_mode_allows_statements_without_principal(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Allow',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]);

        $this->assertSame('Neutral', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/public/readme.txt',
            'tenant:reader',
        ));

        $this->assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/public/readme.txt',
            'tenant:reader',
            requirePrincipal: false,
        ));
    }

    public function test_public_policy_detection_includes_not_principal_anonymous_access(): void
    {
        $public = $this->policy([[
            'Effect' => 'Allow',
            'NotPrincipal' => ['AWS' => 'tenant:owner'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/*',
        ]]);
        $matchesNobody = $this->policy([[
            'Effect' => 'Allow',
            'NotPrincipal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/*',
        ]]);

        self::assertTrue(PolicyEvaluator::isPublicPolicy($public));
        self::assertFalse(PolicyEvaluator::isPublicPolicy($matchesNobody));
    }

    public function test_request_tags_headers_null_numeric_and_date_conditions(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/uploads/*',
                'Condition' => [
                    'StringEquals' => [
                        's3:RequestObjectTag/project' => 'alpha',
                        's3:x-amz-acl' => 'private',
                        's3:x-amz-server-side-encryption' => 'AES256',
                    ],
                    'Null' => ['s3:x-amz-storage-class' => 'false'],
                    'NumericLessThanEquals' => ['s3:max-keys' => 100],
                    'DateGreaterThan' => ['aws:CurrentTime' => '2020-01-01T00:00:00Z'],
                ],
            ],
        ]);

        $this->assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:PutObject',
            'arn:aws:s3:::bucket/uploads/file.txt',
            'tenant:acme',
            [
                's3:RequestObjectTag/project' => 'alpha',
                's3:x-amz-acl' => 'private',
                's3:x-amz-server-side-encryption' => 'AES256',
                's3:x-amz-storage-class' => 'STANDARD',
                's3:max-keys' => '50',
                'aws:CurrentTime' => '2026-06-14T12:00:00Z',
            ],
        ));
    }

    public function test_for_any_value_and_for_all_values_conditions(): void
    {
        $policy = $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => [
                    'ForAnyValue:StringEquals' => ['s3:ExistingObjectTag/group' => ['alpha', 'beta']],
                    'ForAllValues:StringLike' => ['s3:RequestObjectTag/team' => 'platform-*'],
                ],
            ],
        ]);

        $this->assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key.txt',
            'tenant:acme',
            [
                's3:ExistingObjectTag/group' => ['gamma', 'beta'],
                's3:RequestObjectTag/team' => ['platform-api', 'platform-storage'],
            ],
        ));

        $this->assertSame('Neutral', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key.txt',
            'tenant:acme',
            [
                's3:ExistingObjectTag/group' => ['gamma', 'beta'],
                's3:RequestObjectTag/team' => ['platform-api', 'security'],
            ],
        ));
    }

    /**
     * @param list<array<string, mixed>> $statements
     */
    private function policy(array $statements): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ], JSON_THROW_ON_ERROR);
    }
}
