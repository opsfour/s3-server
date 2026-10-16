<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceException;

use function Amp\delay;

/** @implements Task<mixed, never, string> */
final readonly class ProductionEfsFeedbackWorkerTask implements Task
{
    public const string EVIDENCE_FAILURE = 'evidence-failure';

    public const string CANCELLATION = 'cancellation';

    public const string UNSERIALIZABLE_RESULT = 'unserializable-result';

    public function __construct(private string $mode) {}

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        return match ($this->mode) {
            self::EVIDENCE_FAILURE => throw new ProductionEfsWorkerEvidenceException(),
            self::CANCELLATION => $this->waitForCancellation($channel, $cancellation),
            self::UNSERIALIZABLE_RESULT => static fn(): null => null,
            default => throw new \LogicException('Unknown production EFS feedback test mode.'),
        };
    }

    /** @param Channel<never, string> $channel */
    private function waitForCancellation(Channel $channel, Cancellation $cancellation): never
    {
        $channel->send('started');
        while (true) {
            $cancellation->throwIfRequested();
            delay(0.001);
        }
    }
}
