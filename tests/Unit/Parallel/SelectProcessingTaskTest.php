<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use Aws\Api\Parser\DecodingEventStreamIterator;
use GuzzleHttp\Psr7\Utils;
use OpsFour\S3Server\Parallel\SelectProcessingTask;
use OpsFour\S3Server\Select\CsvProcessor;
use OpsFour\S3Server\Select\EventStreamEncoder;
use OpsFour\S3Server\Select\ExpressionEvaluator;
use OpsFour\S3Server\Select\SqlParser;
use OpsFour\S3Server\Select\SelectSqlException;
use PHPUnit\Framework\TestCase;

final class SelectProcessingTaskTest extends TestCase
{
    public function test_invalid_sql_uses_a_distinct_worker_error_type(): void
    {
        $task = new SelectProcessingTask('name,Ada', 'NOT SQL', ['format' => 'CSV'], ['format' => 'CSV']);

        $this->expectException(SelectSqlException::class);
        $task->run($this->createMock(\Amp\Sync\Channel::class), new \Amp\NullCancellation());
    }

    public function test_worker_rejects_adversarial_where_before_processing_rows(): void
    {
        $expression = 'SELECT * FROM s3object WHERE ' . implode(' OR ', array_fill(0, 2_500, '_1=1'));
        $task = new SelectProcessingTask("0\n", $expression, ['format' => 'CSV'], ['format' => 'CSV']);

        $this->expectException(SelectSqlException::class);
        $task->run($this->createMock(\Amp\Sync\Channel::class), new \Amp\NullCancellation());
    }

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

    public function test_qualified_projection_returns_the_selected_fields(): void
    {
        $task = new SelectProcessingTask(
            "name,age\nAda,37\n",
            'SELECT s.name AS person, s.age FROM s3object s',
            ['format' => 'CSV', 'fileHeaderInfo' => 'USE'],
            ['format' => 'CSV'],
        );

        $result = $task->run($this->createMock(\Amp\Sync\Channel::class), new \Amp\NullCancellation());
        $records = '';
        foreach (new DecodingEventStreamIterator(Utils::streamFor($result['eventStream'])) as $event) {
            if ($event['headers'][':event-type'] === 'Records') {
                $records .= (string) $event['payload'];
            }
        }

        self::assertSame("Ada,37\n", $records);
    }

    public function test_aggregate_qualified_field_is_evaluated(): void
    {
        $task = new SelectProcessingTask(
            "amount\n2\n3\n",
            'SELECT SUM(s.amount) AS total FROM s3object s',
            ['format' => 'CSV', 'fileHeaderInfo' => 'USE'],
            ['format' => 'CSV'],
        );

        $result = $task->run($this->createMock(\Amp\Sync\Channel::class), new \Amp\NullCancellation());
        $records = '';
        foreach (new DecodingEventStreamIterator(Utils::streamFor($result['eventStream'])) as $event) {
            if ($event['headers'][':event-type'] === 'Records') {
                $records .= (string) $event['payload'];
            }
        }

        self::assertSame("5\n", $records);
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
