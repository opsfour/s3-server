<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Logging;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes S3 access log entries to the configured target bucket.
 *
 * Collects access log entries and periodically flushes them
 * as S3 objects in the standard S3 access log format.
 */
final class AccessLogWriter
{
    /** @var int Maximum number of entries to keep in the buffer. */
    private const int MAX_BUFFER_SIZE = 10_000;

    /** @var list<array{bucket: string, line: string}> Buffered log entries with source bucket. */
    private array $buffer = [];

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Add an access log entry.
     */
    public function log(
        string $bucket,
        string $key,
        string $operation,
        int $httpStatus,
        int $bytesTransferred,
        string $remoteIp = '',
        string $requesterId = '',
    ): void {
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('[d/M/Y:H:i:s +0000]');

        // Sanitize user-controlled values to prevent log injection via embedded newlines.
        $safeBucket = str_replace(["\r", "\n"], ['\\r', '\\n'], $bucket);
        $safeKey = $key !== '' ? str_replace(["\r", "\n"], ['\\r', '\\n'], $key) : '-';

        $line = sprintf(
            '%s %s %s %s %s %s %s %d - %d -',
            $remoteIp ?: '-',
            $safeBucket,
            $timestamp,
            $remoteIp ?: '-',
            $requesterId ?: '-',
            $operation,
            $safeKey,
            $httpStatus,
            $bytesTransferred,
        );

        $this->buffer[] = ['bucket' => $bucket, 'line' => $line];

        if (count($this->buffer) > self::MAX_BUFFER_SIZE) {
            array_shift($this->buffer);
            $this->logger->warning('Access log buffer overflow, dropping oldest entry.');
        }
    }

    /**
     * Flush buffered log entries to the target bucket.
     *
     * Groups entries by source bucket, looks up the logging target for each,
     * and writes a log object to the target bucket with concatenated entries.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $entries = $this->buffer;
        $this->buffer = [];

        // Group entries by source bucket.
        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry['bucket']][] = $entry['line'];
        }

        $totalFlushed = 0;

        foreach ($grouped as $sourceBucket => $lines) {
            try {
                $loggingConfig = $this->metadata->getBucketLogging($sourceBucket);

                if ($loggingConfig === null) {
                    // No logging configured for this source bucket; discard entries.
                    continue;
                }

                $targetBucket = $loggingConfig['targetBucket'];
                $targetPrefix = $loggingConfig['targetPrefix'];

                // Build the log key matching AWS S3 access log naming:
                // {targetPrefix}{ISO-date}-{random-hex}.log
                $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d-H-i-s');
                $random = bin2hex(random_bytes(8));
                $logKey = $targetPrefix . $timestamp . '-' . $random . '.log';

                $logContent = implode("\n", $lines) . "\n";

                // Write the log object to the target bucket storage.
                $writeResult = $this->storage->putObject(
                    $targetBucket,
                    $logKey,
                    new ReadableBuffer($logContent),
                );

                // Write the metadata record so the log object is discoverable via S3 API.
                $this->metadata->putObjectMetadata(
                    bucket: $targetBucket,
                    key: $logKey,
                    ownerId: $this->metadata->getBucketOwner($targetBucket) ?? '',
                    size: $writeResult->size,
                    etag: '"' . $writeResult->md5Hex . '"',
                    contentType: 'text/plain',
                    storagePath: $writeResult->path,
                );

                $totalFlushed += count($lines);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Failed to flush access logs for bucket '{$sourceBucket}': {$e->getMessage()}",
                );
            }
        }

        if ($totalFlushed > 0) {
            $this->logger->debug('Flushed ' . $totalFlushed . ' access log entries.');
        }
    }

    /**
     * Get the number of buffered entries.
     */
    public function getBufferCount(): int
    {
        return count($this->buffer);
    }
}
