<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\ByteStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\SelectProcessingTask;
use OpsFour\S3Server\Select\CsvProcessor;
use OpsFour\S3Server\Select\EventStreamEncoder;
use OpsFour\S3Server\Select\ExpressionEvaluator;
use OpsFour\S3Server\Select\JsonProcessor;
use OpsFour\S3Server\Select\SqlParser;
use OpsFour\S3Server\Storage\StorageBackend;

/**
 * Handles SelectObjectContent (POST /{bucket}/{key}?select&select-type=2).
 *
 * Implements S3 Select: SQL queries over CSV/JSON objects.
 * When a WorkerPool is provided, processing is offloaded to a worker process.
 */
final class SelectObjectContentHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly int $maxSelectObjectSize = 268_435_456,
        private readonly ?WorkerPool $workerPool = null,
        private readonly ?MetricsCollector $metrics = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);
        if ($objectInfo === null) {
            throw new NoSuchKeyException;
        }

        // Size guard: S3 Select buffers the entire object.
        if ($objectInfo->size > $this->maxSelectObjectSize) {
            throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                'S3 Select is limited to objects under ' . $this->maxSelectObjectSize . ' bytes.',
            );
        }

        // Parse request XML.
        $body = ByteStream\buffer($request->getBody());
        $config = self::parseSelectRequest($body);

        // Fetch object data.
        $storagePath = $objectInfo->systemMetadata['storagePath'] ?? null;
        if ($storagePath === null || $storagePath === '') {
            throw new NoSuchKeyException;
        }

        $objectData = ByteStream\buffer($this->storage->getObjectByPath($storagePath));

        // Offload to worker if pool is available.
        if ($this->workerPool !== null) {
            return $this->processInWorker($objectData, $config);
        }

        return $this->processInline($objectData, $config);
    }

    private function processInWorker(string $objectData, array $config): Response
    {
        $task = new SelectProcessingTask(
            $objectData,
            $config['expression'],
            $config['inputSerialization'],
            $config['outputSerialization'],
        );

        try {
            $result = $this->workerPool->submit($task)->await();
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent');
        } catch (TaskFailureThrowable $e) {
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e->getOriginalClassName());
            if (str_contains($e->getOriginalClassName(), 'RuntimeException')) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                    'Invalid SQL expression: ' . $e->getOriginalMessage(),
                );
            }

            throw new \RuntimeException($e->getOriginalMessage(), 0, $e);
        } catch (\Throwable $e) {
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e::class);
            throw $e;
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/octet-stream'],
            body: $result['eventStream'],
        );
    }

    private function processInline(string $objectData, array $config): Response
    {
        $bytesScanned = strlen($objectData);

        // Parse the SQL.
        try {
            $parsed = SqlParser::parse($config['expression']);
        } catch (\RuntimeException $e) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid SQL expression: ' . $e->getMessage(),
            );
        }

        // Parse input data.
        $inputFormat = $config['inputSerialization']['format'] ?? 'CSV';
        $rows = match ($inputFormat) {
            'CSV' => CsvProcessor::parse(
                $objectData,
                $config['inputSerialization']['fieldDelimiter'] ?? ',',
                $config['inputSerialization']['recordDelimiter'] ?? "\n",
                $config['inputSerialization']['quoteCharacter'] ?? '"',
                $config['inputSerialization']['fileHeaderInfo'] ?? 'NONE',
            )['rows'],
            'JSON' => JsonProcessor::parse(
                $objectData,
                $config['inputSerialization']['jsonType'] ?? 'LINES',
            ),
            default => throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                "Unsupported input format: {$inputFormat}",
            ),
        };

        // Free raw object data — rows have been parsed.
        unset($objectData);

        // Apply WHERE filter.
        $filtered = array_filter($rows, fn (array $row) =>
            ExpressionEvaluator::evaluate($parsed['where'], $row, $parsed['alias'])
        );
        $filtered = array_values($filtered);

        // Free unfiltered rows.
        unset($rows);

        // Project columns — aggregate queries produce a single result row.
        if (ExpressionEvaluator::isAggregate($parsed['columns'])) {
            $projected = ExpressionEvaluator::computeAggregates($parsed['columns'], $filtered, $parsed['alias']);
        } else {
            $projected = self::projectColumns($filtered, $parsed['columns'], $parsed['alias']);
        }

        // Free filtered rows.
        unset($filtered);

        // Format output.
        $outputFormat = $config['outputSerialization']['format'] ?? 'CSV';
        $outputData = match ($outputFormat) {
            'CSV' => CsvProcessor::format(
                $projected,
                null,
                $config['outputSerialization']['fieldDelimiter'] ?? ',',
                $config['outputSerialization']['recordDelimiter'] ?? "\n",
                $config['outputSerialization']['quoteCharacter'] ?? '"',
            ),
            'JSON' => JsonProcessor::format($projected),
            default => throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                "Unsupported output format: {$outputFormat}",
            ),
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

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/octet-stream'],
            body: $eventStream,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{expr: string, alias: ?string}> $columns
     * @return list<array<string, mixed>>
     */
    private static function projectColumns(array $rows, array $columns, string $alias): array
    {
        // SELECT * — return all columns.
        if (count($columns) === 1 && $columns[0]['expr'] === '*') {
            return $rows;
        }

        $result = [];
        foreach ($rows as $row) {
            $projected = [];
            foreach ($columns as $col) {
                $expr = $col['expr'];
                $key = $col['alias'] ?? $expr;

                // Handle alias.field notation.
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

    /**
     * Parse SelectObjectContent request XML.
     *
     * @return array{expression: string, inputSerialization: array<string, mixed>, outputSerialization: array<string, mixed>}
     */
    private static function parseSelectRequest(string $xml): array
    {
        try {
            $xml = preg_replace('/<!DOCTYPE[^[>]*(?:\[[^\]]*\])?[^>]*>/i', '', $xml);
            $element = new \SimpleXMLElement($xml, LIBXML_NONET);
        } catch (\Exception) {
            throw new \OpsFour\S3Server\Exception\MalformedXmlException('Invalid SelectObjectContent request XML.');
        }

        $expression = (string) ($element->Expression ?? '');

        $inputSerialization = ['format' => 'CSV'];
        if (isset($element->InputSerialization)) {
            if (isset($element->InputSerialization->CSV)) {
                $csv = $element->InputSerialization->CSV;
                $inputSerialization['format'] = 'CSV';
                if (isset($csv->FileHeaderInfo)) $inputSerialization['fileHeaderInfo'] = (string) $csv->FileHeaderInfo;
                if (isset($csv->FieldDelimiter)) $inputSerialization['fieldDelimiter'] = (string) $csv->FieldDelimiter;
                if (isset($csv->RecordDelimiter)) $inputSerialization['recordDelimiter'] = (string) $csv->RecordDelimiter;
                if (isset($csv->QuoteCharacter)) $inputSerialization['quoteCharacter'] = (string) $csv->QuoteCharacter;
            } elseif (isset($element->InputSerialization->JSON)) {
                $inputSerialization['format'] = 'JSON';
                if (isset($element->InputSerialization->JSON->Type)) {
                    $inputSerialization['jsonType'] = (string) $element->InputSerialization->JSON->Type;
                }
            }
        }

        $outputSerialization = ['format' => 'CSV'];
        if (isset($element->OutputSerialization)) {
            if (isset($element->OutputSerialization->CSV)) {
                $outputSerialization['format'] = 'CSV';
                $csv = $element->OutputSerialization->CSV;
                if (isset($csv->FieldDelimiter)) $outputSerialization['fieldDelimiter'] = (string) $csv->FieldDelimiter;
                if (isset($csv->RecordDelimiter)) $outputSerialization['recordDelimiter'] = (string) $csv->RecordDelimiter;
                if (isset($csv->QuoteCharacter)) $outputSerialization['quoteCharacter'] = (string) $csv->QuoteCharacter;
            } elseif (isset($element->OutputSerialization->JSON)) {
                $outputSerialization['format'] = 'JSON';
            }
        }

        return [
            'expression' => $expression,
            'inputSerialization' => $inputSerialization,
            'outputSerialization' => $outputSerialization,
        ];
    }
}
