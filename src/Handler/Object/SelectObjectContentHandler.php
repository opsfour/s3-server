<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use Amp\TimeoutCancellation;
use Amp\Http\Server\Driver\Client;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\SelectProcessingTask;
use OpsFour\S3Server\Select\CsvProcessor;
use OpsFour\S3Server\Select\EventStreamEncoder;
use OpsFour\S3Server\Select\ExpressionEvaluator;
use OpsFour\S3Server\Select\JsonProcessor;
use OpsFour\S3Server\Select\SelectMemoryGuard;
use OpsFour\S3Server\Select\SelectMemoryLimitException;
use OpsFour\S3Server\Select\SqlParser;
use OpsFour\S3Server\Select\SelectCsvException;
use OpsFour\S3Server\Select\SelectRecordSizeException;
use OpsFour\S3Server\Select\SelectSqlException;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Xml\SafeXmlParser;

use function Amp\async;

/**
 * Handles SelectObjectContent (POST /{bucket}/{key}?select&select-type=2).
 *
 * Implements S3 Select: SQL queries over CSV/JSON objects.
 * When a WorkerPool is provided, processing is offloaded to a worker process.
 */
final class SelectObjectContentHandler implements RequestHandler
{
    private const string S3_XML_NAMESPACE = 'http://s3.amazonaws.com/doc/2006-03-01/';

