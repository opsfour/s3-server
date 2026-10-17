<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidenceFileReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceException;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceLatch;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceScope;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/** @implements Task<array{pid: int, storageRoot: string, successfulChecks: int, rejectedAfterRemoval: bool}, never, never> */
final readonly class ProductionEfsStorageEvidenceWorkerTask implements Task
{
    public function __construct(private ProductionEfsWorkerEvidenceScope $scope) {}

    /** @return array{pid: int, storageRoot: string, successfulChecks: int, rejectedAfterRemoval: bool} */
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $pid = getmypid();
        if ($pid === false) {
            throw new \RuntimeException('Worker process identifier is unavailable.');
        }

        $scope = $this->scope;
        self::initializeLatchWithTestIo($scope);
        $evidence = new ProductionEfsWorkerStorageEvidence($scope);
        $successfulChecks = 0;
        $evidence->assertFresh();
        $successfulChecks++;
        $evidence->assertFresh();
        $successfulChecks++;

        $path = $scope->evidenceDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($scope->identity(), $scope->hostPin());
        if (! @unlink($path)) {
            throw new \RuntimeException('Test heartbeat could not be removed.');
        }
        try {
            $evidence->assertFresh();
            $rejectedAfterRemoval = false;
        } catch (ProductionEfsWorkerEvidenceException) {
            $rejectedAfterRemoval = true;
        }

        return [
            'pid' => $pid,
            'storageRoot' => $evidence->storageRoot(),
            'successfulChecks' => $successfulChecks,
            'rejectedAfterRemoval' => $rejectedAfterRemoval,
        ];
    }

    private static function initializeLatchWithTestIo(ProductionEfsWorkerEvidenceScope $scope): void
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
