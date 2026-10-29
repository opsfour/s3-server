<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

/**
 * Runs host-wide request spool accounting outside the HTTP event loop.
 *
 * @implements Task<array{ok: bool, value?: int|string|null, type?: class-string<\Throwable>, message?: string}, bool, array{ok: bool, value?: string, type?: class-string<\Throwable>, message?: string}>
 */
final class PostObjectSpoolTask implements Task
{
    public function __construct(
        private readonly string $operation,
        private readonly string $tempDir,
        private readonly string $tempPath = '',
        private readonly string $leasePath = '',
        private readonly string $nonce = '',
        private readonly int $reservedBytes = 0,
        private readonly int $bytes = 0,
        private readonly int $budgetBytes = 0,
        private readonly int $filesystemReserveBytes = StorageTempBudgetCoordinator::DEFAULT_FILESYSTEM_RESERVE_BYTES,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): array
    {
        try {
            $cancellation->throwIfRequested();
            $coordinator = new StorageTempBudgetCoordinator(
                $this->tempDir,
                $this->budgetBytes > 0 ? $this->budgetBytes : StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
                $this->filesystemReserveBytes,
            );

            $value = match ($this->operation) {
                'recover' => $this->recover($coordinator),
                'start' => $this->start($coordinator, $channel, $cancellation),
                'reserve' => $this->reserve(
                    $coordinator,
                    $this->leasePath,
                    $this->tempPath,
                    $this->nonce,
                    $this->reservedBytes,
                    $this->bytes,
                ),
                'release' => $this->release($coordinator),
                'cleanup' => $this->cleanup($coordinator),
                default => throw new \LogicException("Unknown browser POST spool operation: {$this->operation}."),
            };

            return ['ok' => true, 'value' => $value];
        } catch (\Throwable $error) {
            $result = [
                'ok' => false,
                'type' => $error::class,
                'message' => $error->getMessage(),
            ];
            if ($this->operation === 'start') {
                try {
                    $channel->send($result);
                } catch (\Throwable) {
                }
            }

            return $result;
        }
    }

    private function recover(StorageTempBudgetCoordinator $coordinator): null
    {
        $coordinator->snapshot();

        return null;
    }

    /** @param Channel<bool, array{ok: bool, value?: string, type?: class-string<\Throwable>, message?: string}> $channel */
    private function start(
        StorageTempBudgetCoordinator $coordinator,
        Channel $channel,
        Cancellation $cancellation,
    ): string {
        $reservation = $coordinator->start($this->tempPath, 'request_spool');
        $leasePath = $reservation->leasePath;
        $token = $leasePath . ':' . $reservation->nonce;
        try {
            $channel->send(['ok' => true, 'value' => $token]);
            if ($channel->receive($cancellation) !== true) {
                throw new \RuntimeException('Cannot transfer request temporary reservation.');
            }
        } finally {
            $reservation->close();
        }

        return $token;
    }

    private function reserve(
        StorageTempBudgetCoordinator $coordinator,
        string $leasePath,
        string $tempPath,
        string $nonce,
        int $reservedBytes,
        int $bytes,
    ): int {
        $coordinator->reserveExisting($leasePath, $tempPath, $nonce, $bytes);

        return $reservedBytes + $bytes;
    }

    private function release(StorageTempBudgetCoordinator $coordinator): null
    {
        $coordinator->releaseExisting($this->leasePath, $this->tempPath, $this->nonce);

        return null;
    }

    private function cleanup(StorageTempBudgetCoordinator $coordinator): int
    {
        return $coordinator->cleanupExisting($this->leasePath, $this->tempPath, $this->nonce) ? 1 : 0;
    }
}
