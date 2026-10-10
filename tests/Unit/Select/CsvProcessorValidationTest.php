<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Select;

use OpsFour\S3Server\Select\CsvProcessor;
use OpsFour\S3Server\Select\SelectMemoryLimitException;
use OpsFour\S3Server\Select\SelectRecordSizeException;
use PHPUnit\Framework\TestCase;

final class CsvProcessorValidationTest extends TestCase
{
    public function test_empty_record_delimiter_is_rejected_before_csv_loop(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvProcessor::parse("name\nAda\n", ',', '', '"', 'USE');
    }

    public function test_empty_field_delimiter_is_rejected_before_php_csv_parser(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvProcessor::parse("name\nAda\n", '', "\n", '"', 'USE');
    }

    public function test_record_delimiter_inside_quoted_field_does_not_split_a_row(): void
    {
        $parsed = CsvProcessor::parse("name,note\r\nAda,\"first\r\nsecond\"\r\nBob,done\r\n", ',', "\r\n", '"', 'USE');

        self::assertSame([
            ['name' => 'Ada', 'note' => "first\r\nsecond"],
            ['name' => 'Bob', 'note' => 'done'],
        ], $parsed['rows']);
    }

    public function test_doubled_quotes_and_custom_record_delimiter_round_trip(): void
    {
        $parsed = CsvProcessor::parse('name|note~~Ada|"a""b~~c"~~', '|', '~~', '"', 'USE');

        self::assertSame([['name' => 'Ada', 'note' => 'a"b~~c']], $parsed['rows']);
    }

    public function test_unterminated_quoted_field_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvProcessor::parse("name,note\nAda,\"open\n", ',', "\n", '"', 'USE');
    }

    public function test_characters_after_a_closing_quote_are_rejected(): void
    {
        foreach ([
            ["name,note\nAda,\"closed\"junk\n", ',', "\n"],
            ["name,note\r\nAda,\"closed\" junk\r\n", ',', "\r\n"],
            ['name|note~~Ada|"closed"junk~~', '|', '~~'],
        ] as [$csv, $fieldDelimiter, $recordDelimiter]) {
            try {
                CsvProcessor::parse($csv, $fieldDelimiter, $recordDelimiter, '"', 'USE');
                self::fail('Malformed CSV characters after a closing quote were accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('after a closing quote', $error->getMessage());
            }
        }
    }

    public function test_ambiguous_quote_and_field_delimiters_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvProcessor::parse('a|b', '|', "\n", '|');
    }

    public function test_record_delimiter_starting_with_field_delimiter_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvProcessor::parse('a|b||c|d||', '|', '||');
    }

    public function test_csv_output_converts_json_scalars_without_type_error(): void
    {
        $csv = CsvProcessor::format([['active' => false, 'count' => 2, 'missing' => null]]);

        self::assertSame("false,2,\n", $csv);
    }

    public function test_csv_output_rejects_nested_json_instead_of_stringifying_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvProcessor::format([['nested' => (object) ['x' => 1]]]);
    }

    public function test_csv_input_accepts_boundary_record_and_rejects_one_extra_byte(): void
    {
        $boundary = str_repeat('x', SelectRecordSizeException::MAX_RECORD_BYTES);
        self::assertSame($boundary, CsvProcessor::parse($boundary . "\n")['rows'][0]['_1']);

        $this->expectException(SelectRecordSizeException::class);
        CsvProcessor::parse($boundary . "x\n");
    }

    public function test_csv_output_rejects_oversized_serialized_record(): void
    {
        $this->expectException(SelectRecordSizeException::class);

        CsvProcessor::format([['first' => str_repeat('x', 600_000), 'second' => str_repeat('y', 500_000)]]);
    }

    public function test_csv_field_budget_counts_only_unquoted_delimiters(): void
    {
        $quoted = '"' . str_repeat(',', 20_000) . '"';
        self::assertSame(str_repeat(',', 20_000), CsvProcessor::parse($quoted . "\n")['rows'][0]['_1']);

        $this->expectException(SelectMemoryLimitException::class);
        CsvProcessor::parse(str_repeat(',', 20_000) . "\n");
    }
}
