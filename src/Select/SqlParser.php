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
        $parser = new self;
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
                $this->tokens[] = ['type' => 'STRING', 'value' => $value];
                $i = $j + 1;
                continue;
            }

            // Number (negative sign only treated as part of number after operator/keyword/start, not after value).
            $isNegativeNumber = $sql[$i] === '-' && $i + 1 < $len && ctype_digit($sql[$i + 1])
                && (empty($this->tokens) || in_array(end($this->tokens)['type'], ['OP', 'KEYWORD'], true));
            if (ctype_digit($sql[$i]) || $isNegativeNumber) {
                $j = $i;
                if ($sql[$j] === '-') $j++;
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

            $i++;
        }
    }

    private function parseSelect(): array
    {
        $this->expect('KEYWORD', 'SELECT');

        // Parse columns.
        $columns = $this->parseColumnList();

        $this->expect('KEYWORD', 'FROM');

        // Parse table (S3Object).
        $tableName = $this->consume('IDENT')['value'] ?? 'S3Object';

        $alias = 's';
        if ($this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'AS') {
            $this->consume('KEYWORD');
            $alias = $this->consume('IDENT')['value'];
        } elseif ($this->peek()['type'] === 'IDENT') {
            $alias = $this->consume('IDENT')['value'];
        }

        // Parse WHERE.
        $where = null;
        if ($this->pos < count($this->tokens) && $this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'WHERE') {
            $this->consume('KEYWORD');
            $where = $this->parseExpression();
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

        do {
            $expr = $this->parseColumnExpr();
            $alias = null;
            if ($this->pos < count($this->tokens) && $this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'AS') {
                $this->consume('KEYWORD');
                $alias = $this->consume('IDENT')['value'];
            }
            $columns[] = ['expr' => $expr, 'alias' => $alias];
        } while ($this->pos < count($this->tokens) && $this->peek()['value'] === ',' && $this->consume('OP'));

        return $columns;
    }

    private function parseColumnExpr(): string
    {
        $parts = [];
        $depth = 0;
        while ($this->pos < count($this->tokens)) {
            $token = $this->peek();
            if ($token['value'] === ',' && $depth === 0) break;
            if ($token['type'] === 'KEYWORD' && in_array($token['value'], ['FROM', 'AS', 'WHERE'], true) && $depth === 0) break;
            if ($token['value'] === '(') $depth++;
            if ($token['value'] === ')') { if ($depth === 0) break; $depth--; }
            $parts[] = $this->tokens[$this->pos++]['value'];
        }
        return implode(' ', $parts);
    }

    private function parseExpression(): array
    {
        // Simple expression parser — returns a structured array.
        $tokens = [];
        while ($this->pos < count($this->tokens)) {
            $tokens[] = $this->tokens[$this->pos++];
        }
        return ['tokens' => $tokens];
    }

    private function peek(): array
    {
        return $this->tokens[$this->pos] ?? ['type' => 'EOF', 'value' => ''];
    }

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
