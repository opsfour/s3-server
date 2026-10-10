<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use OpsFour\S3Server\Exception\MalformedPolicyException;
use OpsFour\S3Server\Policy\PolicyDocumentValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyDocumentValidatorTest extends TestCase
{
    public function test_accepts_supported_bucket_policy_structure(): void
    {
        PolicyDocumentValidator::validateBucketPolicy(json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Sid' => 'ReadPublic',
                'Effect' => 'Allow',
                'Principal' => ['AWS' => ['tenant:reader', 'tenant:auditor']],
                'Action' => ['s3:GetObject', 's3:GetObjectTagging'],
                'Resource' => 'arn:aws:s3:::bucket/public/*',
                'Condition' => [
                    'IpAddress' => ['aws:SourceIp' => ['192.0.2.0/24', '2001:db8::/32']],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->addToAssertionCount(1);
    }

    public function test_accepts_boolean_condition_values(): void
    {
        PolicyDocumentValidator::validateBucketPolicy(json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => [
                    'Bool' => ['aws:SecureTransport' => true],
                    'Null' => ['aws:PrincipalArn' => 'false'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->addToAssertionCount(1);
    }

    public function test_identity_policy_accepts_empty_statements_and_supported_selectors(): void
    {
        foreach ([
            ['Statement' => []],
            ['Statement' => [
                'Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*',
            ]],
            ['Statement' => [[
                'Effect' => 'Deny', 'NotPrincipal' => 'tenant:excepted',
                'NotAction' => 's3:ListBucket', 'NotResource' => 'arn:aws:s3:::safe',
                'Condition' => ['StringEquals' => ['aws:UserAgent' => ['client-a', 'client-b']]],
            ]]],
        ] as $policy) {
            PolicyDocumentValidator::validateIdentityPolicy(json_encode($policy, JSON_THROW_ON_ERROR));
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentityPolicies(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'missing Statement' => ['{}'];
        yield 'unknown statement field' => ['{"Statement":[{"Effect":"Allow","Action":"s3:*","Resource":"*","Condtion":{}}]}'];
        yield 'missing Action' => ['{"Statement":[{"Effect":"Deny","Resource":"*"}]}'];
        yield 'both principals' => ['{"Statement":[{"Effect":"Allow","Principal":"*","NotPrincipal":"x","Action":"s3:*","Resource":"*"}]}'];
        yield 'malformed condition' => ['{"Statement":[{"Effect":"Deny","Action":"s3:*","Resource":"*","Condition":{"StringEquals":{"aws:UserAgent":[]}}}]}'];
        yield 'unsupported condition' => ['{"Statement":[{"Effect":"Deny","Action":"s3:*","Resource":"*","Condition":{"ArnEquals":{"aws:PrincipalArn":"x"}}}]}'];
    }

    #[DataProvider('invalidIdentityPolicies')]
    public function test_identity_policy_rejects_invalid_document(string $policy): void
    {
        $this->expectException(MalformedPolicyException::class);
        PolicyDocumentValidator::validateIdentityPolicy($policy);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPolicies(): iterable
    {
        yield 'missing statement' => [[]];
        yield 'empty statements' => [['Statement' => []]];
        yield 'non-object statement' => [['Statement' => ['invalid']]];
        yield 'invalid effect' => [['Statement' => [[
            'Effect' => 'Maybe',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => '*',
        ]]]];
        yield 'conflicting action fields' => [['Statement' => [[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'NotAction' => 's3:DeleteObject',
            'Resource' => '*',
        ]]]];
        yield 'unsupported principal shape' => [['Statement' => [[
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'example.test'],
            'Action' => 's3:GetObject',
            'Resource' => '*',
        ]]]];
        yield 'empty condition' => [['Statement' => [[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => '*',
            'Condition' => [],
        ]]]];
        foreach ([
            'invalid null value' => ['Null' => ['aws:PrincipalArn' => 'maybe']],
            'numeric null value' => ['Null' => ['aws:PrincipalArn' => 1]],
            'array null value' => ['Null' => ['aws:PrincipalArn' => ['true']]],
            'invalid bool value' => ['Bool' => ['aws:SecureTransport' => 'maybe']],
            'invalid source IP' => ['IpAddress' => ['aws:SourceIp' => 'not-an-ip']],
            'invalid negated CIDR' => ['NotIpAddress' => ['aws:SourceIp' => '192.0.2.0/99']],
            'invalid numeric threshold' => ['NumericLessThan' => ['s3:max-keys' => 'many']],
            'infinite numeric threshold' => ['NumericGreaterThan' => ['s3:max-keys' => '1e309']],
            'invalid date threshold' => ['DateLessThan' => ['aws:CurrentTime' => 'never-ever']],
            'relative date threshold' => ['DateLessThan' => ['aws:CurrentTime' => 'tomorrow']],
        ] as $name => $condition) {
            yield $name => [['Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => $condition,
            ]]]];
        }
    }

    /**
     * @param array<string, mixed> $policy
     */
    #[DataProvider('invalidPolicies')]
    public function test_rejects_malformed_policy_documents(array $policy): void
    {
        $this->expectException(MalformedPolicyException::class);

        PolicyDocumentValidator::validateBucketPolicy(json_encode($policy, JSON_THROW_ON_ERROR));
    }
}
