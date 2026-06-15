<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use OpsFour\S3Server\Parallel\SelectProcessingTask;
use OpsFour\S3Server\Select\CsvProcessor;
use OpsFour\S3Server\Select\EventStreamEncoder;
use OpsFour\S3Server\Select\ExpressionEvaluator;
use OpsFour\S3Server\Select\SqlParser;
use PHPUnit\Framework\TestCase;

final class SelectProcessingTaskTest extends TestCase
{
    public function test_csv_select_star(): void
    {
        $csv = "name,age\nAlice,30\nBob,25\n";
        $expression = "SELECT * FROM s3object";
        $inputSerialization = ['format' => 'CSV', 'fileHeaderInfo' => 'USE'];
        $outputSerialization = ['format' => 'CSV'];

        $task = new SelectProcessingTask($csv, $expression, $inputSerialization, $outputSerialization);

        // Run directly (simulates what the worker does).
        $channel = $this->createMock(\Amp\Sync\Channel::class);
        $cancellation = new \Amp\NullCancellation();

        $result = $task->run($channel, $cancellation);

        $this->assertArrayHasKey('eventStream', $result);
        $this->assertArrayHasKey('bytesScanned', $result);
        $this->assertArrayHasKey('bytesReturned', $result);
        $this->assertSame(strlen($csv), $result['bytesScanned']);
        $this->assertGreaterThan(0, $result['bytesReturned']);
    }

    public function test_csv_select_with_where(): void
    {
        $csv = "name,age\nAlice,30\nBob,25\nCharlie,35\n";
        $expression = "SELECT * FROM s3object WHERE age > 28";
        $inputSerialization = ['format' => 'CSV', 'fileHeaderInfo' => 'USE'];
        $outputSerialization = ['format' => 'CSV'];

        $task = new SelectProcessingTask($csv, $expression, $inputSerialization, $outputSerialization);

        $channel = $this->createMock(\Amp\Sync\Channel::class);
        $cancellation = new \Amp\NullCancellation();

        $result = $task->run($channel, $cancellation);

        // Only Alice (30) and Charlie (35) should match.
        $this->assertArrayHasKey('bytesReturned', $result);
        $this->assertGreaterThan(0, $result['bytesReturned']);
    }

    public function test_json_select(): void
    {
        $json = "{\"name\":\"Alice\",\"age\":30}\n{\"name\":\"Bob\",\"age\":25}\n";
        $expression = "SELECT * FROM s3object s";
        $inputSerialization = ['format' => 'JSON', 'jsonType' => 'LINES'];
        $outputSerialization = ['format' => 'JSON'];

        $task = new SelectProcessingTask($json, $expression, $inputSerialization, $outputSerialization);

        $channel = $this->createMock(\Amp\Sync\Channel::class);
        $cancellation = new \Amp\NullCancellation();

        $result = $task->run($channel, $cancellation);

        $this->assertArrayHasKey('eventStream', $result);
        $this->assertSame(strlen($json), $result['bytesScanned']);
    }

    public function test_output_matches_inline_processing(): void
    {
        $csv = "name,age\nAlice,30\nBob,25\n";
        $expression = "SELECT * FROM s3object";
        $inputSerialization = ['format' => 'CSV', 'fileHeaderInfo' => 'USE'];
        $outputSerialization = ['format' => 'CSV'];

        // Process via task.
        $task = new SelectProcessingTask($csv, $expression, $inputSerialization, $outputSerialization);
        $channel = $this->createMock(\Amp\Sync\Channel::class);
        $cancellation = new \Amp\NullCancellation();
        $taskResult = $task->run($channel, $cancellation);

        // Process inline (same logic as SelectObjectContentHandler).
        $parsed = SqlParser::parse($expression);
        $rows = CsvProcessor::parse($csv, ',', "\n", '"', 'USE')['rows'];
        $filtered = array_values(array_filter(
            $rows,
            fn(array $row)
            => ExpressionEvaluator::evaluate($parsed['where'], $row, $parsed['alias']),
        ));

        $outputData = CsvProcessor::format($filtered, null, ',', "\n", '"');
        $inlineEventStream = EventStreamEncoder::encode(
            $outputData,
            strlen($csv),
            strlen($csv),
            strlen($outputData),
        );

        $this->assertSame($inlineEventStream, $taskResult['eventStream']);
    }
}
