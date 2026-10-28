<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\CancelledException;

/**
 * Runs best-effort cleanup without replacing an in-flight request failure.
 *
 * @internal
 */
final class CancellationPreservingCleanup
{
    private function __construct() {}

    /** @param callable(): void ...$cleanups */
    public static function afterFailure(\Throwable $failure, callable ...$cleanups): void
    {
        $cleanupError = self::runAll(array_values($cleanups));
        if ($cleanupError !== null && ! $failure instanceof CancelledException) {
            throw $cleanupError;
        }
    }

    /** @param callable(): void ...$cleanups */
    public static function inFinally(?\Throwable $failure, callable ...$cleanups): void
    {
        $cleanupError = self::runAll(array_values($cleanups));
        if ($cleanupError !== null && $failure === null) {
            throw $cleanupError;
        }
    }

    /**
     * @param list<callable(): void> $cleanups
     */
    private static function runAll(array $cleanups): ?\Throwable
    {
        $firstError = null;
        foreach ($cleanups as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }

        return $firstError;
    }
}
