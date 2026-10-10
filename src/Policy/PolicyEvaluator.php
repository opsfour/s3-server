<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

use OpsFour\S3Server\Http\Iso8601Timestamp;

/**
 * Evaluates S3 bucket policies against requests.
 *
 * Supports Principal, Action wildcards, Resource ARN patterns, and a bounded
 * set of IAM/S3 condition keys. Unsupported conditions fail closed.
 */
final class PolicyEvaluator
{
    private const array SUPPORTED_STATEMENT_FIELDS = [
        'Action', 'Condition', 'Effect', 'NotAction', 'NotPrincipal',
        'NotResource', 'Principal', 'Resource', 'Sid',
    ];

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
        if (! is_array($policy)
            || ! array_key_exists('Statement', $policy)
            || ! is_array($policy['Statement'])) {
            return 'Deny';
        }

        $statements = self::normalizeStatements($policy['Statement']);

        if (self::hasUnsupportedConditions($statements) || self::hasMalformedSelectors($statements)) {
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

            if (isset($statement['Principal']) && self::principalValueIncludesWildcard($statement['Principal'])
                && ! self::hasNarrowSourceIpBoundary($statement)) {
                return true;
            }

            // Any Allow with NotPrincipal can include anonymous callers unless
            // the exclusion itself is the wildcard (which matches nobody).
            if (
                isset($statement['NotPrincipal'])
                && ! self::principalValueIncludesWildcard($statement['NotPrincipal'])
                && ! self::hasNarrowSourceIpBoundary($statement)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $statement */
    private static function hasNarrowSourceIpBoundary(array $statement): bool
    {
        $ranges = $statement['Condition']['IpAddress']['aws:SourceIp'] ?? null;
        if (is_string($ranges)) {
            $ranges = [$ranges];
        }
        if (! is_array($ranges) || $ranges === []) {
            return false;
        }

        foreach ($ranges as $range) {
            if (! is_string($range)) {
                return false;
            }
            $parts = explode('/', $range, 2);
            $address = $parts[0];
            $prefix = $parts[1] ?? null;
            if ($prefix === null) {
                return false;
            }
            $binary = @inet_pton($address);
            if ($binary === false) {
                return false;
            }
            $maxBits = strlen($binary) * 8;
            $minimumBits = $maxBits === 32 ? 8 : 32;
            if (! ctype_digit($prefix) || (int) $prefix < $minimumBits || (int) $prefix > $maxBits) {
                return false;
            }
        }

        return true;
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
                    if ($p === '*' || $p === $principal || self::matchesAccountPrincipal($p, $principal)) {
                        return true;
                    }
                }
            }
        }

