<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Keep one terminal heartbeat reader for the lifetime of an Amp worker.
 *
 * A worker is bound to the first immutable production scope it receives. The
 * cached reader preserves heartbeat progression and permanent revocation
 * across separate tasks in that process. A scope change poisons the process
 * permanently. Recovery therefore requires the worker process to terminate
 * and a replacement to validate the scope anew.
 */
final class ProductionEfsWorkerEvidenceLatch
{
    /** @var array<string, int|string>|null */
    private static ?array $scopeFields = null;

    private static ?ProductionEfsWatcherEvidenceFileReader $reader = null;

    private static bool $poisoned = false;

    /** Revalidate the native local heartbeat for one guarded worker operation. */
    public static function assertFresh(ProductionEfsWorkerEvidenceScope $scope): void
    {
        self::assertFreshWithIo($scope);
    }

    /**
     * @param (Closure(): int)|null $monotonicClock
     * @param (Closure(): (string|false))|null $bootIdReader
     * @param (Closure(): (string|false))|null $mountInfoReader
     * @param (Closure(string): (array<array-key, int>|false))|null $lstatReader
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstatReader
     */
    private static function assertFreshWithIo(
        ProductionEfsWorkerEvidenceScope $scope,
        ?Closure $monotonicClock = null,
        ?Closure $bootIdReader = null,
        ?Closure $mountInfoReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
    ): void {
        if (self::$poisoned) {
            throw new ProductionEfsWorkerEvidenceException();
        }
        $scopeFields = get_object_vars($scope);
        if (self::$scopeFields !== null && self::$scopeFields !== $scopeFields) {
            self::reject();
        }

        if (self::$reader === null) {
            self::$scopeFields = $scopeFields;
            try {
                self::$reader = ProductionEfsWatcherEvidenceFileReader::fromWorkerScope(
                    $scope,
                    $monotonicClock,
                    $bootIdReader,
                    $mountInfoReader,
                    $lstatReader,
                    $fstatReader,
                );
            } catch (\Throwable) {
                self::reject();
            }
        }

        try {
            self::$reader->assertFresh();
        } catch (\Throwable) {
            self::reject();
        }
    }

    private static function reject(): never
    {
        self::$poisoned = true;

        throw new ProductionEfsWorkerEvidenceException();
    }
}
