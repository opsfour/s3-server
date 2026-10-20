<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Select;

use OpsFour\S3Server\Select\ExpressionEvaluator;
use OpsFour\S3Server\Select\SelectSqlException;
use OpsFour\S3Server\Select\SqlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlAdmissionTest extends TestCase
{
    public function test_rejects_adversarial_or_chain_before_evaluation(): void
    {
        $sql = 'SELECT * FROM s3object WHERE ' . implode(' OR ', array_fill(0, 2_500, 'x=1'));

        $this->expectException(SelectSqlException::class);
        SqlParser::parse($sql);
    }

    public function test_rejects_deep_where_parentheses_before_evaluation(): void
    {
        $sql = 'SELECT * FROM s3object WHERE ' . str_repeat('(', 17) . 'x=1' . str_repeat(')', 17);

        $this->expectException(SelectSqlException::class);
        SqlParser::parse($sql);
    }

    #[DataProvider('invalidSql')]
    public function test_rejects_invalid_sql_during_parse(string $sql): void
    {
        $this->expectException(SelectSqlException::class);
        SqlParser::parse($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSql(): iterable
    {
        yield 'wrong table' => ['SELECT * FROM other'];
        yield 'trailing tokens' => ['SELECT * FROM s3object s extra'];
        yield 'empty where' => ['SELECT * FROM s3object WHERE'];
        yield 'unsupported character' => ['SELECT * FROM s3object WHERE x @ 1'];
        yield 'unterminated string' => ["SELECT * FROM s3object WHERE x = 'open"];
        yield 'scalar function projection' => ['SELECT LOWER(s.name) FROM s3object s'];
        yield 'cast inside aggregate' => ['SELECT SUM(CAST(s.amount AS FLOAT)) FROM s3object s'];
        yield 'unknown field qualifier' => ['SELECT other.name FROM s3object s'];
        yield 'mixed aggregate and field' => ['SELECT COUNT(*), s.name FROM s3object s'];
        yield 'nested aggregate field' => ['SELECT SUM(s.nested.amount) FROM s3object s'];
        yield 'malformed projection' => ['SELECT s.name + 1 FROM s3object s'];
    }

    public function test_qualified_projection_is_normalized_for_both_execution_paths(): void
    {
        $parsed = SqlParser::parse('SELECT s.name AS person, s.nested.age FROM s3object s');

        self::assertSame([
            ['expr' => 's.name', 'alias' => 'person'],
            ['expr' => 's.nested.age', 'alias' => null],
        ], $parsed['columns']);
        self::assertSame('SUM(s.amount)', SqlParser::parse('SELECT SUM(s.amount) FROM s3object s')['columns'][0]['expr']);
    }

    #[DataProvider('invalidWhere')]
    public function test_rejects_invalid_where_before_reading_rows(string $where): void
    {
        $this->expectException(SelectSqlException::class);
        SqlParser::parse('SELECT * FROM s3object WHERE ' . $where);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWhere(): iterable
    {
        yield 'bare identifier' => ['nonsense'];
        yield 'missing right operand' => ['x ='];
        yield 'missing left operand' => ['= 1'];
        yield 'trailing garbage' => ['x = 1 nonsense'];
        yield 'incomplete not' => ['NOT'];
        yield 'hidden OR branch' => ['x = 1 OR nonsense'];
        yield 'hidden AND branch' => ['x = 0 AND nonsense'];
        yield 'hidden parenthesized branch' => ['x IS NULL OR (nonsense)'];
        yield 'dangling NOT predicate' => ['x NOT = 1'];
        yield 'empty IN list' => ['x IN ()'];
        yield 'extra IN operand' => ['x IN (1 2)'];
        yield 'invalid IN number' => ['x IN (1.2.3)'];
        yield 'missing BETWEEN bound' => ['x BETWEEN 1 AND'];
        yield 'unsupported function' => ['COALESCE(x, y) = 1'];
        yield 'malformed function argument' => ['LOWER(x, y) = 1'];
        yield 'wrong field qualifier' => ['wrong.age = 1'];
    }

    public function test_small_boolean_where_still_evaluates_correctly(): void
    {
        $parsed = SqlParser::parse("SELECT * FROM s3object WHERE (x = '1' OR x = '2') AND y IS NOT NULL");

        self::assertTrue(ExpressionEvaluator::evaluate($parsed['where'], ['x' => '2', 'y' => 'ok']));
        self::assertFalse(ExpressionEvaluator::evaluate($parsed['where'], ['x' => '0', 'y' => 'ok']));
        self::assertFalse(ExpressionEvaluator::evaluate($parsed['where'], ['x' => '1', 'y' => null]));
    }

    #[DataProvider('nullComparisons')]
    public function test_missing_and_null_fields_do_not_match_sql_comparisons(string $where): void
    {
        $parsed = SqlParser::parse('SELECT * FROM s3object WHERE ' . $where);

        self::assertFalse(ExpressionEvaluator::evaluate($parsed['where'], []));
        self::assertFalse(ExpressionEvaluator::evaluate($parsed['where'], ['x' => null]));
    }

    /** @return iterable<string, array{string}> */
    public static function nullComparisons(): iterable
    {
        yield 'equals empty' => ["x = ''"];
        yield 'not equals empty' => ["x != ''"];
        yield 'less than' => ['x < 1'];
        yield 'greater than' => ['x > 1'];
        yield 'in empty' => ["x IN ('')"];
        yield 'not in empty' => ["x NOT IN ('')"];
        yield 'between' => ['x BETWEEN 0 AND 1'];
        yield 'not between' => ['x NOT BETWEEN 0 AND 1'];
        yield 'like' => ["x LIKE '%'"];
        yield 'not like' => ["x NOT LIKE '%'"];
        yield 'negated comparison' => ["NOT (x = '')"];
    }

    public function test_sql_null_boolean_logic_and_is_null(): void
    {
        $isNull = SqlParser::parse('SELECT * FROM s3object WHERE x IS NULL');
        self::assertTrue(ExpressionEvaluator::evaluate($isNull['where'], []));

        $unknownOrTrue = SqlParser::parse('SELECT * FROM s3object WHERE x = 1 OR y = 2');
        self::assertTrue(ExpressionEvaluator::evaluate($unknownOrTrue['where'], ['y' => '2']));

        $unknownAndTrue = SqlParser::parse('SELECT * FROM s3object WHERE x = 1 AND y = 2');
        self::assertFalse(ExpressionEvaluator::evaluate($unknownAndTrue['where'], ['y' => '2']));

        $notInNullList = SqlParser::parse('SELECT * FROM s3object WHERE y NOT IN (1, NULL)');
        self::assertFalse(ExpressionEvaluator::evaluate($notInNullList['where'], ['y' => '2']));
    }

    public function test_utf8_scalar_functions_use_character_semantics(): void
    {
        $substring = SqlParser::parse("SELECT * FROM s3object s WHERE SUBSTRING(s.name, 2, 1) = 'ö'");
        self::assertTrue(ExpressionEvaluator::evaluate($substring['where'], ['name' => 'Jörg'], $substring['alias']));

        $case = SqlParser::parse("SELECT * FROM s3object s WHERE LOWER(s.name) = 'ärger'");
        self::assertTrue(ExpressionEvaluator::evaluate($case['where'], ['name' => 'ÄRGER'], $case['alias']));
    }

    public function test_empty_aggregates_preserve_sql_null(): void
    {
        self::assertSame(
            [['total' => null, 'count' => 0]],
            ExpressionEvaluator::computeAggregates(
                [
                    ['expr' => 'SUM(s.amount)', 'alias' => 'total'],
                    ['expr' => 'COUNT(s.amount)', 'alias' => 'count'],
                ],
                [],
                's',
            ),
        );
    }

    public function test_scalar_functions_preserve_null_and_unknown_casts_fail_closed(): void
    {
        $nullFunction = SqlParser::parse("SELECT * FROM s3object s WHERE LOWER(s.missing) = ''");
        self::assertFalse(ExpressionEvaluator::evaluate($nullFunction['where'], [], $nullFunction['alias']));

        $this->expectException(SelectSqlException::class);
        SqlParser::parse('SELECT * FROM s3object s WHERE CAST(s.value AS BOOL) = TRUE');
    }

    #[DataProvider('validWhere')]
    public function test_supported_where_grammar_remains_valid(string $where): void
    {
        $parsed = SqlParser::parse('SELECT * FROM s3object s WHERE ' . $where);

        self::assertNotNull($parsed['where']);
    }

    /** @return iterable<string, array{string}> */
    public static function validWhere(): iterable
    {
        yield 'like' => ["s.name NOT LIKE 'A%'"];
        yield 'between' => ['s.age BETWEEN 10 AND 20 OR s.age IS NULL'];
        yield 'in' => ["s.category IN ('A', 'B', NULL)"];
        yield 'nested functions' => ["LOWER(TRIM(s.name)) = 'ada'"];
        yield 'cast' => ['CAST(s.age AS INT) >= 30'];
        yield 'substring' => ["SUBSTRING(s.name, 1, 2) = 'Ad'"];
        yield 'nested boolean' => ["NOT (s.age < 18 OR s.status IS NOT NULL) AND s.age != 0"];
    }
}
