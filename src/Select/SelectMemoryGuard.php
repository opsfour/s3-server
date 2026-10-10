<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Conservative admission and progress checks for the buffered Select pipeline.
 * This guard cannot replace row/frame streaming, but rejects allocations before
 * an adversarial input can exhaust a normally configured PHP process.
 */
final class SelectMemoryGuard
{
    public const int MAX_INPUT_BYTES = 64 * 1024 * 1024;
    private const int MAX_JSON_DOCUMENT_BYTES = 2 * 1024 * 1024;
    public const int MAX_OUTPUT_BYTES = 32 * 1024 * 1024;
    private const int MAX_GROWTH_BYTES = 128 * 1024 * 1024;
    private const int MIN_PHP_HEADROOM_BYTES = 64 * 1024 * 1024;

    private function __construct(
        private readonly int $startingBytes,
        private readonly int $memoryLimitBytes,
        private readonly int $minimumHeadroomBytes,
    ) {}

    public static function start(): self
    {
        $startingBytes = memory_get_usage(true);
        $memoryLimitBytes = ini_parse_quantity((string) ini_get('memory_limit'));
        $minimumHeadroomBytes = $memoryLimitBytes > 0
            ? min(self::MIN_PHP_HEADROOM_BYTES, max(8 * 1024 * 1024, intdiv(max(0, $memoryLimitBytes - $startingBytes), 2)))
            : self::MIN_PHP_HEADROOM_BYTES;

        return new self($startingBytes, $memoryLimitBytes, $minimumHeadroomBytes);
    }

    public function assertInputSize(int $bytes): void
    {
        if ($bytes > self::MAX_INPUT_BYTES) {
            throw new SelectMemoryLimitException('S3 Select input exceeds the buffered memory budget.');
        }

        // Amp buffering and worker IPC may temporarily hold a second copy.
        $this->check($bytes * 2);
    }

    public function assertJsonDocumentSize(int $bytes): void
    {
        if ($bytes > self::MAX_JSON_DOCUMENT_BYTES) {
            throw new SelectMemoryLimitException('S3 Select JSON document exceeds the buffered memory budget.');
        }
        $this->check();
    }

    public function assertOutputSize(int $currentBytes, int $nextRecordBytes): void
    {
        if ($currentBytes > self::MAX_OUTPUT_BYTES - $nextRecordBytes) {
            throw new SelectMemoryLimitException('S3 Select result exceeds the buffered memory budget.');
        }
        $this->check($currentBytes + $nextRecordBytes);
    }

    public function check(int $prospectiveBytes = 0): void
    {
        $used = memory_get_usage(true);
        if ($used - $this->startingBytes + $prospectiveBytes > self::MAX_GROWTH_BYTES) {
            throw new SelectMemoryLimitException('S3 Select exceeded the buffered memory budget.');
        }

        if ($this->memoryLimitBytes > 0 && $used + $prospectiveBytes > $this->memoryLimitBytes - $this->minimumHeadroomBytes) {
            throw new SelectMemoryLimitException('S3 Select has insufficient PHP memory headroom.');
        }
    }
}
