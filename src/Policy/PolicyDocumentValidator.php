<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

use OpsFour\S3Server\Exception\MalformedPolicyException;

/**
 * Validates the IAM policy structure accepted by the local policy evaluator.
 */
final class PolicyDocumentValidator
{
    private const array TOP_LEVEL_FIELDS = ['Id', 'Statement', 'Version'];

    private const array STATEMENT_FIELDS = [
        'Action',
        'Condition',
        'Effect',
        'NotAction',
        'NotPrincipal',
        'NotResource',
        'Principal',
        'Resource',
        'Sid',
    ];

    public static function validateBucketPolicy(string $policyJson): void
    {
        try {
            $policy = json_decode($policyJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedPolicyException('The policy is not valid JSON.');
        }

        if (!is_array($policy) || array_is_list($policy)) {
            throw new MalformedPolicyException('Policy must be a JSON object.');
        }

        self::rejectUnknownFields($policy, self::TOP_LEVEL_FIELDS, 'policy');
        self::optionalString($policy, 'Id', 'Policy Id');
        self::optionalString($policy, 'Version', 'Policy Version');

        if (!array_key_exists('Statement', $policy) || !is_array($policy['Statement'])) {
            throw new MalformedPolicyException('Policy must contain a Statement object or list.');
        }

        $statements = array_is_list($policy['Statement'])
            ? $policy['Statement']
            : [$policy['Statement']];
        if ($statements === []) {
            throw new MalformedPolicyException('Policy Statement list must not be empty.');
        }

        foreach ($statements as $index => $statement) {
            if (!is_array($statement) || array_is_list($statement)) {
                throw new MalformedPolicyException("Policy Statement {$index} must be a JSON object.");
            }

            self::validateStatement($statement, $index);
        }
    }

    /**
     * @param array<string, mixed> $statement
     */
    private static function validateStatement(array $statement, int $index): void
    {
        self::rejectUnknownFields($statement, self::STATEMENT_FIELDS, "Statement {$index}");
        self::optionalString($statement, 'Sid', "Statement {$index} Sid");

        if (($statement['Effect'] ?? null) !== 'Allow' && ($statement['Effect'] ?? null) !== 'Deny') {
            throw new MalformedPolicyException("Statement {$index} Effect must be Allow or Deny.");
        }

        self::requireExactlyOne($statement, 'Principal', 'NotPrincipal', $index);
        self::requireExactlyOne($statement, 'Action', 'NotAction', $index);
        self::requireExactlyOne($statement, 'Resource', 'NotResource', $index);

        self::validatePrincipal($statement[array_key_exists('Principal', $statement) ? 'Principal' : 'NotPrincipal'], $index);
        self::validateStringOrList($statement[array_key_exists('Action', $statement) ? 'Action' : 'NotAction'], "Statement {$index} action");
        self::validateStringOrList($statement[array_key_exists('Resource', $statement) ? 'Resource' : 'NotResource'], "Statement {$index} resource");

        if (array_key_exists('Condition', $statement)) {
            self::validateConditions($statement['Condition'], $index);
        }
    }

    /**
     * @param array<string, mixed> $statement
     */
    private static function requireExactlyOne(array $statement, string $positive, string $negative, int $index): void
    {
        $count = (int) array_key_exists($positive, $statement) + (int) array_key_exists($negative, $statement);
        if ($count !== 1) {
            throw new MalformedPolicyException(
                "Statement {$index} must contain exactly one of {$positive} or {$negative}.",
            );
        }
    }

    private static function validatePrincipal(mixed $principal, int $index): void
    {
        if (is_string($principal) && $principal !== '') {
            return;
        }

        if (!is_array($principal) || array_is_list($principal) || array_keys($principal) !== ['AWS']) {
            throw new MalformedPolicyException(
                "Statement {$index} Principal must be a string or an object containing only AWS.",
            );
        }

        self::validateStringOrList($principal['AWS'], "Statement {$index} Principal AWS");
    }

    private static function validateStringOrList(mixed $value, string $field): void
    {
        if (is_string($value) && $value !== '') {
            return;
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new MalformedPolicyException("{$field} must be a non-empty string or string list.");
        }

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new MalformedPolicyException("{$field} must contain only non-empty strings.");
            }
        }
    }

    private static function validateConditions(mixed $conditions, int $index): void
    {
        if (!is_array($conditions) || array_is_list($conditions)) {
            throw new MalformedPolicyException("Statement {$index} Condition must be a non-empty object.");
        }

        foreach ($conditions as $operator => $conditionBlock) {
            if (!is_string($operator) || $operator === ''
                || !is_array($conditionBlock) || array_is_list($conditionBlock)) {
                throw new MalformedPolicyException(
                    "Statement {$index} Condition operators must map to non-empty objects.",
                );
            }

            foreach ($conditionBlock as $key => $expected) {
                if (!is_string($key) || $key === '') {
                    throw new MalformedPolicyException("Statement {$index} Condition keys must be non-empty strings.");
                }

                self::validateConditionValue($expected, $index, $key);
            }
        }
    }

    private static function validateConditionValue(mixed $value, int $index, string $key): void
    {
        if (is_scalar($value)) {
            return;
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new MalformedPolicyException(
                "Statement {$index} Condition '{$key}' must be a scalar or non-empty scalar list.",
            );
        }

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new MalformedPolicyException(
                    "Statement {$index} Condition '{$key}' must contain only scalar values.",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $allowed
     */
    private static function rejectUnknownFields(array $document, array $allowed, string $context): void
    {
        foreach (array_keys($document) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new MalformedPolicyException("Unknown field '{$field}' in {$context}.");
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function optionalString(array $document, string $field, string $label): void
    {
        if (array_key_exists($field, $document)
            && (!is_string($document[$field]) || $document[$field] === '')) {
            throw new MalformedPolicyException("{$label} must be a non-empty string.");
        }
    }
}
