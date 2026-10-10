<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Select;

use OpsFour\S3Server\Select\JsonProcessor;
use Symfony\Component\Process\Process;
use OpsFour\S3Server\Select\SelectRecordSizeException;
use PHPUnit\Framework\TestCase;

final class JsonProcessorValidationTest extends TestCase
{
    public function test_dense_json_document_is_rejected_before_decoder_can_exhaust_memory(): void
    {
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            dirname(__DIR__, 2) . '/Support/select-dense-json-worker.php',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('rejected', $process->getOutput());
    }

    public function test_document_structure_budget_ignores_punctuation_inside_strings(): void
    {
        $value = str_repeat('{},:[]', 10_000);
        self::assertSame([['text' => $value]], JsonProcessor::parse(
            json_encode(['text' => $value], JSON_THROW_ON_ERROR),
            'DOCUMENT',
        ));
    }

    public function test_empty_object_is_a_record_in_json_lines_and_document_arrays(): void
    {
        self::assertSame([[], ['name' => 'Ada']], JsonProcessor::parse("{}\n{\"name\":\"Ada\"}\n", 'LINES'));
        self::assertSame([[], ['name' => 'Ada']], JsonProcessor::parse('[{}, {"name":"Ada"}]', 'DOCUMENT'));
        self::assertSame("{}\n{\"name\":\"Ada\"}\n", JsonProcessor::format([[], ['name' => 'Ada']]));
    }

    public function test_json_lines_rejects_scalar_or_array_rows(): void
    {
        foreach (["42\n", "[]\n", "{\"name\":\"Ada\"}\ntrue\n"] as $data) {
            try {
                JsonProcessor::parse($data, 'LINES');
                self::fail('Expected non-object JSON line to be rejected.');
            } catch (\JsonException $e) {
                self::assertStringContainsString('must contain objects', $e->getMessage());
            }
        }
    }

    public function test_json_document_rejects_scalar_root_and_mixed_arrays(): void
    {
        foreach (['null', '42', '[{"name":"Ada"}, 3]', '[[], {}]'] as $data) {
            try {
                JsonProcessor::parse($data, 'DOCUMENT');
                self::fail('Expected invalid JSON document shape to be rejected.');
            } catch (\JsonException $e) {
                self::assertStringContainsString('must contain', $e->getMessage());
            }
        }
    }

    public function test_nested_json_object_fields_remain_queryable_and_keep_object_shape(): void
    {
        $rows = JsonProcessor::parse('{"user":{"name":"Ada"},"empty":{}}', 'DOCUMENT');

        self::assertSame('Ada', JsonProcessor::resolvePath($rows[0], 'user.name'));
        self::assertSame('{"user":{"name":"Ada"},"empty":{}}' . "\n", JsonProcessor::format($rows));
    }

    public function test_json_lines_accepts_boundary_record_and_rejects_one_extra_byte(): void
    {
        $record = '{"v":"' . str_repeat('x', SelectRecordSizeException::MAX_RECORD_BYTES - 8) . '"}';
        self::assertSame(SelectRecordSizeException::MAX_RECORD_BYTES, strlen($record));
        self::assertCount(1, JsonProcessor::parse($record . "\n", 'LINES'));

        $this->expectException(SelectRecordSizeException::class);
        JsonProcessor::parse(substr_replace($record, 'x', -2, 0) . "\n", 'LINES');
    }

    public function test_json_lines_trims_each_segment_without_copying_large_padding(): void
    {
        $data = "  {}\r\n" . str_repeat(' ', SelectRecordSizeException::MAX_RECORD_BYTES + 1) . '{"v":1}';

        self::assertSame([[], ['v' => 1]], JsonProcessor::parse($data, 'LINES'));
    }

    public function test_json_document_array_rejects_oversized_member(): void
    {
        $record = '{"v":"' . str_repeat('x', SelectRecordSizeException::MAX_RECORD_BYTES) . '"}';
        $this->expectException(SelectRecordSizeException::class);

        JsonProcessor::parse('[' . $record . ',{}]', 'DOCUMENT');
    }

    public function test_json_output_rejects_oversized_serialized_record(): void
    {
        $this->expectException(SelectRecordSizeException::class);

        JsonProcessor::format([['value' => str_repeat('x', SelectRecordSizeException::MAX_RECORD_BYTES)]]);
    }
}
