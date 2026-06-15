<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Select\CsvProcessor;
use OpsFour\S3Server\Select\EventStreamEncoder;
use OpsFour\S3Server\Select\ExpressionEvaluator;
use OpsFour\S3Server\Select\JsonProcessor;
use OpsFour\S3Server\Select\SqlParser;

/**
 * Worker task for executing S3 Select queries in a child process.
 *
 * Runs the full pipeline: SQL parse -> input parse -> filter -> project -> format -> event stream encode.
 *
 * @implements Task<array{eventStream: string, bytesScanned: int, bytesReturned: int}, never, never>
 */
final class SelectProcessingTask implements Task
{
    /**
     * @param  string  $objectData  The raw object data.
     * @param  string  $expression  The SQL expression.
     * @param  array<string, mixed>  $inputSerialization  Input format config.
     * @param  array<string, mixed>  $outputSerialization  Output format config.
     */
    public function __construct(
        private readonly string $objectData,
        private readonly string $expression,
        private readonly array $inputSerialization,
        private readonly array $outputSerialization,
    ) {}

    /**
     * @return array{eventStream: string, bytesScanned: int, bytesReturned: int}
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $bytesScanned = strlen($this->objectData);

        // Parse SQL.
        $parsed = SqlParser::parse($this->expression);

        // Parse input data.
        $inputFormat = $this->inputSerialization['format'] ?? 'CSV';
        $rows = match ($inputFormat) {
            'CSV' => CsvProcessor::parse(
                $this->objectData,
                $this->inputSerialization['fieldDelimiter'] ?? ',',
                $this->inputSerialization['recordDelimiter'] ?? "\n",
                $this->inputSerialization['quoteCharacter'] ?? '"',
                $this->inputSerialization['fileHeaderInfo'] ?? 'NONE',
            )['rows'],
            'JSON' => JsonProcessor::parse(
                $this->objectData,
                $this->inputSerialization['jsonType'] ?? 'LINES',
            ),
            default => throw new \RuntimeException("Unsupported input format: {$inputFormat}"),
        };

        // Apply WHERE filter.
        $filtered = array_filter(
            $rows,
            fn(array $row)
            => ExpressionEvaluator::evaluate($parsed['where'], $row, $parsed['alias']),
        );
        $filtered = array_values($filtered);

        // Project columns.
        if (ExpressionEvaluator::isAggregate($parsed['columns'])) {
            $projected = ExpressionEvaluator::computeAggregates($parsed['columns'], $filtered, $parsed['alias']);
        } else {
            $projected = self::projectColumns($filtered, $parsed['columns'], $parsed['alias']);
        }

        // Format output.
        $outputFormat = $this->outputSerialization['format'] ?? 'CSV';
        $outputData = match ($outputFormat) {
            'CSV' => CsvProcessor::format(
                $projected,
                null,
                $this->outputSerialization['fieldDelimiter'] ?? ',',
                $this->outputSerialization['recordDelimiter'] ?? "\n",
                $this->outputSerialization['quoteCharacter'] ?? '"',
            ),
            'JSON' => JsonProcessor::format($projected),
            default => throw new \RuntimeException("Unsupported output format: {$outputFormat}"),
        };

        $bytesProcessed = $bytesScanned; // No decompression; processed = scanned.
        $bytesReturned = strlen($outputData);

        // Encode as event stream.
        $eventStream = EventStreamEncoder::encode(
            $outputData,
            $bytesScanned,
            $bytesProcessed,
            $bytesReturned,
        );

        return [
            'eventStream' => $eventStream,
            'bytesScanned' => $bytesScanned,
            'bytesReturned' => $bytesReturned,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{expr: string, alias: ?string}>  $columns
     * @return list<array<string, mixed>>
     */
    private static function projectColumns(array $rows, array $columns, string $alias): array
    {
        if (count($columns) === 1 && $columns[0]['expr'] === '*') {
            return $rows;
        }

        $result = [];
        foreach ($rows as $row) {
            $projected = [];
            foreach ($columns as $col) {
                $expr = $col['expr'];
                $key = $col['alias'] ?? $expr;

                if (str_contains($expr, '.')) {
                    $parts = explode('.', $expr, 2);
                    $fieldName = $parts[1] ?? $parts[0];
                    $projected[$key] = JsonProcessor::resolvePath($row, $fieldName) ?? ($row[$fieldName] ?? '');
                } else {
                    $projected[$key] = $row[$expr] ?? '';
                }
            }
            $result[] = $projected;
        }

        return $result;
    }
}
