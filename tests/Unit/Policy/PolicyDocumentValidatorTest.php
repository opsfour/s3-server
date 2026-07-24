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
