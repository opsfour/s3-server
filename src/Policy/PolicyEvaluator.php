<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

/**
 * Evaluates S3 bucket policies against requests.
 *
 * Supports Principal, Action wildcards, Resource ARN patterns, and a bounded
 * set of IAM/S3 condition keys. Unsupported conditions fail closed.
 */
final class PolicyEvaluator
{
    private const array SUPPORTED_CONDITION_OPERATORS = [
        'Bool',
        'DateGreaterThan',
        'DateGreaterThanEquals',
        'DateLessThan',
        'DateLessThanEquals',
        'IpAddress',
        'NotIpAddress',
        'Null',
        'NumericGreaterThan',
        'NumericGreaterThanEquals',
        'NumericLessThan',
        'NumericLessThanEquals',
        'StringEquals',
        'StringNotEquals',
        'StringLike',
        'StringNotLike',
    ];

    private const array SUPPORTED_EXACT_CONDITION_KEYS = [
        'aws:CurrentTime',
        'aws:PrincipalArn',
        'aws:SecureTransport',
        'aws:SourceIp',
        'aws:UserAgent',
        's3:delimiter',
        's3:max-keys',
        's3:prefix',
        's3:VersionId',
        's3:x-amz-acl',
        's3:x-amz-server-side-encryption',
        's3:x-amz-storage-class',
    ];

