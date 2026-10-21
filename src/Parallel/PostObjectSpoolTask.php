<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;

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
        private readonly int $reservedBytes = 0,
        private readonly int $bytes = 0,
        private readonly int $budgetBytes = 0,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): array
    {
        try {
            $cancellation->throwIfRequested();
            $coordinator = new PostObjectSpoolCoordinator($this->tempDir, runInline: true);

            $value = match ($this->operation) {
                'recover' => $this->recover($coordinator),
                'start' => $this->start($coordinator, $channel, $cancellation),
                'reserve' => $coordinator->reserveInline(
                    $this->leasePath,
                    $this->tempPath,
                    $this->reservedBytes,
                    $this->bytes,
                    $this->budgetBytes,
                ),
                'release' => $this->release($coordinator),
                default => throw new \LogicException("Unknown browser POST spool operation: {$this->operation}."),
            };

            return ['ok' => true, 'value' => $value];
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'type' => $error::class,
                'message' => $error->getMessage(),
            ];
        }
    }

    private function recover(PostObjectSpoolCoordinator $coordinator): null
    {
        $coordinator->recoverAbandonedInline();

        return null;
    }

    /** @param Channel<bool, array{ok: bool, value?: string, type?: class-string<\Throwable>, message?: string}> $channel */
    private function start(
        PostObjectSpoolCoordinator $coordinator,
        Channel $channel,
        Cancellation $cancellation,
    ): string {
        $leasePath = $coordinator->startInline($this->tempPath, $channel, $cancellation);
        if (! is_string($leasePath)) {
            throw new \LogicException('Browser POST reservation worker returned an invalid result.');
        }

        return $leasePath;
    }

    private function release(PostObjectSpoolCoordinator $coordinator): null
    {
        $coordinator->releaseInline($this->leasePath, $this->reservedBytes);

        return null;
    }
}
