<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/**
 * Idempotent, one-segment namespace operations for an optional EFS worker.
 * An uncertain mkdir is never acknowledged unless a fresh, safe directory
 * can be observed and the parent directory synchronized.
 */
final readonly class LocalEfsBucketNamespace
{
    public function __construct(
        private string $root,
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
        private LocalEfsBucketNamespaceIo $io = new NativeLocalEfsBucketNamespaceIo(),
    ) {
        if ($root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Guarded bucket namespace root differs from EFS evidence.');
        }
    }

    public function createBucket(string $bucket): void
    {
        $path = $this->bucketPath($bucket);
        $this->evidence->assertFresh();
        $entry = $this->io->lstat($path);
        $this->evidence->assertFresh();
        if ($entry === false) {
            $this->assertAbsentInRoot($bucket);
            $this->evidence->assertFresh();
            // Concurrent same-name provisions are safe: the final entry is
            // checked independently of mkdir's possibly ambiguous result.
            $this->io->mkdir($path);
            $this->evidence->assertFresh();
            $entry = $this->io->lstat($path);
            $this->evidence->assertFresh();
        }
        $this->assertPrivateDirectory($entry);
        $this->evidence->assertFresh();
        $synced = $this->io->syncDirectory($this->root, $this->evidence->assertFresh(...));
        $this->evidence->assertFresh();
        if (! $synced) {
            throw new \RuntimeException('EFS bucket namespace durability is uncertain.');
        }
        $this->evidence->assertFresh();
    }

    public function bucketExists(string $bucket): bool
    {
        $path = $this->bucketPath($bucket);
        $this->evidence->assertFresh();
        $entry = $this->io->lstat($path);
        $this->evidence->assertFresh();
        if ($entry === false) {
            $this->assertAbsentInRoot($bucket);
            return false;
        }
        $this->assertPrivateDirectory($entry);
        $this->evidence->assertFresh();
        return true;
    }

    private function bucketPath(string $bucket): string
    {
        StoragePath::assertBucketPathSegment($bucket);
        return $this->root . '/' . $bucket;
    }

    private function assertAbsentInRoot(string $bucket): void
    {
        $this->evidence->assertFresh();
        $entries = $this->io->listDirectory($this->root);
        $this->evidence->assertFresh();
        if ($entries === false || in_array($bucket, $entries, true)) {
            throw new \RuntimeException('EFS bucket namespace is unavailable or unsafe.');
        }
    }

    /** @param array<array-key, int>|false $entry */
    private function assertPrivateDirectory(array|false $entry): void
    {
        $this->evidence->assertFresh();
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : false;
        if (! is_array($entry) || ! is_int($entry['mode'] ?? null)
            || ($entry['mode'] & 0o170000) !== 0o040000
            || ($entry['mode'] & 0o7777) !== 0o700
            || ! is_int($uid) || ($entry['uid'] ?? null) !== $uid) {
            throw new \RuntimeException('EFS bucket namespace is not a private directory owned by the API user.');
        }
        $this->evidence->assertFresh();
    }
}