    /**
     * Evaluate a policy against a request context.
     *
     * @param string $policyJson The raw JSON bucket policy.
     * @param string $action IAM action string (e.g., 's3:GetObject').
     * @param string $resource Resource ARN (e.g., 'arn:aws:s3:::bucket/key').
     * @param string $principal The request principal (ownerId or '*').
     * @param array<string, string|list<string>> $conditions Context conditions (e.g., 'aws:SourceIp' => '1.2.3.4').
     * @param bool $requirePrincipal Whether statements without Principal/NotPrincipal should be ignored.
     * @return string 'Allow', 'Deny', or 'Neutral'.
     */
    public static function evaluate(
        string $policyJson,
        string $action,
        string $resource,
        string $principal,
        array $conditions = [],
        bool $requirePrincipal = true,
    ): string {
        try {
            $policy = json_decode($policyJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Corrupted policy must fail closed — deny access.
            return 'Deny';
        }
        if (!is_array($policy) || !isset($policy['Statement'])) {
            return 'Neutral';
        }

        $statements = self::normalizeStatements($policy['Statement']);

        if (self::hasUnsupportedConditions($statements)) {
            return 'Deny';
        }

        $hasExplicitDeny = false;
        $hasExplicitAllow = false;

        foreach ($statements as $statement) {
            if (!is_array($statement)) {
                continue;
            }

            $effect = $statement['Effect'] ?? '';
            if (!in_array($effect, ['Allow', 'Deny'], true)) {
                continue;
            }

            // Resource policies require Principal. Identity policies can omit it.
            if (!self::matchesPrincipal($statement, $principal, $requirePrincipal)) {
                continue;
            }

            // Match Action.
            if (!self::matchesAction($statement, $action)) {
                continue;
            }

            // Match Resource.
            if (!self::matchesResource($statement, $resource)) {
                continue;
            }

            // Match Condition (optional).
            if (isset($statement['Condition']) && !self::matchesCondition($statement['Condition'], $conditions)) {
                continue;
            }

            if ($effect === 'Deny') {
                $hasExplicitDeny = true;
            } elseif ($effect === 'Allow') {
                $hasExplicitAllow = true;
            }
        }

        // Explicit Deny always wins.
        if ($hasExplicitDeny) {
            return 'Deny';
        }

        if ($hasExplicitAllow) {
            return 'Allow';
        }

        return 'Neutral';
    }

    /**
     * Check if a policy grants public access (Principal: "*").
     */
    public static function isPublicPolicy(string $policyJson): bool
    {
        try {
            $policy = json_decode($policyJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($policy) || !isset($policy['Statement'])) {
            return false;
        }

        foreach (self::normalizeStatements($policy['Statement']) as $statement) {
            if (!is_array($statement)) {
                continue;
            }

            if (($statement['Effect'] ?? '') !== 'Allow') {
                continue;
            }

            if (isset($statement['Principal']) && self::principalValueIncludesWildcard($statement['Principal'])) {
                return true;
            }

            // Any Allow with NotPrincipal can include anonymous callers unless
            // the exclusion itself is the wildcard (which matches nobody).
            if (
                isset($statement['NotPrincipal'])
                && ! self::principalValueIncludesWildcard($statement['NotPrincipal'])
            ) {
                return true;
            }
        }

        return false;
    }

    private static function principalValueIncludesWildcard(mixed $principal): bool
    {
        if ($principal === '*') {
            return true;
        }

        if (! is_array($principal)) {
            return false;
        }

        $aws = $principal['AWS'] ?? null;
        if ($aws === '*') {
            return true;
        }

        return is_array($aws) && in_array('*', $aws, true);
    }

    /** @param array<string, mixed> $statement */
    private static function matchesPrincipal(array $statement, string $principal, bool $requirePrincipal): bool
    {
        if (isset($statement['Principal'])) {
            return self::matchesPrincipalValue($statement['Principal'], $principal);
        }
        if (isset($statement['NotPrincipal'])) {
            return !self::matchesPrincipalValue($statement['NotPrincipal'], $principal);
        }

        return !$requirePrincipal;
    }

    /**
     * @return list<mixed>
     */
    private static function normalizeStatements(mixed $statements): array
    {
        if (!is_array($statements)) {
            return [];
        }

        if (array_is_list($statements)) {
            return $statements;
        }

        return [$statements];
    }

    private static function matchesPrincipalValue(mixed $statementPrincipal, string $principal): bool
    {
        if ($statementPrincipal === '*') {
            return true;
        }

        if (is_array($statementPrincipal)) {
            $aws = $statementPrincipal['AWS'] ?? null;
            if ($aws === '*') {
                return true;
            }
            if (is_string($aws)) {
                $aws = [$aws];
            }
            if (is_array($aws)) {
                foreach ($aws as $p) {
                    if ($p === '*' || $p === $principal || str_ends_with($p, ':root') && str_starts_with($principal, 'arn:')) {
                        return true;
                    }
                }
            }
        }

        if (is_string($statementPrincipal) && $statementPrincipal === $principal) {
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $statement */
    private static function matchesAction(array $statement, string $action): bool
    {
        if (isset($statement['Action'])) {
            return self::matchesActionValue($statement['Action'], $action);
        }
        if (isset($statement['NotAction'])) {
            return !self::matchesActionValue($statement['NotAction'], $action);
        }

        return false;
    }

    private static function matchesActionValue(mixed $actions, string $action): bool
    {
        if (is_string($actions)) {
            $actions = [$actions];
        }

        if (!is_array($actions)) {
            return false;
        }

        foreach ($actions as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            if ($pattern === '*' || $pattern === 's3:*') {
                return true;
            }
            if (fnmatch($pattern, $action, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $statement */
    private static function matchesResource(array $statement, string $resource): bool
    {
        if (isset($statement['Resource'])) {
            return self::matchesResourceValue($statement['Resource'], $resource);
        }
        if (isset($statement['NotResource'])) {
            return !self::matchesResourceValue($statement['NotResource'], $resource);
        }

        return true; // No resource restriction.
    }

    private static function matchesResourceValue(mixed $resources, string $resource): bool
    {
        if (is_string($resources)) {
            $resources = [$resources];
        }

        if (!is_array($resources)) {
            return false;
        }

        foreach ($resources as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            if ($pattern === '*') {
                return true;
            }
            if (fnmatch($pattern, $resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $context
     */
    private static function matchesCondition(array $conditions, array $context): bool
    {
        foreach ($conditions as $operator => $conditionBlock) {
            if (!is_array($conditionBlock)) {
                return false;
            }

            [$setModifier, $baseOperator] = self::parseOperator($operator);
            if (!in_array($baseOperator, self::SUPPORTED_CONDITION_OPERATORS, true)) {
                return false;
            }

            foreach ($conditionBlock as $key => $expected) {
                if (!is_string($key) || !self::isSupportedConditionKey($key)) {
                    return false;
                }

                if ($baseOperator === 'Null') {
                    if (!self::nullMatches(array_key_exists($key, $context), $expected)) {
                        return false;
                    }
                    continue;
                }

                if (!array_key_exists($key, $context)) {
                    return false;
                }

                $actualValues = self::stringValues($context[$key]);
                if ($actualValues === []) {
                    return false;
                }

                $expectedValues = self::stringValues($expected);
                if ($expectedValues === []) {
                    return false;
                }

                $matched = self::matchesValues($baseOperator, $actualValues, $expectedValues, $setModifier);

                if (!$matched) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function hasUnsupportedConditions(mixed $statements): bool
    {
        if (!is_array($statements)) {
            return false;
        }

        foreach ($statements as $statement) {
            if (!is_array($statement) || !isset($statement['Condition'])) {
                continue;
            }

            if (!is_array($statement['Condition'])) {
                return true;
            }

            foreach ($statement['Condition'] as $operator => $conditionBlock) {
                if (!is_string($operator) || !is_array($conditionBlock)) {
                    return true;
                }

                [, $baseOperator] = self::parseOperator($operator);
                if (!in_array($baseOperator, self::SUPPORTED_CONDITION_OPERATORS, true)) {
                    return true;
                }

                foreach ($conditionBlock as $key => $_) {
                    if (!is_string($key) || !self::isSupportedConditionKey($key)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function isSupportedConditionKey(string $key): bool
    {
        return in_array($key, self::SUPPORTED_EXACT_CONDITION_KEYS, true)
            || str_starts_with($key, 's3:ExistingObjectTag/')
            || str_starts_with($key, 's3:RequestObjectTag/');
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function parseOperator(string $operator): array
    {
        foreach (['ForAnyValue', 'ForAllValues'] as $modifier) {
            $prefix = $modifier . ':';
            if (str_starts_with($operator, $prefix)) {
                return [$modifier, substr($operator, strlen($prefix))];
            }
        }

        return [null, $operator];
    }

    /**
     * @param list<string> $actualValues
     * @param list<string> $expectedValues
     */
    private static function matchesValues(string $operator, array $actualValues, array $expectedValues, ?string $setModifier): bool
    {
        $matcher = static fn(string $actual): bool => match ($operator) {
            'Bool' => self::boolMatches($actual, $expectedValues),
            'DateGreaterThan' => self::dateCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp > 0),
            'DateGreaterThanEquals' => self::dateCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp >= 0),
            'DateLessThan' => self::dateCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp < 0),
            'DateLessThanEquals' => self::dateCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp <= 0),
            'NumericGreaterThan' => self::numericCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp > 0),
            'NumericGreaterThanEquals' => self::numericCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp >= 0),
            'NumericLessThan' => self::numericCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp < 0),
            'NumericLessThanEquals' => self::numericCompare($actual, $expectedValues, static fn(int $cmp): bool => $cmp <= 0),
            'StringEquals' => in_array($actual, $expectedValues, true),
            'StringNotEquals' => !in_array($actual, $expectedValues, true),
            'StringLike' => self::anyFnmatch($expectedValues, $actual),
            'StringNotLike' => !self::anyFnmatch($expectedValues, $actual),
            'IpAddress' => self::ipInRanges($actual, $expectedValues),
            'NotIpAddress' => !self::ipInRanges($actual, $expectedValues),
            default => false,
        };

        if ($setModifier === 'ForAllValues') {
            foreach ($actualValues as $actual) {
                if (!$matcher($actual)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($actualValues as $actual) {
            if ($matcher($actual)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringValues(mixed $value): array
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return [(string) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item) || is_bool($item)) {
                $values[] = (string) $item;
            }
        }

        return $values;
    }

    private static function nullMatches(bool $exists, mixed $expected): bool
    {
        $expectedValues = self::stringValues($expected);
        if ($expectedValues === []) {
            return false;
        }

        return in_array(strtolower($expectedValues[0]), ['true', '1'], true) ? !$exists : $exists;
    }

    /** @param list<string> $expectedValues */
    private static function boolMatches(string $actual, array $expectedValues): bool
    {
        $actual = strtolower($actual);
        foreach ($expectedValues as $expected) {
            if ($actual === strtolower($expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(int): bool $compare
     * @param list<string> $expectedValues
     */
    private static function numericCompare(string $actual, array $expectedValues, callable $compare): bool
    {
        if (!is_numeric($actual)) {
            return false;
        }

        foreach ($expectedValues as $expected) {
            if (!is_numeric($expected)) {
                continue;
            }

            $cmp = ((float) $actual) <=> ((float) $expected);
            if ($compare($cmp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(int): bool $compare
     * @param list<string> $expectedValues
     */
    private static function dateCompare(string $actual, array $expectedValues, callable $compare): bool
    {
        $actualTime = strtotime($actual);
        if ($actualTime === false) {
            return false;
        }

        foreach ($expectedValues as $expected) {
            $expectedTime = strtotime($expected);
            if ($expectedTime === false) {
                continue;
            }

            $cmp = $actualTime <=> $expectedTime;
            if ($compare($cmp)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private static function anyFnmatch(array $patterns, string $value): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $ranges */
    private static function ipInRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (str_contains($range, '/')) {
                if (self::cidrMatch($ip, $range)) {
                    return true;
                }
            } else {
                if ($ip === $range) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * CIDR match with dual-stack IPv4/IPv6 support.
     */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // IPv4/IPv6 mismatch.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $mask = str_repeat("\xff", intdiv($bits, 8));
        if ($bits % 8) {
            $mask .= chr(0xff << (8 - ($bits % 8)));
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }
}
