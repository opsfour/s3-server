<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceException;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceLatch;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceScope;

/** @implements Task<array{pid: int, accepted: bool, error: string}, never, never> */
final readonly class ProductionEfsEvidenceLatchWorkerTask implements Task
{
    public function __construct(
        private ProductionEfsWorkerEvidenceScope $scope,
        private bool $propagateFailure = false,
    ) {}

    /** @return array{pid: int, accepted: bool, error: string} */
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $pid = getmypid();
        if ($pid === false) {
            throw new \RuntimeException('Worker process identifier is unavailable.');
        }

        $scope = $this->scope;
        if ($this->propagateFailure) {
            self::assertFreshWithTestIo($scope);

            return ['pid' => $pid, 'accepted' => true, 'error' => ''];
        }
        try {
            self::assertFreshWithTestIo($scope);

            return ['pid' => $pid, 'accepted' => true, 'error' => ''];
        } catch (ProductionEfsWorkerEvidenceException $error) {
            return ['pid' => $pid, 'accepted' => false, 'error' => $error->getMessage()];
        }
    }

    private static function assertFreshWithTestIo(ProductionEfsWorkerEvidenceScope $scope): void
    {
        $method = new \ReflectionMethod(ProductionEfsWorkerEvidenceLatch::class, 'assertFreshWithIo');
        $method->invoke(
            null,
            $scope,
            static fn(): int => $scope->validatedMonotonicNs,
            static fn(): string => $scope->bootId,
            static fn(): string => "1 0 0:1 / / rw - ext4 /dev/root rw\n"
                . "2 1 0:2 /evidence {$scope->evidenceDirectory} ro - tmpfs tmpfs rw\n",
            self::rootOwnedLstat(...),
            self::rootOwnedFstat(...),
        );
    }

    /** @return array<array-key, int>|false */
    private static function rootOwnedLstat(string $path): array|false
    {
        clearstatcache(true, $path);

        return self::rootOwned(@lstat($path));
    }

    /** @param resource $handle
     *  @return array<array-key, int>|false
     */
    private static function rootOwnedFstat($handle): array|false
    {
        return self::rootOwned(@fstat($handle));
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>|false
     */
    private static function rootOwned(array|false $entry): array|false
    {
        if ($entry === false) {
            return false;
        }
        $entry['uid'] = 0;
        $entry['mode'] &= ~0o022;

        return $entry;
    }
}