    /** @var \WeakMap<Client, DeferredCancellation> */
    private \WeakMap $clientDisconnects;

    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
        private readonly int $maxSelectObjectSize = 268_435_456,
        private readonly ?WorkerPool $workerPool = null,
        private readonly ?MetricsCollector $metrics = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly float $workerTimeoutSeconds = 300.0,
    ) {
        if (! is_finite($workerTimeoutSeconds) || $workerTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('S3 Select worker timeout must be finite and positive.');
        }
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
        $this->clientDisconnects = new \WeakMap();
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // S3 Select requires authentication even when the object has a public
        // read grant. The regular GetObject ACL does not authorize this API.
        if ($ownerId === null || $ownerId === '') {
            throw new AccessDeniedException();
        }
        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        if (array_key_exists('versionId', $queryParams)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'versionId is not supported for S3 Select.',
            );
        }
        if (($queryParams['select-type'] ?? null) !== '2') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('select-type must be 2.');
        }

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);
        if ($objectInfo === null) {
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            throw new NoSuchKeyException();
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        // Size guard: S3 Select buffers the entire object.
        if ($objectInfo->size > $this->maxSelectObjectSize) {
            throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                'S3 Select is limited to objects under ' . $this->maxSelectObjectSize . ' bytes.',
            );
        }

        // Parse request XML.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 262_144);
        $config = self::parseSelectRequest($body);
        try {
            SqlParser::parse($config['expression']);
        } catch (\RuntimeException $e) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid SQL expression: ' . $e->getMessage(),
            );
        }
        // Request-body buffering can yield after the first object lookup.
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        // Fetch object data.
        $storagePath = $objectInfo->systemMetadata['storagePath'] ?? null;
        if ($storagePath === null || $storagePath === '') {
            throw new NoSuchKeyException();
        }

        $tier = $this->storageTiers->tier($objectInfo->storageTier);
        $readStorage = $tier->backend;
        if ($tier->restoreRequired) {
            if (
                $objectInfo->restoreStatus !== 'restored'
                || $objectInfo->restoredStoragePath === null
                || $objectInfo->restoreExpiresAt === null
                || $objectInfo->restoreExpiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                throw new InvalidObjectStateException();
            }
            $readStorage = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($objectInfo, $this->storageTiers)->backend;
            $storagePath = $objectInfo->restoredStoragePath;
        }

        if (isset($objectInfo->userMetadata['__sse-algorithm'])) {
            throw new InvalidObjectStateException('S3 Select does not support encrypted objects.');
        }

        $clientCancellation = $this->clientCancellation($request);
        $clientCancellation->throwIfRequested();
        $bufferedWorkLock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
        try {
            $memoryGuard = SelectMemoryGuard::start();
            $memoryGuard->assertInputSize($objectInfo->size);
            $objectData = $this->bufferSelectObject(
                new LimitedReadableStream($readStorage->getObjectByPath($storagePath), $objectInfo->size + 1),
                $clientCancellation,
                $memoryGuard,
                $objectInfo->size,
            );
            if (strlen($objectData) !== $objectInfo->size) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Stored object size does not match metadata.');
            }
            // Storage reads can yield across a bucket delete and recreation.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            $memoryGuard->assertInputSize(strlen($objectData));
            $memoryGuard->check($this->workerPool === null
                ? 0
                : strlen($objectData) * 2 + SelectMemoryGuard::MAX_OUTPUT_BYTES);
            $clientCancellation->throwIfRequested();

            // Offload to worker if pool is available.
            $response = $this->workerPool !== null
                ? $this->processInWorker($this->workerPool, $objectData, $config, $clientCancellation, $memoryGuard)
                : $this->processInline($objectData, $config, $memoryGuard);
            $clientCancellation->throwIfRequested();
            // A worker can finish after the authorized bucket has been reused.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

            return $response;
        } catch (SelectRecordSizeException) {
            throw new \OpsFour\S3Server\Exception\OverMaxRecordSizeException();
        } catch (NoSuchKeyException $error) {
            // A source path can disappear while its bucket is reused.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            throw $error;
        } catch (SelectSqlException $e) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid SQL expression: ' . $e->getMessage(),
            );
        } catch (SelectMemoryLimitException) {
            throw new SlowDownException('S3 Select exceeded the buffered memory budget.');
        } catch (CancelledException) {
            throw new SlowDownException('S3 Select request was cancelled.');
        } finally {
            $bufferedWorkLock->release();
        }
    }

    private function bufferSelectObject(ByteStream\ReadableStream $source, Cancellation $cancellation, SelectMemoryGuard $memoryGuard, int $expectedSize): string
    {
        $limit = min($this->maxSelectObjectSize, SelectMemoryGuard::MAX_INPUT_BYTES);
        $chunks = [];
        $length = 0;
        try {
            while (($chunk = $source->read($cancellation)) !== null) {
                $chunkLength = strlen($chunk);
                if ($chunkLength === 0 || $chunkLength > $expectedSize - $length) {
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Stored object size does not match metadata.');
                }
                if ($chunkLength > $limit - $length) {
                    throw new SelectMemoryLimitException('S3 Select input exceeds the buffered memory budget.');
                }
                $memoryGuard->check($length + $chunkLength);
                $chunks[] = $chunk;
                $length += $chunkLength;
            }

            $memoryGuard->check($length);
            $result = implode($chunks);
            $memoryGuard->check();

            return $result;
        } finally {
            $source->close();
        }
    }

    /**
     * @param array{expression: string, inputSerialization: array<string, mixed>, outputSerialization: array<string, mixed>} $config
     */
    private function processInWorker(WorkerPool $workerPool, string $objectData, array $config, ?Cancellation $clientCancellation = null, ?SelectMemoryGuard $memoryGuard = null): Response
    {
        $memoryGuard ??= SelectMemoryGuard::start();
        $task = new SelectProcessingTask(
            $objectData,
            $config['expression'],
            $config['inputSerialization'],
            $config['outputSerialization'],
        );

        // The buffered-work admission slot ensures only one Select request per
        // process can wait here. Pool acquisition itself is time bounded.
        $timeout = new TimeoutCancellation($this->workerTimeoutSeconds);
        $cancellation = $clientCancellation === null
            ? $timeout
            : new CompositeCancellation($timeout, $clientCancellation);

        try {
            $worker = self::acquireWorker($workerPool, $cancellation);
        } catch (CancelledException $e) {
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e::class);
            throw new SlowDownException('S3 Select worker acquisition was cancelled.');
        }

        // A dedicated pool worker can be killed on timeout or disconnect
        // without affecting unrelated Select requests running in other workers.
        try {
            $cancellation->throwIfRequested();
            $execution = $worker->submit($task, $cancellation);
        } catch (CancelledException $e) {
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e::class);
            throw new SlowDownException($clientCancellation?->isRequested()
                ? 'S3 Select request was cancelled.'
                : 'S3 Select processing timed out.');
        }

        try {
            $result = $execution->await($cancellation);
            $memoryGuard->check(strlen($result['eventStream']));
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent');
        } catch (CancelledException $e) {
            $worker->kill();
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e::class);
            throw new SlowDownException($clientCancellation?->isRequested()
                ? 'S3 Select request was cancelled.'
                : 'S3 Select processing timed out.');
        } catch (TaskFailureThrowable $e) {
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e->getOriginalClassName());
            if ($e->getOriginalClassName() === SelectSqlException::class) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                    'Invalid SQL expression: ' . $e->getOriginalMessage(),
                );
            }
            if ($e->getOriginalClassName() === \JsonException::class) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid JSON input for S3 Select.');
            }
            if ($e->getOriginalClassName() === SelectCsvException::class) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid CSV input or serialization for S3 Select.');
            }
            if ($e->getOriginalClassName() === SelectRecordSizeException::class) {
                throw new \OpsFour\S3Server\Exception\OverMaxRecordSizeException();
            }
            if ($e->getOriginalClassName() === SelectMemoryLimitException::class) {
                throw new SlowDownException('S3 Select exceeded the buffered memory budget.');
            }

            throw new \RuntimeException('S3 Select worker failed.', 0, $e);
        } catch (\Throwable $e) {
            $this->metrics?->recordWorkerPoolTask('select', 'SelectObjectContent', false, $e::class);
            throw $e;
        }

        return new Response(
            status: 200,
            headers: ['content-type' => 'application/octet-stream'],
            body: $result['eventStream'],
        );
    }

    private static function acquireWorker(WorkerPool $pool, Cancellation $cancellation): Worker
    {
        $cancellation->throwIfRequested();
        // getWorker() can suspend while creating a process or pruning a killed
        // worker. If the request leaves, release a worker that arrives later.
        $pending = async(static fn(): Worker => $pool->getWorker());
        try {
            return $pending->await($cancellation);
        } catch (CancelledException $e) {
            $pending->map(static function (Worker $lateWorker): void {
                unset($lateWorker);
            })->ignore();

            throw $e;
        }
    }

    private function clientCancellation(Request $request): Cancellation
    {
        $client = $request->getClient();
        if (! isset($this->clientDisconnects[$client])) {
            $source = new DeferredCancellation();
            $this->clientDisconnects[$client] = $source;
            $client->onClose(static function () use ($source): void {
                $source->cancel();
            });
        }

        $source = $this->clientDisconnects[$client];
        if ($client->isClosed()) {
            $source->cancel();
        }

        return $source->getCancellation();
    }

    /**
     * @param array{expression: string, inputSerialization: array<string, mixed>, outputSerialization: array<string, mixed>} $config
     */
    private function processInline(string $objectData, array $config, ?SelectMemoryGuard $memoryGuard = null): Response
    {
        $memoryGuard ??= SelectMemoryGuard::start();
        $memoryGuard->assertInputSize(strlen($objectData));
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
            'CSV' => self::parseCsv(
                $objectData,
                $config['inputSerialization']['fieldDelimiter'] ?? ',',
                $config['inputSerialization']['recordDelimiter'] ?? "\n",
                $config['inputSerialization']['quoteCharacter'] ?? '"',
                $config['inputSerialization']['fileHeaderInfo'] ?? 'NONE',
                $memoryGuard,
            )['rows'],
            'JSON' => self::parseJson(
                $objectData,
                $config['inputSerialization']['jsonType'] ?? 'LINES',
                $memoryGuard,
            ),
            default => throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                "Unsupported input format: {$inputFormat}",
            ),
        };

        // Free raw object data — rows have been parsed.
        unset($objectData);
        $memoryGuard->check();

        // Apply WHERE filter.
        $filtered = array_filter(
            $rows,
            fn(array $row)
            => ExpressionEvaluator::evaluate($parsed['where'], $row, $parsed['alias']),
        );
        $filtered = array_values($filtered);
        $memoryGuard->check();

        // Free unfiltered rows.
        unset($rows);

        // Project columns — aggregate queries produce a single result row.
        if (ExpressionEvaluator::isAggregate($parsed['columns'])) {
            $projected = ExpressionEvaluator::computeAggregates($parsed['columns'], $filtered, $parsed['alias']);
        } else {
            $projected = self::projectColumns($filtered, $parsed['columns'], $parsed['alias'], $memoryGuard);
        }

        // Free filtered rows.
        unset($filtered);

        // Format output.
        $outputFormat = $config['outputSerialization']['format'] ?? 'CSV';
        $outputData = match ($outputFormat) {
            'CSV' => self::formatCsv(
                $projected,
                null,
                $config['outputSerialization']['fieldDelimiter'] ?? ',',
                $config['outputSerialization']['recordDelimiter'] ?? "\n",
                $config['outputSerialization']['quoteCharacter'] ?? '"',
                $memoryGuard,
            ),
            'JSON' => self::formatJson($projected, $memoryGuard),
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
            $memoryGuard,
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
    private static function projectColumns(array $rows, array $columns, string $alias, SelectMemoryGuard $memoryGuard): array
    {
        // SELECT * — return all columns.
        if (count($columns) === 1 && $columns[0]['expr'] === '*') {
            return $rows;
        }

        $result = [];
        foreach ($rows as $row) {
            $projected = [];
            $columnIndex = 0;
            foreach ($columns as $col) {
                if (($columnIndex++ & 63) === 0) {
                    $memoryGuard->check();
                }
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
            $memoryGuard->check();
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function parseJson(string $data, string $type, SelectMemoryGuard $memoryGuard): array
    {
        try {
            return JsonProcessor::parse($data, $type, $memoryGuard);
        } catch (\JsonException) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid JSON input for S3 Select.');
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private static function formatJson(array $rows, SelectMemoryGuard $memoryGuard): string
    {
        try {
            return JsonProcessor::format($rows, $memoryGuard);
        } catch (\JsonException) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid JSON input for S3 Select.');
        }
    }

    /** @return array{headers: ?list<string>, rows: list<array<string, string>>} */
    private static function parseCsv(string $data, string $fieldDelimiter, string $recordDelimiter, string $quoteCharacter, string $fileHeaderInfo, SelectMemoryGuard $memoryGuard): array
    {
        try {
            return CsvProcessor::parse($data, $fieldDelimiter, $recordDelimiter, $quoteCharacter, $fileHeaderInfo, $memoryGuard);
        } catch (SelectCsvException) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid CSV input or serialization for S3 Select.');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $columns
     */
    private static function formatCsv(array $rows, ?array $columns, string $fieldDelimiter, string $recordDelimiter, string $quoteCharacter, SelectMemoryGuard $memoryGuard): string
    {
        try {
            return CsvProcessor::format($rows, $columns, $fieldDelimiter, $recordDelimiter, $quoteCharacter, $memoryGuard);
        } catch (SelectCsvException) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid CSV input or serialization for S3 Select.');
        }
    }

    /**
     * Parse SelectObjectContent request XML.
     *
     * @return array{expression: string, inputSerialization: array<string, mixed>, outputSerialization: array<string, mixed>}
     */
    private static function parseSelectRequest(string $xml): array
    {
        $element = SafeXmlParser::parse($xml, 'Invalid SelectObjectContent request XML.');

        $namespace = dom_import_simplexml($element)->namespaceURI;
        if ($element->getName() !== 'SelectObjectContentRequest'
            || ! in_array($namespace, [null, self::S3_XML_NAMESPACE], true)) {
            throw new MalformedXmlException('Expected SelectObjectContentRequest root element.');
        }
        $root = self::selectChildren($element, ['Expression', 'ExpressionType', 'InputSerialization', 'OutputSerialization'], 'SelectObjectContentRequest');

        if (! isset($root['Expression'], $root['ExpressionType'], $root['InputSerialization'], $root['OutputSerialization'])) {
            throw new MalformedXmlException('SelectObjectContentRequest requires Expression, ExpressionType, InputSerialization, and OutputSerialization.');
        }

        $expressionType = self::selectScalar($root['ExpressionType'], 'ExpressionType');
        $expression = self::selectScalar($root['Expression'], 'Expression');
        if ($expressionType !== 'SQL' || trim($expression) === '') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('S3 Select requires a non-empty SQL expression.');
        }

        $serializations = [];
        foreach (['InputSerialization', 'OutputSerialization'] as $field) {
            $serializations[$field] = self::selectChildren(
                $root[$field],
                $field === 'InputSerialization' ? ['CSV', 'JSON', 'CompressionType'] : ['CSV', 'JSON'],
                $field,
            );
            if ((int) isset($serializations[$field]['CSV']) + (int) isset($serializations[$field]['JSON']) !== 1) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException("{$field} requires exactly one supported CSV or JSON format.");
            }
        }

        $input = $serializations['InputSerialization'];
        $output = $serializations['OutputSerialization'];
        if (isset($input['CompressionType']) && self::selectScalar($input['CompressionType'], 'CompressionType') !== 'NONE') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('S3 Select does not support compressed input.');
        }

        $inputSerialization = ['format' => 'CSV'];
        if (isset($input['CSV'])) {
            $csv = self::selectChildren($input['CSV'], ['FileHeaderInfo', 'FieldDelimiter', 'RecordDelimiter', 'QuoteCharacter'], 'InputSerialization/CSV');
            $inputSerialization['format'] = 'CSV';
            if (isset($csv['FileHeaderInfo'])) {
                $inputSerialization['fileHeaderInfo'] = self::selectScalar($csv['FileHeaderInfo'], 'InputSerialization/CSV/FileHeaderInfo');
                if (! in_array($inputSerialization['fileHeaderInfo'], ['NONE', 'USE', 'IGNORE'], true)) {
                    throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid CSV FileHeaderInfo value.');
                }
            }
            if (isset($csv['FieldDelimiter'])) {
                $inputSerialization['fieldDelimiter'] = self::selectDelimiter(self::selectScalar($csv['FieldDelimiter'], 'InputSerialization/CSV/FieldDelimiter'), 'FieldDelimiter');
            }
            if (isset($csv['RecordDelimiter'])) {
                $inputSerialization['recordDelimiter'] = self::selectDelimiter(self::selectScalar($csv['RecordDelimiter'], 'InputSerialization/CSV/RecordDelimiter'), 'RecordDelimiter', false);
            }
            if (isset($csv['QuoteCharacter'])) {
                $inputSerialization['quoteCharacter'] = self::selectDelimiter(self::selectScalar($csv['QuoteCharacter'], 'InputSerialization/CSV/QuoteCharacter'), 'QuoteCharacter');
            }
        } elseif (isset($input['JSON'])) {
            $json = self::selectChildren($input['JSON'], ['Type'], 'InputSerialization/JSON');
            $inputSerialization['format'] = 'JSON';
            if (isset($json['Type'])) {
                $inputSerialization['jsonType'] = self::selectScalar($json['Type'], 'InputSerialization/JSON/Type');
                if (! in_array($inputSerialization['jsonType'], ['LINES', 'DOCUMENT'], true)) {
                    throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Invalid JSON input type.');
                }
            }
        }

        $outputSerialization = ['format' => 'CSV'];
        if (isset($output['CSV'])) {
            $outputSerialization['format'] = 'CSV';
            $csv = self::selectChildren($output['CSV'], ['FieldDelimiter', 'RecordDelimiter', 'QuoteCharacter'], 'OutputSerialization/CSV');
            if (isset($csv['FieldDelimiter'])) {
                $outputSerialization['fieldDelimiter'] = self::selectDelimiter(self::selectScalar($csv['FieldDelimiter'], 'OutputSerialization/CSV/FieldDelimiter'), 'FieldDelimiter');
            }
            if (isset($csv['RecordDelimiter'])) {
                $outputSerialization['recordDelimiter'] = self::selectDelimiter(self::selectScalar($csv['RecordDelimiter'], 'OutputSerialization/CSV/RecordDelimiter'), 'RecordDelimiter', false);
            }
            if (isset($csv['QuoteCharacter'])) {
                $outputSerialization['quoteCharacter'] = self::selectDelimiter(self::selectScalar($csv['QuoteCharacter'], 'OutputSerialization/CSV/QuoteCharacter'), 'QuoteCharacter');
            }
        } elseif (isset($output['JSON'])) {
            self::selectChildren($output['JSON'], [], 'OutputSerialization/JSON');
            $outputSerialization['format'] = 'JSON';
        }

        return [
            'expression' => $expression,
            'inputSerialization' => $inputSerialization,
            'outputSerialization' => $outputSerialization,
        ];
    }

    private static function selectDelimiter(string $value, string $field, bool $singleByte = true): string
    {
        if ($value === '' || ($singleByte && strlen($value) !== 1)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException("{$field} must be non-empty" . ($singleByte ? ' and exactly one byte.' : '.'));
        }

        return $value;
    }

    private static function selectScalar(\SimpleXMLElement $element, string $path): string
    {
        if (($element->xpath('./*') ?: []) !== []) {
            throw new MalformedXmlException("{$path} must contain only text.");
        }

        return (string) $element;
    }

    /**
     * @param list<string> $allowed
     * @return array<string, \SimpleXMLElement>
     */
    private static function selectChildren(\SimpleXMLElement $element, array $allowed, string $path): array
    {
        $namespace = dom_import_simplexml($element)->namespaceURI;
        $seen = [];
        foreach ($element->xpath('./*') ?: [] as $child) {
            $name = $child->getName();
            if (dom_import_simplexml($child)->namespaceURI !== $namespace
                || ! in_array($name, $allowed, true) || isset($seen[$name])) {
                throw new MalformedXmlException("{$path} contains an unsupported or duplicate element.");
            }
            $seen[$name] = $child;
        }

        return $seen;
    }
}
