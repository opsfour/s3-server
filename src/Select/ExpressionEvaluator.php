<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Evaluates WHERE clause expressions for S3 Select.
 */
final class ExpressionEvaluator
{
    /**
     * Evaluate a parsed WHERE expression against a row.
     *
     * @param array<string, mixed>|null $where The parsed WHERE clause.
     * @param array<string, mixed> $row The data row.
     * @param string $alias The table alias (e.g., 's').
     * @return bool Whether the row matches the WHERE condition.
     */
    public static function evaluate(?array $where, array $row, string $alias = 's'): bool
    {
        if ($where === null) {
            return true;
        }

        $tokens = $where['tokens'] ?? [];
        if ($tokens === []) {
            return true;
        }

        return self::evaluateTokens($tokens, $row, $alias);
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     * @param array<string, mixed> $row
     */
    private static function evaluateTokens(array $tokens, array $row, string $alias): bool
    {
        // Simple evaluation: find comparison operators and evaluate.
        $len = count($tokens);
        if ($len === 0) {
            return true;
        }

        // Look for OR first (lowest precedence), then AND (higher precedence).
        // This ensures `A AND B OR C` evaluates as `(A AND B) OR C` per SQL standard.

        // Pass 1: scan for OR at depth 0 (lowest precedence — outermost split).
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i]['value'] === '(') $depth++;
            if ($tokens[$i]['value'] === ')') $depth--;
            if ($depth === 0 && $tokens[$i]['type'] === 'KEYWORD' && $tokens[$i]['value'] === 'OR') {
                $left = array_slice($tokens, 0, $i);
                $right = array_slice($tokens, $i + 1);
                return self::evaluateTokens($left, $row, $alias) || self::evaluateTokens($right, $row, $alias);
            }
        }

        // Pass 2: scan for AND at depth 0 (higher precedence).
        // Skip AND keywords that are part of a BETWEEN construct.
        $depth = 0;
        $betweenCount = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i]['value'] === '(') $depth++;
            if ($tokens[$i]['value'] === ')') $depth--;
            if ($depth === 0 && $tokens[$i]['type'] === 'KEYWORD') {
                if ($tokens[$i]['value'] === 'BETWEEN') {
                    $betweenCount++;
                } elseif ($tokens[$i]['value'] === 'AND') {
                    if ($betweenCount > 0) {
                        $betweenCount--; // This AND belongs to a BETWEEN, skip it.
                        continue;
                    }
                    $left = array_slice($tokens, 0, $i);
                    $right = array_slice($tokens, $i + 1);
                    return self::evaluateTokens($left, $row, $alias) && self::evaluateTokens($right, $row, $alias);
                }
            }
        }

        // Handle NOT.
        if ($tokens[0]['type'] === 'KEYWORD' && $tokens[0]['value'] === 'NOT') {
            return !self::evaluateTokens(array_slice($tokens, 1), $row, $alias);
        }

        // Handle parentheses.
        if ($tokens[0]['value'] === '(' && $tokens[$len - 1]['value'] === ')') {
            return self::evaluateTokens(array_slice($tokens, 1, -1), $row, $alias);
        }

        // Simple comparison: field OP value.
        return self::evaluateComparison($tokens, $row, $alias);
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private static function evaluateComparison(array $tokens, array $row, string $alias): bool
    {
        $len = count($tokens);

        // IS NOT NULL (must be checked before IS NULL).
        if ($len >= 4 && $tokens[$len - 1]['value'] === 'NULL' && $tokens[$len - 2]['value'] === 'NOT' && $tokens[$len - 3]['value'] === 'IS') {
            $fieldTokens = array_slice($tokens, 0, $len - 3);
            $value = self::resolveValue($fieldTokens, $row, $alias);
            return $value !== null;
        }

        // IS NULL.
        if ($len >= 3 && $tokens[$len - 1]['value'] === 'NULL' && $tokens[$len - 2]['value'] === 'IS') {
            $fieldTokens = array_slice($tokens, 0, $len - 2);
            $value = self::resolveValue($fieldTokens, $row, $alias);
            return $value === null;
        }

        // NOT LIKE / LIKE.
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i]['type'] === 'KEYWORD' && $tokens[$i]['value'] === 'LIKE') {
                // Check for NOT LIKE: token before LIKE is NOT.
                $negate = ($i > 0 && $tokens[$i - 1]['type'] === 'KEYWORD' && $tokens[$i - 1]['value'] === 'NOT');
                $leftEnd = $negate ? $i - 1 : $i;
                $leftVal = self::resolveValue(array_slice($tokens, 0, $leftEnd), $row, $alias);
                $rightVal = self::resolveValue(array_slice($tokens, $i + 1), $row, $alias);
                if ($leftVal === null || $rightVal === null) return false;
                $pattern = implode('.*', array_map(
                    fn(string $p) => implode('.', array_map(fn(string $q) => preg_quote($q, '/'), explode('_', $p))),
                    explode('%', (string) $rightVal),
                ));
                $matchResult = preg_match('/^' . $pattern . '$/s', (string) $leftVal) === 1;
                return $negate ? !$matchResult : $matchResult;
            }
        }

        // NOT BETWEEN / BETWEEN.
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i]['type'] === 'KEYWORD' && $tokens[$i]['value'] === 'BETWEEN') {
                $negate = ($i > 0 && $tokens[$i - 1]['type'] === 'KEYWORD' && $tokens[$i - 1]['value'] === 'NOT');
                $leftEnd = $negate ? $i - 1 : $i;
                for ($j = $i + 1; $j < $len; $j++) {
                    if ($tokens[$j]['type'] === 'KEYWORD' && $tokens[$j]['value'] === 'AND') {
                        $val = self::resolveValue(array_slice($tokens, 0, $leftEnd), $row, $alias);
                        $low = self::resolveValue(array_slice($tokens, $i + 1, $j - $i - 1), $row, $alias);
                        $high = self::resolveValue(array_slice($tokens, $j + 1), $row, $alias);
                        $result = $val >= $low && $val <= $high;
                        return $negate ? !$result : $result;
                    }
                }
            }
        }

        // NOT IN / IN.
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i]['type'] === 'KEYWORD' && $tokens[$i]['value'] === 'IN') {
                $negate = ($i > 0 && $tokens[$i - 1]['type'] === 'KEYWORD' && $tokens[$i - 1]['value'] === 'NOT');
                $leftEnd = $negate ? $i - 1 : $i;
                $val = self::resolveValue(array_slice($tokens, 0, $leftEnd), $row, $alias);
                $inValues = [];
                for ($j = $i + 1; $j < $len; $j++) {
                    if ($tokens[$j]['type'] === 'STRING' || $tokens[$j]['type'] === 'NUMBER') {
                        $inValues[] = $tokens[$j]['value'];
                    }
                }
                $result = in_array((string) $val, $inValues, true);
                return $negate ? !$result : $result;
            }
        }

        // Standard comparison: find the operator.
        for ($i = 0; $i < $len; $i++) {
            if ($tokens[$i]['type'] === 'OP' && in_array($tokens[$i]['value'], ['=', '!=', '<', '>', '<=', '>='], true)) {
                $leftVal = self::resolveValue(array_slice($tokens, 0, $i), $row, $alias);
                $rightVal = self::resolveValue(array_slice($tokens, $i + 1), $row, $alias);

                return match ($tokens[$i]['value']) {
                    '=' => $leftVal == $rightVal,
                    '!=' => $leftVal != $rightVal,
                    '<' => $leftVal < $rightVal,
                    '>' => $leftVal > $rightVal,
                    '<=' => $leftVal <= $rightVal,
                    '>=' => $leftVal >= $rightVal,
                    default => false,
                };
            }
        }

        return true;
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private static function resolveValue(array $tokens, array $row, string $alias): mixed
    {
        if (count($tokens) === 0) return null;

        // Single token.
        if (count($tokens) === 1) {
            $token = $tokens[0];
            if ($token['type'] === 'STRING') return $token['value'];
            if ($token['type'] === 'NUMBER') return is_numeric($token['value']) ? (str_contains($token['value'], '.') ? (float) $token['value'] : (int) $token['value']) : $token['value'];
            if ($token['type'] === 'KEYWORD' && $token['value'] === 'NULL') return null;
            if ($token['type'] === 'KEYWORD' && $token['value'] === 'TRUE') return true;
            if ($token['type'] === 'KEYWORD' && $token['value'] === 'FALSE') return false;
            if ($token['type'] === 'IDENT') {
                return $row[$token['value']] ?? null;
            }
        }

        // Dot notation: alias.field or alias._1.
        if (count($tokens) === 3 && $tokens[1]['value'] === '.') {
            $field = $tokens[2]['value'];
            return JsonProcessor::resolvePath($row, $field);
        }

        // Longer dot paths: alias.nested.field.
        $parts = [];
        $isDotPath = true;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($i % 2 === 0) {
                $parts[] = $tokens[$i]['value'];
            } elseif ($tokens[$i]['value'] !== '.') {
                $isDotPath = false;
                break;
            }
        }

        if ($isDotPath && count($parts) > 1) {
            // Skip the alias prefix.
            array_shift($parts);
            return JsonProcessor::resolvePath($row, implode('.', $parts));
        }

        // Function call: LOWER(x), UPPER(x), SUBSTRING(x, pos[, len]), CAST(x AS type), etc.
        if ($tokens[0]['type'] === 'KEYWORD' && count($tokens) >= 3) {
            $func = $tokens[0]['value'];

            // Find inner tokens between the outermost parentheses.
            $innerTokens = [];
            $depth = 0;
            for ($i = 1; $i < count($tokens); $i++) {
                if ($tokens[$i]['value'] === '(') { $depth++; if ($depth === 1) continue; }
                if ($tokens[$i]['value'] === ')') { $depth--; if ($depth === 0) continue; }
                if ($depth > 0) $innerTokens[] = $tokens[$i];
            }

            // SUBSTRING(str, pos[, len]) — SQL standard, 1-based position.
            if ($func === 'SUBSTRING') {
                $args = self::splitByComma($innerTokens);
                if (count($args) < 2) {
                    return null;
                }
                $str = (string) self::resolveValue($args[0], $row, $alias);
                $pos = (int) self::resolveValue($args[1], $row, $alias);
                // Convert 1-based SQL position to 0-based PHP index.
                $phpPos = $pos - 1;
                if (isset($args[2])) {
                    $len = (int) self::resolveValue($args[2], $row, $alias);
                    return substr($str, $phpPos, $len);
                }
                return substr($str, $phpPos);
            }

            // CAST(expr AS type).
            if ($func === 'CAST') {
                return self::evaluateCast($innerTokens, $row, $alias);
            }

            // Aggregate functions — these are no-ops at row level, resolved by computeAggregates().
            if (in_array($func, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)) {
                // At individual row level, return the field value for MIN/MAX or numeric for SUM.
                // True aggregation is handled by computeAggregates() over all rows.
                $innerVal = self::resolveValue($innerTokens, $row, $alias);
                return $innerVal;
            }

            // Single-argument scalar functions.
            $innerVal = self::resolveValue($innerTokens, $row, $alias);

            return match ($func) {
                'LOWER' => strtolower((string) $innerVal),
                'UPPER' => strtoupper((string) $innerVal),
                'TRIM' => trim((string) $innerVal),
                'CHAR_LENGTH' => mb_strlen((string) $innerVal, 'UTF-8'),
                default => $innerVal,
            };
        }

        return $tokens[0]['value'] ?? null;
    }

    /**
     * Split a token list by top-level commas into separate argument lists.
     *
     * @param list<array{type: string, value: string}> $tokens
     * @return list<list<array{type: string, value: string}>>
     */
    private static function splitByComma(array $tokens): array
    {
        $args = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token['value'] === '(') $depth++;
            if ($token['value'] === ')') $depth--;
            if ($depth === 0 && $token['type'] === 'OP' && $token['value'] === ',') {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        if ($current !== []) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * Evaluate CAST(expr AS type).
     *
     * @param list<array{type: string, value: string}> $innerTokens Tokens between the parentheses.
     */
    private static function evaluateCast(array $innerTokens, array $row, string $alias): mixed
    {
        // Find the AS keyword to split expr and type.
        $asIndex = null;
        $depth = 0;
        for ($i = 0; $i < count($innerTokens); $i++) {
            if ($innerTokens[$i]['value'] === '(') $depth++;
            if ($innerTokens[$i]['value'] === ')') $depth--;
            if ($depth === 0 && $innerTokens[$i]['type'] === 'KEYWORD' && $innerTokens[$i]['value'] === 'AS') {
                $asIndex = $i;
                break;
            }
        }

        if ($asIndex === null) {
            return null;
        }

        $exprTokens = array_slice($innerTokens, 0, $asIndex);
        $typeTokens = array_slice($innerTokens, $asIndex + 1);
        $value = self::resolveValue($exprTokens, $row, $alias);
        $typeName = strtoupper(trim(implode('', array_column($typeTokens, 'value'))));

        return match ($typeName) {
            'INT', 'INTEGER' => (int) $value,
            'FLOAT', 'DOUBLE', 'DECIMAL' => (float) $value,
            'VARCHAR', 'STRING', 'CHAR' => (string) $value,
            'BOOL', 'BOOLEAN' => (bool) $value,
            default => $value,
        };
    }

    /**
     * Check whether a parsed column list contains aggregate functions.
     *
     * @param list<array{expr: string, alias: ?string}> $columns Parsed column definitions from SqlParser.
     */
    public static function isAggregate(array $columns): bool
    {
        $aggregateKeywords = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

        foreach ($columns as $col) {
            $upper = strtoupper($col['expr']);
            foreach ($aggregateKeywords as $keyword) {
                if (str_contains($upper, $keyword . '(') || str_contains($upper, $keyword . ' (')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compute aggregate function results over all rows.
     *
     * @param list<array{expr: string, alias: ?string}> $columns Parsed column definitions.
     * @param list<array<string, mixed>> $allRows All filtered data rows.
     * @param string $alias The table alias.
     * @return list<array<string, mixed>> A single-element array containing the aggregate result row.
     */
    public static function computeAggregates(array $columns, array $allRows, string $alias): array
    {
        $result = [];

        foreach ($columns as $col) {
            $expr = $col['expr'];
            $outputKey = $col['alias'] ?? $expr;
            $upper = strtoupper($expr);

            // Parse the aggregate: FUNC(arg).
            if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\s*\(\s*(.*?)\s*\)$/i', $expr, $m)) {
                $func = strtoupper($m[1]);
                $arg = trim($m[2]);

                $result[$outputKey] = self::computeSingleAggregate($func, $arg, $allRows, $alias);
            } else {
                // Non-aggregate column in an aggregate query — use first row value.
                if ($allRows !== []) {
                    $field = $expr;
                    if (str_contains($field, '.')) {
                        $parts = explode('.', $field, 2);
                        $field = $parts[1] ?? $parts[0];
                    }
                    $result[$outputKey] = $allRows[0][$field] ?? '';
                } else {
                    $result[$outputKey] = '';
                }
            }
        }

        return [$result];
    }

    /**
     * Compute a single aggregate function over all rows.
     *
     * @param list<array<string, mixed>> $allRows
     */
    private static function computeSingleAggregate(string $func, string $arg, array $allRows, string $alias): int|float|string
    {
        // COUNT(*) counts all rows.
        if ($func === 'COUNT' && $arg === '*') {
            return count($allRows);
        }

        // Resolve the field name (strip alias prefix).
        $field = $arg;
        if (str_contains($field, '.')) {
            $parts = explode('.', $field, 2);
            $field = $parts[1] ?? $parts[0];
        }

        // Collect values from all rows.
        $values = [];
        foreach ($allRows as $row) {
            $val = $row[$field] ?? null;
            if ($val !== null) {
                $values[] = $val;
            }
        }

        if ($values === []) {
            return match ($func) {
                'COUNT' => 0,
                default => '',
            };
        }

        return match ($func) {
            'COUNT' => count($values),
            'SUM' => array_sum(array_map(fn ($v) => is_numeric($v) ? (float) $v : 0, $values)),
            'AVG' => count($values) > 0
                ? array_sum(array_map(fn ($v) => is_numeric($v) ? (float) $v : 0, $values)) / count($values)
                : 0,
            'MIN' => self::aggregateMin($values),
            'MAX' => self::aggregateMax($values),
            default => '',
        };
    }

    /**
     * Compute MIN supporting both numeric and string values.
     *
     * @param list<mixed> $values
     */
    private static function aggregateMin(array $values): int|float|string
    {
        $allNumeric = true;
        foreach ($values as $v) {
            if (!is_numeric($v)) {
                $allNumeric = false;
                break;
            }
        }

        if ($allNumeric) {
            $nums = array_map(fn ($v) => str_contains((string) $v, '.') ? (float) $v : (int) $v, $values);
            return min($nums);
        }

        $strings = array_map(fn ($v) => (string) $v, $values);
        return min($strings);
    }

    /**
     * Compute MAX supporting both numeric and string values.
     *
     * @param list<mixed> $values
     */
    private static function aggregateMax(array $values): int|float|string
    {
        $allNumeric = true;
        foreach ($values as $v) {
            if (!is_numeric($v)) {
                $allNumeric = false;
                break;
            }
        }

        if ($allNumeric) {
            $nums = array_map(fn ($v) => str_contains((string) $v, '.') ? (float) $v : (int) $v, $values);
            return max($nums);
        }

        $strings = array_map(fn ($v) => (string) $v, $values);
        return max($strings);
    }
}
