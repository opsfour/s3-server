<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Tokenizer + recursive descent parser for S3 Select SQL.
 *
 * Supports: SELECT columns FROM S3Object [AS alias] [WHERE conditions]
 */
final class SqlParser
{
    private const int MAX_SQL_BYTES = 8_192;
    private const int MAX_TOKENS = 512;
    private const int MAX_WHERE_TOKENS = 128;
    private const int MAX_WHERE_NESTING = 16;

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];
    private int $pos = 0;

    /**
     * Parse a SQL statement.
     *
     * @return array{columns: list<array{expr: string, alias: ?string}>, alias: string, where: ?array<string, mixed>}
     */
    public static function parse(string $sql): array
    {
        if (strlen($sql) > self::MAX_SQL_BYTES) {
            throw new SelectSqlException('S3 Select SQL expression is too long.');
        }

        $parser = new self();
        $parser->tokenize($sql);
        return $parser->parseSelect();
    }

    private function tokenize(string $sql): void
    {
        $this->tokens = [];
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace.
            if (ctype_space($sql[$i])) {
                $i++;
                continue;
            }

            // String literal.
            if ($sql[$i] === '\'') {
                $j = $i + 1;
                $value = '';
                while ($j < $len) {
                    if ($sql[$j] === '\'' && $j + 1 < $len && $sql[$j + 1] === '\'') {
                        $value .= '\'';
                        $j += 2;
                    } elseif ($sql[$j] === '\'') {
                        break;
                    } else {
                        $value .= $sql[$j];
                        $j++;
                    }
                }
                if ($j >= $len) {
                    throw new SelectSqlException('Unterminated S3 Select SQL string literal.');
                }
                $this->tokens[] = ['type' => 'STRING', 'value' => $value];
                $i = $j + 1;
                continue;
            }

            // Number (negative sign only treated as part of number after operator/keyword/start, not after value).
            $isNegativeNumber = $sql[$i] === '-' && $i + 1 < $len && ctype_digit($sql[$i + 1])
                && (empty($this->tokens) || in_array(end($this->tokens)['type'], ['OP', 'KEYWORD'], true));
            if (ctype_digit($sql[$i]) || $isNegativeNumber) {
                $j = $i;
                if ($sql[$j] === '-') {
                    $j++;
                }
                while ($j < $len && (ctype_digit($sql[$j]) || $sql[$j] === '.')) {
                    $j++;
                }
                $this->tokens[] = ['type' => 'NUMBER', 'value' => substr($sql, $i, $j - $i)];
                $i = $j;
                continue;
            }

            // Operators.
            $twoChar = substr($sql, $i, 2);
            if (in_array($twoChar, ['!=', '<>', '<=', '>='], true)) {
                $this->tokens[] = ['type' => 'OP', 'value' => $twoChar === '<>' ? '!=' : $twoChar];
                $i += 2;
                continue;
            }

            if (in_array($sql[$i], ['=', '<', '>', ',', '(', ')', '.', '*', '+', '-', '/'], true)) {
                $this->tokens[] = ['type' => 'OP', 'value' => $sql[$i]];
                $i++;
                continue;
            }

            // Identifier or keyword.
            if (ctype_alpha($sql[$i]) || $sql[$i] === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                    $j++;
                }
                $word = substr($sql, $i, $j - $i);
                $upper = strtoupper($word);

                $keywords = ['SELECT', 'FROM', 'WHERE', 'AS', 'AND', 'OR', 'NOT', 'IS', 'NULL',
                    'LIKE', 'BETWEEN', 'IN', 'CAST', 'LOWER', 'UPPER', 'TRIM', 'SUBSTRING',
                    'CHAR_LENGTH', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'TRUE', 'FALSE'];

                if (in_array($upper, $keywords, true)) {
                    $this->tokens[] = ['type' => 'KEYWORD', 'value' => $upper];
                } else {
                    $this->tokens[] = ['type' => 'IDENT', 'value' => $word];
                }
                $i = $j;
                continue;
            }

            throw new SelectSqlException('Unsupported character in S3 Select SQL expression.');
        }

        if (count($this->tokens) > self::MAX_TOKENS) {
            throw new SelectSqlException('S3 Select SQL expression has too many tokens.');
        }
    }

    /** @return array{columns: list<array{expr: string, alias: ?string}>, alias: string, where: ?array<string, mixed>} */
    private function parseSelect(): array
    {
        $this->expect('KEYWORD', 'SELECT');

        // Parse columns.
        $columns = $this->parseColumnList();

        $this->expect('KEYWORD', 'FROM');

        // Parse table (S3Object).
        $tableName = $this->consume('IDENT')['value'];
        if (strcasecmp($tableName, 's3object') !== 0) {
            throw new SelectSqlException('S3 Select SQL must query s3object.');
        }

        $alias = 's';
        if ($this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'AS') {
            $this->consume('KEYWORD');
            $alias = $this->consume('IDENT')['value'];
        } elseif ($this->peek()['type'] === 'IDENT') {
            $alias = $this->consume('IDENT')['value'];
        }

        $aggregate = null;
        foreach ($columns as $column) {
            $expr = $column['expr'];
            $isAggregate = preg_match('/^(?:COUNT|SUM|AVG|MIN|MAX)\(/', $expr) === 1;
            if ($aggregate !== null && $aggregate !== $isAggregate) {
                throw new SelectSqlException('S3 Select cannot mix aggregate and non-aggregate projections.');
            }
            $aggregate = $isAggregate;

            $field = $isAggregate ? substr($expr, strpos($expr, '(') + 1, -1) : $expr;
            if ($field === '*') {
                continue;
            }
            $parts = explode('.', $field);
            if (count($parts) > 1 && strcasecmp($parts[0], $alias) !== 0) {
                throw new SelectSqlException('S3 Select projection must use the table alias for qualified fields.');
            }
            if ($isAggregate && count($parts) > 2) {
                throw new SelectSqlException('S3 Select aggregate fields cannot use nested paths.');
            }
        }

        // Parse WHERE.
        $where = null;
        if ($this->pos < count($this->tokens) && $this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'WHERE') {
            $this->consume('KEYWORD');
            $where = $this->parseExpression();
            self::assertWhereQualifiers($where['tokens'], $alias);
        }

        if ($this->pos !== count($this->tokens)) {
            throw new SelectSqlException('Unexpected tokens after S3 Select SQL expression.');
        }

        return [
            'columns' => $columns,
            'alias' => $alias,
            'where' => $where,
        ];
    }

    /** @return list<array{expr: string, alias: ?string}> */
    private function parseColumnList(): array
    {
        $columns = [];

        if ($this->peek()['value'] === '*') {
            $this->consume('OP');
            return [['expr' => '*', 'alias' => null]];
        }

        while (true) {
            $expr = $this->parseColumnExpr();
            $alias = null;
            if ($this->pos < count($this->tokens) && $this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'AS') {
                $this->consume('KEYWORD');
                $alias = $this->consume('IDENT')['value'];
            }
            $columns[] = ['expr' => $expr, 'alias' => $alias];
            if ($this->pos >= count($this->tokens) || $this->peek()['value'] !== ',') {
                break;
            }
            $this->consume('OP');
        }

        return $columns;
    }

    private function parseColumnExpr(): string
    {
        $tokens = [];
        $depth = 0;
        while ($this->pos < count($this->tokens)) {
            $token = $this->peek();
            if ($token['value'] === ',' && $depth === 0) {
                break;
            }
            if ($token['type'] === 'KEYWORD' && in_array($token['value'], ['FROM', 'AS', 'WHERE'], true) && $depth === 0) {
                break;
            }
            if ($token['value'] === '(') {
                $depth++;
            }
            if ($token['value'] === ')') {
                if ($depth === 0) {
                    break;
                } $depth--;
            }
            $tokens[] = $this->tokens[$this->pos++];
        }

        if ($tokens !== [] && $tokens[0]['type'] === 'IDENT') {
            $field = self::parseProjectionField($tokens);
            if ($field !== null) {
                return $field;
            }
        }

        if (count($tokens) >= 4 && $tokens[0]['type'] === 'KEYWORD'
            && in_array($tokens[0]['value'], ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)
            && $tokens[1]['value'] === '(' && $tokens[array_key_last($tokens)]['value'] === ')') {
            $operand = array_slice($tokens, 2, -1);
            if ($tokens[0]['value'] === 'COUNT' && count($operand) === 1 && $operand[0]['value'] === '*') {
                return 'COUNT(*)';
            }
            $field = self::parseProjectionField($operand);
            if ($field !== null) {
                return $tokens[0]['value'] . '(' . $field . ')';
            }
        }

        throw new SelectSqlException('Unsupported S3 Select projection expression.');
    }

    /** @param list<array{type: string, value: string}> $tokens */
    private static function parseProjectionField(array $tokens): ?string
    {
        if ($tokens === [] || count($tokens) % 2 === 0) {
            return null;
        }

        $parts = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                if ($token['type'] !== 'IDENT') {
                    return null;
                }
                $parts[] = $token['value'];
            } elseif ($token['value'] !== '.') {
                return null;
            }
        }

        return implode('.', $parts);
    }

    /** @return array{tokens: list<array{type: string, value: string}>} */
    private function parseExpression(): array
    {
        // Simple expression parser — returns a structured array.
        $tokens = [];
        $depth = 0;
        while ($this->pos < count($this->tokens)) {
            $token = $this->tokens[$this->pos++];
            if ($token['value'] === '(') {
                self::assertWhereDepth(++$depth);
            } elseif ($token['value'] === ')' && --$depth < 0) {
                throw new SelectSqlException('Unbalanced parentheses in S3 Select WHERE expression.');
            }
            $tokens[] = $token;
            if (count($tokens) > self::MAX_WHERE_TOKENS) {
                throw new SelectSqlException('S3 Select WHERE expression has too many tokens.');
            }
        }
        if ($tokens === [] || $depth !== 0) {
            throw new SelectSqlException('Incomplete S3 Select WHERE expression.');
        }
        WhereSyntaxValidator::validate($tokens);

        return ['tokens' => $tokens];
    }

    /** @param list<array{type: string, value: string}> $tokens */
    private static function assertWhereQualifiers(array $tokens, string $alias): void
    {
        foreach ($tokens as $index => $token) {
            if ($token['type'] !== 'IDENT' || ($tokens[$index + 1]['value'] ?? null) !== '.') {
                continue;
            }
            if ($index > 0 && $tokens[$index - 1]['value'] === '.') {
                continue;
            }
            if (strcasecmp($token['value'], $alias) !== 0) {
                throw new SelectSqlException('S3 Select WHERE fields must use the declared table alias.');
            }
        }
    }

    private static function assertWhereDepth(int $depth): void
    {
        if ($depth > self::MAX_WHERE_NESTING) {
            throw new SelectSqlException('S3 Select WHERE nesting exceeds the supported limit.');
        }
    }

    /** @return array{type: string, value: string} */
    private function peek(): array
    {
        return $this->tokens[$this->pos] ?? ['type' => 'EOF', 'value' => ''];
    }

    /** @return array{type: string, value: string} */
    private function consume(string $type): array
    {
        $token = $this->peek();
        if ($token['type'] !== $type) {
            throw new \RuntimeException("Expected {$type}, got {$token['type']} ({$token['value']})");
        }
        $this->pos++;
        return $token;
    }

    private function expect(string $type, string $value): void
    {
        $token = $this->consume($type);
        if (strtoupper($token['value']) !== $value) {
            throw new \RuntimeException("Expected {$value}, got {$token['value']}");
        }
    }
}
