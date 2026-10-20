<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/** Validates the supported WHERE grammar before any object rows are read. */
final class WhereSyntaxValidator
{
    /** @param list<array{type: string, value: string}> $tokens */
    private function __construct(private readonly array $tokens) {}

    private int $position = 0;

    /** @param list<array{type: string, value: string}> $tokens */
    public static function validate(array $tokens): void
    {
        $validator = new self($tokens);
        $validator->parseOr();
        if ($validator->position !== count($tokens)) {
            throw new SelectSqlException('Unsupported S3 Select WHERE expression.');
        }
    }

    private function parseOr(): void
    {
        $this->parseAnd();
        while ($this->accept('OR')) {
            $this->parseAnd();
        }
    }

    private function parseAnd(): void
    {
        $this->parseNot();
        while ($this->accept('AND')) {
            $this->parseNot();
        }
    }

    private function parseNot(): void
    {
        if ($this->accept('NOT')) {
            $this->parseNot();

            return;
        }

        if ($this->accept('(')) {
            $this->parseOr();
            $this->expect(')');

            return;
        }

        $this->parsePredicate();
    }

    private function parsePredicate(): void
    {
        $this->parseOperand();

        if ($this->accept('IS')) {
            $this->accept('NOT');
            $this->expect('NULL');

            return;
        }

        $negated = $this->accept('NOT');
        if ($this->accept('LIKE')) {
            $this->parseOperand();

            return;
        }
        if ($this->accept('BETWEEN')) {
            $this->parseOperand();
            $this->expect('AND');
            $this->parseOperand();

            return;
        }
        if ($this->accept('IN')) {
            $this->expect('(');
            $this->parseInValue();
            while ($this->accept(',')) {
                $this->parseInValue();
            }
            $this->expect(')');

            return;
        }

        if (! $negated && in_array($this->current()['value'], ['=', '!=', '<', '>', '<=', '>='], true)) {
            ++$this->position;
            $this->parseOperand();

            return;
        }

        throw new SelectSqlException('Unsupported S3 Select WHERE predicate.');
    }

    private function parseInValue(): void
    {
        $token = $this->current();
        if (in_array($token['type'], ['STRING', 'NUMBER'], true) || $token['value'] === 'NULL') {
            if ($token['type'] === 'NUMBER' && ! is_numeric($token['value'])) {
                throw new SelectSqlException('Invalid S3 Select SQL number.');
            }
            ++$this->position;

            return;
        }

        throw new SelectSqlException('Unsupported S3 Select IN value.');
    }

    private function parseOperand(): void
    {
        $token = $this->current();
        if (in_array($token['type'], ['STRING', 'NUMBER'], true)) {
            if ($token['type'] === 'NUMBER' && ! is_numeric($token['value'])) {
                throw new SelectSqlException('Invalid S3 Select SQL number.');
            }
            ++$this->position;

            return;
        }
        if (in_array($token['value'], ['NULL', 'TRUE', 'FALSE'], true)) {
            ++$this->position;

            return;
        }
        if (in_array($token['value'], ['LOWER', 'UPPER', 'TRIM', 'CHAR_LENGTH', 'SUBSTRING', 'CAST'], true)) {
            $this->parseFunction($token['value']);

            return;
        }
        if ($token['type'] === 'IDENT') {
            ++$this->position;
            while ($this->accept('.')) {
                if ($this->current()['type'] !== 'IDENT') {
                    throw new SelectSqlException('Invalid S3 Select field path.');
                }
                ++$this->position;
            }

            return;
        }

        throw new SelectSqlException('Invalid S3 Select SQL operand.');
    }

    private function parseFunction(string $name): void
    {
        ++$this->position;
        $this->expect('(');
        $this->parseOperand();

        if ($name === 'SUBSTRING') {
            $this->expect(',');
            $this->parseOperand();
            if ($this->accept(',')) {
                $this->parseOperand();
            }
        } elseif ($name === 'CAST') {
            $this->expect('AS');
            if ($this->current()['type'] !== 'IDENT'
                || ! in_array(strtoupper($this->current()['value']), ['INT', 'INTEGER', 'FLOAT', 'DOUBLE', 'DECIMAL', 'VARCHAR', 'STRING', 'CHAR'], true)) {
                throw new SelectSqlException('Unsupported S3 Select CAST type.');
            }
            ++$this->position;
        }

        $this->expect(')');
    }

    private function accept(string $value): bool
    {
        if ($this->current()['value'] !== $value) {
            return false;
        }
        ++$this->position;

        return true;
    }

    private function expect(string $value): void
    {
        if (! $this->accept($value)) {
            throw new SelectSqlException('Incomplete S3 Select WHERE expression.');
        }
    }

    /** @return array{type: string, value: string} */
    private function current(): array
    {
        return $this->tokens[$this->position] ?? ['type' => 'EOF', 'value' => ''];
    }
}