        return is_string($statementPrincipal)
            && ($statementPrincipal === $principal || self::matchesAccountPrincipal($statementPrincipal, $principal));
    }

    private static function matchesAccountPrincipal(string $policyPrincipal, string $requestPrincipal): bool
    {
        $account = explode(':', $policyPrincipal, 6);
        $identity = explode(':', $requestPrincipal, 6);
        if (count($account) !== 6 || count($identity) !== 6
            || $account[0] !== 'arn' || $identity[0] !== 'arn'
            || $account[1] !== $identity[1]
            || $account[2] !== 'iam' || !in_array($identity[2], ['iam', 'sts'], true)
            || $account[3] !== '' || $identity[3] !== ''
            || preg_match('/^[0-9]{12}$/D', $account[4]) !== 1
            || $account[4] !== $identity[4] || $account[5] !== 'root') {
            return false;
        }

        return $identity[2] === 'iam'
            ? $identity[5] === 'root' || preg_match('~^(?:user|role)/.+$~D', $identity[5]) === 1
            : preg_match('~^(?:assumed-role|federated-user)/.+$~D', $identity[5]) === 1;
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
            if (self::wildcardMatches($pattern, $action, true)) {
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
            if (self::wildcardMatches($pattern, $resource)) {
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

                $expectedValues = self::stringValues($expected);
                if ($expectedValues === []) {
                    return false;
                }

                $actualValues = array_key_exists($key, $context)
                    ? self::stringValues($context[$key])
                    : [];
                if ($actualValues === []) {
                    // A missing context key satisfies a negated condition.
                    // Set modifiers override this: ForAllValues is vacuously
                    // true and ForAnyValue is false for an empty value set.
                    if ($setModifier === 'ForAllValues'
                        || ($setModifier === null && in_array($baseOperator, ['StringNotEquals', 'StringNotLike', 'NotIpAddress'], true))) {
                        continue;
                    }

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
            if (!is_array($statement) || !array_key_exists('Condition', $statement)) {
                continue;
            }

            if (!is_array($statement['Condition']) || $statement['Condition'] === [] || array_is_list($statement['Condition'])) {
                return true;
            }

            foreach ($statement['Condition'] as $operator => $conditionBlock) {
                if (!is_string($operator) || !is_array($conditionBlock) || $conditionBlock === [] || array_is_list($conditionBlock)) {
                    return true;
                }

                [, $baseOperator] = self::parseOperator($operator);
                if (! in_array($baseOperator, self::SUPPORTED_CONDITION_OPERATORS, true)) {
                    return true;
                }

                foreach ($conditionBlock as $key => $expected) {
                    if (!is_string($key) || !self::supportsCondition($operator, $key)
                        || !self::supportsConditionValue($operator, $expected)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Stored account and named policies do not pass through the bucket-policy
     * validator. Missing or invalid action/resource selectors must not turn
     * into unrestricted Allows or silently disable Denies when written directly.
     *
     * @param list<mixed> $statements
     */
    private static function hasMalformedSelectors(array $statements): bool
    {
        foreach ($statements as $statement) {
            if (! is_array($statement)
                || ! in_array($statement['Effect'] ?? null, ['Allow', 'Deny'], true)) {
                return true;
            }
            foreach (array_keys($statement) as $field) {
                if (! is_string($field) || ! in_array($field, self::SUPPORTED_STATEMENT_FIELDS, true)) {
                    return true;
                }
            }

            if (array_key_exists('Principal', $statement)
                && ! self::validPrincipalSelector($statement['Principal'])) {
                return true;
            }
            if (array_key_exists('NotPrincipal', $statement)
                && (array_key_exists('Principal', $statement)
                    || ! self::validPrincipalSelector($statement['NotPrincipal']))) {
                return true;
            }
            if ((int) array_key_exists('Action', $statement) + (int) array_key_exists('NotAction', $statement) !== 1
                || (array_key_exists('Action', $statement) && ! self::validSelectorStrings($statement['Action']))) {
                return true;
            }
            if ((int) array_key_exists('Resource', $statement) + (int) array_key_exists('NotResource', $statement) !== 1) {
                return true;
            }
            foreach (['NotAction' => 'Action', 'NotResource' => 'Resource'] as $negative => $positive) {
                if (array_key_exists($negative, $statement)
                    && (array_key_exists($positive, $statement)
                        || ! self::validSelectorStrings($statement[$negative]))) {
                    return true;
                }
            }
            if (array_key_exists('Resource', $statement) && ! self::validSelectorStrings($statement['Resource'])) {
                return true;
            }
        }

        return false;
    }

    private static function validPrincipalSelector(mixed $principal): bool
    {
        if (is_string($principal)) {
            return $principal !== '';
        }

        return is_array($principal)
            && array_keys($principal) === ['AWS']
            && self::validSelectorStrings($principal['AWS']);
    }

    private static function validSelectorStrings(mixed $selector): bool
    {
        if (is_string($selector)) {
            return $selector !== '';
        }

        if (! is_array($selector) || ! array_is_list($selector) || $selector === []) {
            return false;
        }

        foreach ($selector as $item) {
            if (! is_string($item) || $item === '') {
                return false;
            }
        }

        return true;
    }

    public static function supportsCondition(string $operator, string $key): bool
    {
        [, $baseOperator] = self::parseOperator($operator);

        return in_array($baseOperator, self::SUPPORTED_CONDITION_OPERATORS, true)
            && self::isSupportedConditionKey($key);
    }

    public static function supportsConditionValue(string $operator, mixed $value): bool
    {
        [, $baseOperator] = self::parseOperator($operator);
        // Account and named policies can be stored without passing through
        // PolicyDocumentValidator. Reject the same malformed value shapes here
        // so an invalid Deny condition cannot silently stop matching.
        if (!is_scalar($value)) {
            if (!is_array($value) || !array_is_list($value) || $value === []) {
                return false;
            }
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    return false;
                }
            }
        }
        if (!in_array($baseOperator, ['Bool', 'Null', 'IpAddress', 'NotIpAddress'], true)
            && !str_starts_with($baseOperator, 'Numeric')
            && !str_starts_with($baseOperator, 'Date')) {
            return true;
        }

        if ($baseOperator === 'Null' && is_array($value)) {
            return false;
        }

        $values = is_array($value) ? $value : [$value];
        foreach ($values as $item) {
            if (in_array($baseOperator, ['IpAddress', 'NotIpAddress'], true)) {
                if (!is_string($item)
                    || !(str_contains($item, '/') ? self::validCidr($item) : @inet_pton($item) !== false)) {
                    return false;
                }
            } elseif (str_starts_with($baseOperator, 'Numeric')) {
                if ((!is_int($item) && !is_float($item) && !is_string($item))
                    || !is_numeric($item) || !is_finite((float) $item)) {
                    return false;
                }
            } elseif (str_starts_with($baseOperator, 'Date')) {
                if ((!is_int($item) && !is_float($item) && !is_string($item))
                    || self::parseDateValue((string) $item) === null) {
                    return false;
                }
            } elseif (!is_bool($item) && (!is_string($item) || !in_array(strtolower($item), ['true', 'false'], true))) {
                return false;
            }
        }

        return true;
    }

    public static function hasUnsupportedConditionsInDocument(string $policyJson): bool
    {
        try {
            $policy = json_decode($policyJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true;
        }

        if (! is_array($policy) || ! isset($policy['Statement'])) {
            return true;
        }

        $statements = self::normalizeStatements($policy['Statement']);

        return self::hasUnsupportedConditions($statements) || self::hasMalformedSelectors($statements);
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
            'StringLike' => self::anyWildcardMatch($expectedValues, $actual),
            'StringNotLike' => !self::anyWildcardMatch($expectedValues, $actual),
            'IpAddress' => self::ipConditionMatches($actual, $expectedValues),
            'NotIpAddress' => self::ipConditionMatches($actual, $expectedValues, true),
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
            return [is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item) || is_bool($item)) {
                $values[] = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
            }
        }

        return $values;
    }

    private static function nullMatches(bool $exists, mixed $expected): bool
    {
        $expectedValues = self::stringValues($expected);
        if (count($expectedValues) !== 1) {
            return false;
        }

        return match (strtolower($expectedValues[0])) {
            'true' => !$exists,
            'false' => $exists,
            default => false,
        };
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
        $actualTime = self::parseDateValue($actual);
        if ($actualTime === null) {
            return false;
        }

        foreach ($expectedValues as $expected) {
            $expectedTime = self::parseDateValue($expected);
            if ($expectedTime === null) {
                continue;
            }

            $cmp = $actualTime <=> $expectedTime;
            if ($compare($cmp)) {
                return true;
            }
        }

        return false;
    }

    private static function parseDateValue(string $value): ?float
    {
        if (is_numeric($value)) {
            $epoch = (float) $value;

            return is_finite($epoch) ? $epoch : null;
        }

        $timestamp = Iso8601Timestamp::parse($value);
        if ($timestamp !== null) {
            return (float) $timestamp->format('U.u');
        }

        if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})(?:-(?<day>\d{2}))?$/D', $value, $parts) !== 1) {
            return null;
        }
        $year = (int) $parts['year'];
        $month = (int) $parts['month'];
        $day = isset($parts['day']) ? (int) $parts['day'] : 1;
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return (float) (new \DateTimeImmutable(
            sprintf('%04d-%02d-%02dT00:00:00Z', $year, $month, $day),
        ))->getTimestamp();
    }

    /** @param list<string> $patterns */
    private static function anyWildcardMatch(array $patterns, string $value): bool
    {
        foreach ($patterns as $pattern) {
            if (self::wildcardMatches($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private static function wildcardMatches(string $pattern, string $value, bool $caseInsensitive = false): bool
    {
        if ($caseInsensitive) {
            $pattern = strtolower($pattern);
            $value = strtolower($value);
        }

        $patternLength = strlen($pattern);
        $valueLength = strlen($value);
        $patternIndex = 0;
        $valueIndex = 0;
        $lastStar = -1;
        $afterStar = 0;

        while ($valueIndex < $valueLength) {
            if ($patternIndex < $patternLength
                && ($pattern[$patternIndex] === '?' || $pattern[$patternIndex] === $value[$valueIndex])) {
                ++$patternIndex;
                ++$valueIndex;
                continue;
            }
            if ($patternIndex < $patternLength && $pattern[$patternIndex] === '*') {
                $lastStar = $patternIndex++;
                $afterStar = $valueIndex;
                continue;
            }
            if ($lastStar < 0) {
                return false;
            }
            $patternIndex = $lastStar + 1;
            $valueIndex = ++$afterStar;
        }

        while ($patternIndex < $patternLength && $pattern[$patternIndex] === '*') {
            ++$patternIndex;
        }

        return $patternIndex === $patternLength;
    }

    /** @param list<string> $ranges */
    private static function ipConditionMatches(string $ip, array $ranges, bool $negate = false): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false || $ranges === []) {
            return false;
        }

        $matched = false;
        foreach ($ranges as $range) {
            if (str_contains($range, '/')) {
                if (! self::validCidr($range)) {
                    return false;
                }
                if (self::cidrMatch($ip, $range)) {
                    $matched = true;
                }
                continue;
            }

            $rangeBin = @inet_pton($range);
            if ($rangeBin === false) {
                return false;
            }
            if ($rangeBin === $ipBin) {
                $matched = true;
            }
        }

        return $negate ? ! $matched : $matched;
    }

    private static function validCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || ! ctype_digit($parts[1])) {
            return false;
        }

        $subnetBin = @inet_pton($parts[0]);
        if ($subnetBin === false) {
            return false;
        }

        return (int) $parts[1] <= strlen($subnetBin) * 8;
    }

    /**
     * CIDR match with dual-stack IPv4/IPv6 support.
     */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        if (! self::validCidr($cidr)) {
            return false;
        }

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
            $mask .= pack('C', (0xff << (8 - ($bits % 8))) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }
}
