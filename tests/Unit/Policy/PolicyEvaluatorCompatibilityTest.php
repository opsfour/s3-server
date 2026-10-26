<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use OpsFour\S3Server\Policy\PolicyEvaluator;
use PHPUnit\Framework\TestCase;

final class PolicyEvaluatorCompatibilityTest extends TestCase
{
    public function test_missing_or_non_array_statement_in_stored_policy_fails_closed(): void
    {
        foreach ([
            '{}',
            '[]',
            '{"Statment":[{"Effect":"Deny","Action":"s3:*","Resource":"*"}]}',
            '{"Statement":null}',
            '{"Statement":"broken"}',
        ] as $policy) {
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
                requirePrincipal: false,
            ), $policy);
        }

        self::assertSame('Neutral', PolicyEvaluator::evaluate(
            '{"Statement":[]}',
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            requirePrincipal: false,
        ));
    }

    public function test_an_owner_named_anonymous_remains_an_exact_explicit_principal(): void
    {
        $policy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'anonymous'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
        ]]);

        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'anonymous',
        ));
        self::assertSame('Neutral', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            '',
        ));
    }

    public function test_malformed_negated_selectors_fail_closed_in_stored_policies(): void
    {
        foreach ([
            ['NotPrincipal' => [], 'Action' => 's3:GetObject', 'Resource' => '*'],
            ['Principal' => '*', 'NotAction' => [], 'Resource' => '*'],
            ['Principal' => '*', 'Action' => 's3:GetObject', 'NotResource' => []],
            ['Principal' => '*', 'NotAction' => ['s3:PutObject', 7], 'Resource' => '*'],
            ['NotPrincipal' => ['AWS' => ['tenant', 7]], 'Action' => 's3:GetObject', 'Resource' => '*'],
        ] as $selectors) {
            $policy = $this->policy([['Effect' => 'Allow'] + $selectors]);

            self::assertSame(
                'Deny',
                PolicyEvaluator::evaluate($policy, 's3:GetObject', 'arn:aws:s3:::bucket/key', 'tenant'),
                json_encode($selectors, JSON_THROW_ON_ERROR),
            );
            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
        }
    }

    public function test_null_condition_or_resource_does_not_become_an_unrestricted_stored_policy_allow(): void
    {
        foreach (['Condition' => null, 'Resource' => null] as $field => $value) {
            $statement = [
                'Effect' => 'Allow',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::allowed/*',
                'Condition' => ['StringEquals' => ['s3:prefix' => 'allowed/']],
            ];
            $statement[$field] = $value;
            $policy = $this->policy([$statement]);

            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                $field === 'Condition' ? 'arn:aws:s3:::allowed/secret' : 'arn:aws:s3:::elsewhere/secret',
                'tenant',
                ['s3:prefix' => $field === 'Condition' ? 'other/' : 'allowed/'],
                requirePrincipal: false,
            ), $field);
            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy), $field);
        }
    }

    public function test_malformed_principal_does_not_become_an_unrestricted_identity_policy_allow(): void
    {
        foreach ([null, [], ['AWS' => null], ['AWS' => ['tenant', 7]]] as $invalidPrincipal) {
            $policy = $this->policy([[
                'Effect' => 'Allow',
                'Principal' => $invalidPrincipal,
                'Action' => 's3:DeleteObject',
                'Resource' => '*',
            ]]);

            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:DeleteObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
                requirePrincipal: false,
            ));
            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
        }

        $ordinaryIdentityPolicy = $this->policy([[
            'Effect' => 'Allow',
            'Action' => 's3:DeleteObject',
            'Resource' => '*',
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $ordinaryIdentityPolicy,
            's3:DeleteObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            requirePrincipal: false,
        ));

        $scopedIdentityPolicy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant'],
            'Action' => 's3:DeleteObject',
            'Resource' => '*',
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $scopedIdentityPolicy,
            's3:DeleteObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            requirePrincipal: false,
        ));
        self::assertSame('Neutral', PolicyEvaluator::evaluate(
            $scopedIdentityPolicy,
            's3:DeleteObject',
            'arn:aws:s3:::bucket/key',
            'other',
            requirePrincipal: false,
        ));

        $mixedPolicy = $this->policy([[
            'Effect' => 'Allow', 'Action' => 's3:DeleteObject', 'Resource' => '*',
        ], [
            'Effect' => 'Allow', 'Principal' => null, 'Action' => 's3:GetObject', 'Resource' => '*',
        ]]);
        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $mixedPolicy,
            's3:DeleteObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            requirePrincipal: false,
        ));
    }

    public function test_missing_resource_or_action_and_invalid_action_fail_closed_in_stored_identity_policies(): void
    {
        $validAllow = ['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*'];
        foreach ([
            ['Effect' => 'Allow', 'Action' => 's3:GetObject'],
            ['Effect' => 'Deny', 'Resource' => '*'],
            ['Effect' => 'Deny', 'Action' => null, 'Resource' => '*'],
            ['Effect' => 'Deny', 'Action' => [], 'Resource' => '*'],
            ['Effect' => 'Deny', 'Action' => ['s3:GetObject', 7], 'Resource' => '*'],
        ] as $invalidStatement) {
            $policy = $this->policy([$validAllow, $invalidStatement]);
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::other/private',
                'tenant',
                requirePrincipal: false,
            ), json_encode($invalidStatement, JSON_THROW_ON_ERROR));
            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
        }
    }

    public function test_malformed_statement_or_effect_cannot_be_ignored_next_to_a_valid_allow(): void
    {
        $validAllow = ['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*'];
        foreach (['broken', null, [], ['Effect' => null, 'Action' => 's3:GetObject', 'Resource' => '*']] as $invalidStatement) {
            $policy = json_encode(['Statement' => [$validAllow, $invalidStatement]], JSON_THROW_ON_ERROR);
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
                requirePrincipal: false,
            ));
            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
        }
    }

    public function test_unknown_statement_field_cannot_silently_remove_an_identity_policy_condition(): void
    {
        $statement = [
            'Effect' => 'Allow',
            'Action' => 's3:GetObject',
            'Resource' => '*',
            'Condition' => ['Bool' => ['aws:SecureTransport' => true]],
        ];
        $validPolicy = $this->policy([$statement]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $validPolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:SecureTransport' => 'true'],
            requirePrincipal: false,
        ));
        self::assertSame('Neutral', PolicyEvaluator::evaluate(
            $validPolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:SecureTransport' => 'false'],
            requirePrincipal: false,
        ));

        $statement['Conditon'] = $statement['Condition'];
        unset($statement['Condition']);
        $misspelledPolicy = $this->policy([$statement]);
        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $misspelledPolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:SecureTransport' => 'false'],
            requirePrincipal: false,
        ));
        self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($misspelledPolicy));
    }

    public function test_account_root_principal_matches_only_identities_in_its_own_partition_and_account(): void
    {
        $policy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'arn:aws:iam::123456789012:root'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
        ]]);

        foreach ([
            'arn:aws:iam::123456789012:root',
            'arn:aws:iam::123456789012:user/alice',
            'arn:aws:iam::123456789012:role/reader',
            'arn:aws:sts::123456789012:assumed-role/reader/session',
        ] as $principal) {
            self::assertSame('Allow', PolicyEvaluator::evaluate($policy, 's3:GetObject', 'arn:aws:s3:::bucket/key', $principal));
        }

        $barePrincipal = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => 'arn:aws:iam::123456789012:root',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate($barePrincipal, 's3:GetObject', 'arn:aws:s3:::bucket/key', 'arn:aws:iam::123456789012:user/alice'));
        self::assertSame('Neutral', PolicyEvaluator::evaluate($barePrincipal, 's3:GetObject', 'arn:aws:s3:::bucket/key', 'arn:aws:iam::210987654321:user/alice'));

        foreach ([
            'arn:aws:iam::210987654321:user/alice',
            'arn:aws:sts::210987654321:assumed-role/reader/session',
            'arn:aws-cn:iam::123456789012:user/alice',
            'arn:aws:s3::123456789012:bucket',
            'arn:anything',
        ] as $principal) {
            self::assertSame('Neutral', PolicyEvaluator::evaluate($policy, 's3:GetObject', 'arn:aws:s3:::bucket/key', $principal));
        }

        $negated = $this->policy([[
            'Effect' => 'Deny',
            'NotPrincipal' => ['AWS' => 'arn:aws:iam::123456789012:root'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
        ]]);
        self::assertSame('Neutral', PolicyEvaluator::evaluate($negated, 's3:GetObject', 'arn:aws:s3:::bucket/key', 'arn:aws:iam::123456789012:user/alice'));
        self::assertSame('Deny', PolicyEvaluator::evaluate($negated, 's3:GetObject', 'arn:aws:s3:::bucket/key', 'arn:aws:iam::210987654321:user/alice'));
    }

    public function test_boolean_conditions_use_boolean_values_and_reject_legacy_invalid_null_values(): void
    {
        $secureTransport = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
            'Condition' => ['Bool' => ['aws:SecureTransport' => true]],
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $secureTransport,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:SecureTransport' => 'true'],
        ));

        $invalidNull = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
            'Condition' => ['Null' => ['aws:PrincipalArn' => 'maybe']],
        ]]);
        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $invalidNull,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:PrincipalArn' => 'tenant'],
        ));
        self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($invalidNull));
    }

    public function test_missing_context_keys_match_negated_and_for_all_conditions(): void
    {
        foreach (['StringNotEquals', 'StringNotLike', 'NotIpAddress', 'ForAllValues:StringEquals'] as $operator) {
            $key = $operator === 'NotIpAddress' ? 'aws:SourceIp' : 's3:x-amz-acl';
            $expected = $operator === 'NotIpAddress' ? '192.0.2.0/24' : 'public-read';
            $policy = $this->policy([[
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
                'Condition' => [$operator => [$key => $expected]],
            ]]);
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:PutObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
            ), $operator);
        }

        foreach (['StringEquals', 'ForAnyValue:StringNotEquals'] as $operator) {
            $policy = $this->policy([[
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
                'Condition' => [$operator => ['s3:x-amz-acl' => 'public-read']],
            ]]);
            self::assertSame('Neutral', PolicyEvaluator::evaluate(
                $policy,
                's3:PutObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
            ), $operator);
        }
    }

    public function test_legacy_empty_condition_blocks_fail_closed(): void
    {
        foreach ([[], ['StringEquals' => []]] as $condition) {
            $policy = $this->policy([[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
                'Condition' => $condition,
            ]]);
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
            ));
            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
        }
    }

    public function test_malformed_string_condition_values_cannot_disable_a_stored_identity_deny(): void
    {
        foreach ([[], null, ['unexpected' => 'tenant'], ['tenant', []]] as $value) {
            $policy = $this->policy([
                [
                    'Effect' => 'Deny',
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                    'Condition' => ['StringEquals' => ['aws:PrincipalArn' => $value]],
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ],
            ]);

            self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:DeleteObject',
                'arn:aws:s3:::bucket/key',
                'tenant',
                ['aws:PrincipalArn' => 'tenant'],
                requirePrincipal: false,
            ));
        }

        $validPolicy = $this->policy([
            [
                'Effect' => 'Deny',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
                'Condition' => ['StringEquals' => ['aws:PrincipalArn' => ['tenant', 'other']]],
            ],
            [
                'Effect' => 'Allow',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
            ],
        ]);
        self::assertFalse(PolicyEvaluator::hasUnsupportedConditionsInDocument($validPolicy));
        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $validPolicy,
            's3:DeleteObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:PrincipalArn' => 'tenant'],
            requirePrincipal: false,
        ));
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $validPolicy,
            's3:DeleteObject',
            'arn:aws:s3:::bucket/key',
            'other-tenant',
            ['aws:PrincipalArn' => 'other-tenant'],
            requirePrincipal: false,
        ));
    }

    public function test_legacy_invalid_ip_condition_fails_closed(): void
    {
        $policy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
            'Condition' => ['NotIpAddress' => ['aws:SourceIp' => '192.0.2.0/99']],
        ]]);

        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:SourceIp' => '198.51.100.2'],
        ));
        self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
    }

    public function test_legacy_invalid_numeric_condition_fails_closed(): void
    {
        $policy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:ListBucket',
            'Resource' => 'arn:aws:s3:::bucket',
            'Condition' => ['NumericLessThan' => ['s3:max-keys' => 'many']],
        ]]);

        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $policy,
            's3:ListBucket',
            'arn:aws:s3:::bucket',
            'tenant',
            ['s3:max-keys' => '10'],
        ));
        self::assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
    }

    public function test_date_conditions_accept_unix_epoch_and_reject_invalid_legacy_values(): void
    {
        $epochPolicy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
            'Condition' => ['DateGreaterThan' => ['aws:CurrentTime' => 1_700_000_000]],
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $epochPolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:CurrentTime' => '2024-01-01T00:00:00Z'],
        ));
        self::assertFalse(PolicyEvaluator::hasUnsupportedConditionsInDocument($epochPolicy));

        $dateOnlyPolicy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
            'Condition' => ['DateGreaterThan' => ['aws:CurrentTime' => '2023-11-01']],
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $dateOnlyPolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:CurrentTime' => '2024-01-01T00:00:00Z'],
        ));

        $invalidPolicy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/key',
            'Condition' => ['DateGreaterThan' => ['aws:CurrentTime' => 'never-ever']],
        ]]);
        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $invalidPolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            'tenant',
            ['aws:CurrentTime' => '2024-01-01T00:00:00Z'],
        ));
        self::assertFalse(PolicyEvaluator::supportsConditionValue('DateGreaterThan', 'tomorrow'));
    }

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

    public function test_resource_and_stringlike_treat_square_brackets_as_literal_characters(): void
    {
        $resourcePolicy = $this->policy([[
            'Effect' => 'Deny',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/report[1].txt',
        ]]);

        self::assertSame('Deny', PolicyEvaluator::evaluate(
            $resourcePolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/report[1].txt',
            'owner',
        ));
        self::assertSame('Neutral', PolicyEvaluator::evaluate(
            $resourcePolicy,
            's3:GetObject',
            'arn:aws:s3:::bucket/report1.txt',
            'owner',
        ));

        $conditionPolicy = $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:ListBucket',
            'Resource' => 'arn:aws:s3:::bucket',
            'Condition' => ['StringLike' => ['s3:prefix' => 'set[1]/*']],
        ]]);
        self::assertSame('Allow', PolicyEvaluator::evaluate(
            $conditionPolicy,
            's3:ListBucket',
            'arn:aws:s3:::bucket',
            'owner',
            ['s3:prefix' => 'set[1]/'],
        ));
    }

    public function test_wildcard_matching_handles_many_stars_and_literal_special_characters(): void
    {
        $policy = $this->policy([[
            'Effect' => 'Deny',
            'Principal' => '*',
            'Action' => 's3:Get*',
            'Resource' => 'arn:aws:s3:::bucket/' . str_repeat('a*', 30) . 'report[1]?.txt',
        ]]);

        $oldBacktrackLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            self::assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::bucket/' . str_repeat('a', 30) . 'report[1]x.txt',
                'tenant',
            ));
            self::assertSame('Neutral', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::bucket/' . str_repeat('a', 30) . 'report1x.txt',
                'tenant',
            ));
        } finally {
            if ($oldBacktrackLimit !== false) {
                ini_set('pcre.backtrack_limit', $oldBacktrackLimit);
            }
        }
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

    public function test_invalid_cidr_conditions_fail_closed_without_throwing(): void
    {
        foreach ([
            ['IpAddress', '192.0.2.0/33'],
            ['NotIpAddress', 'not-a-cidr'],
            ['NotIpAddress', '2001:db8::/129'],
        ] as [$operator, $range]) {
            $policy = $this->policy([[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => [
                    $operator => ['aws:SourceIp' => $range],
                ],
            ]]);

            $this->assertSame('Deny', PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                'arn:aws:s3:::bucket/key.txt',
                'anonymous',
                ['aws:SourceIp' => '192.0.2.1'],
            ));
            $this->assertTrue(PolicyEvaluator::hasUnsupportedConditionsInDocument($policy));
        }
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

    public function test_public_policy_detection_respects_narrow_source_ip_conditions(): void
    {
        $statement = [
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/*',
            'Condition' => ['IpAddress' => ['aws:SourceIp' => ['192.0.2.0/24', '2001:db8::/48']]],
        ];

        self::assertFalse(PolicyEvaluator::isPublicPolicy($this->policy([$statement])));

        $statement['Condition']['IpAddress']['aws:SourceIp'] = '0.0.0.0/1';
        self::assertTrue(PolicyEvaluator::isPublicPolicy($this->policy([$statement])));

        $statement['Condition']['IpAddress']['aws:SourceIp'] = ['192.0.2.0/24', '0.0.0.0/1'];
        self::assertTrue(PolicyEvaluator::isPublicPolicy($this->policy([$statement])));

        $statement['Condition']['IpAddress']['aws:SourceIp'] = 'not-an-ip';
        self::assertTrue(PolicyEvaluator::isPublicPolicy($this->policy([$statement])));
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
