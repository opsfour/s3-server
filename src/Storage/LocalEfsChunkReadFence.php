<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/**
 * One worker owns one opened inode until EOF or an explicit cancellation.
 * Exactly one chunk is outstanding: after sending it, the worker waits for a
 * credit before reading again. No filesystem handle crosses the IPC boundary.
 *
 * @internal The serving EFS profile remains closed.
 */
final readonly class LocalEfsChunkReadFence
{
    private const int CHUNK_BYTES = 1_048_576;

    /** @param Closure(string): bool $assertConfined */
    public function __construct(
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
        private Closure $assertConfined,
    ) {}

    /** @param Channel<string, array{chunk?: string, eof?: bool}> $channel */
    public function run(string $path, ?int $offset, ?int $length, Channel $channel, Cancellation $cancellation): void
    {
        if ($offset !== null && $offset < 0 || $length !== null && $length < 0) {
            throw new \InvalidArgumentException('Invalid guarded read range.');
        }
        $cancellation->throwIfRequested();
        $this->evidence->assertFresh();
        if (! ($this->assertConfined)($path)) {
            throw new \RuntimeException('Guarded read source is not confined to its storage root.');
        }
        $this->evidence->assertFresh();
        clearstatcache(true, $path);
        $entry = @lstat($path);
        $this->evidence->assertFresh();
        if (! is_array($entry) || ! self::regular($entry)) {
            throw new \RuntimeException('Guarded read path is not a regular file.');
        }
        $this->evidence->assertFresh();
        $file = @fopen($path, 'rb');
        $this->evidence->assertFresh();
        if ($file === false) {
            throw new \RuntimeException('Guarded read source could not be opened.');
        }
        $closed = false;
        try {
            $opened = @fstat($file);
            $this->evidence->assertFresh();
            if (! is_array($opened) || ! self::sameInode($entry, $opened)) {
                throw new \RuntimeException('Guarded read source changed during open.');
            }
            if ($offset !== null && $offset > 0) {
                $this->evidence->assertFresh();
                $seeked = @fseek($file, $offset, SEEK_SET);
                $this->evidence->assertFresh();
                if ($seeked !== 0) {
                    throw new \RuntimeException('Guarded read source could not be seeked.');
                }
            }
            $remaining = $length;
            while (true) {
                $cancellation->throwIfRequested();
                $this->evidence->assertFresh();
                if ($remaining !== null && $remaining <= 0) {
                    break;
                }
                $want = min(self::CHUNK_BYTES, $remaining ?? self::CHUNK_BYTES);
                $chunk = @fread($file, $want);
                $this->evidence->assertFresh();
                $after = @fstat($file);
                $this->evidence->assertFresh();
                if (! is_array($after) || ! self::sameInode($opened, $after)
                    || ($after['size'] ?? null) !== ($opened['size'] ?? null)) {
                    throw new \RuntimeException('Guarded read source changed during transfer.');
                }
                $eof = feof($file);
                $this->evidence->assertFresh();
                if (! is_string($chunk) || ($chunk === '' && ! $eof)) {
                    throw new \RuntimeException('Guarded read source returned an incomplete chunk.');
                }
                if ($chunk === '') {
                    if ($remaining !== null) {
                        throw new \RuntimeException('Guarded read source ended before the requested range.');
                    }
                    break;
                }
                if ($remaining !== null) {
                    $remaining -= strlen($chunk);
                }
                $this->evidence->assertFresh();
                $channel->send(['chunk' => $chunk]);
                $command = $channel->receive();
                $cancellation->throwIfRequested();
                $this->evidence->assertFresh();
                if ($command === 'cancel') {
                    return;
                }
                if ($command !== 'next') {
                    throw new \RuntimeException('Invalid guarded read credit.');
                }
            }
            $this->evidence->assertFresh();
            $didClose = @fclose($file);
            $this->evidence->assertFresh();
            $closed = $didClose;
            if (! $didClose) {
                throw new \RuntimeException('Guarded read source could not be closed.');
            }
            $channel->send(['eof' => true]);
        } finally {
            if (! $closed) {
                // Descriptor-only best-effort cleanup after evidence loss.
                @fclose($file);
            }
        }
    }

    /** @param array<array-key, int> $entry */
    private static function regular(array $entry): bool
    {
        return is_int($entry['mode'] ?? null) && ($entry['mode'] & 0o170000) === 0o100000;
    }

    /** @param array<array-key, int> $before
     *  @param array<array-key, int> $after
     */
    private static function sameInode(array $before, array $after): bool
    {
        return self::regular($before) && self::regular($after)
            && ($before['dev'] ?? null) === ($after['dev'] ?? null)
            && ($before['ino'] ?? null) === ($after['ino'] ?? null);
    }
}
