<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Cutover;

/**
 * Caller-supplied label for one inventory stream. Equal snapshot tokens are
 * assertions, not evidence that SQL and storage share a stopped snapshot.
 */
final readonly class CutoverInventoryIdentity
{
    public function __construct(
        public string $storageTier,
        public string $rootPathSha256,
        public string $snapshotToken,
    ) {
        if ($storageTier === '' || strlen($storageTier) > 255 || str_contains($storageTier, "\0")
            || preg_match('/^[a-f0-9]{64}$/D', $rootPathSha256) !== 1
            || $snapshotToken === '' || strlen($snapshotToken) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $snapshotToken) === 1) {
            throw new \InvalidArgumentException('Invalid cutover inventory identity.');
        }
    }
}
