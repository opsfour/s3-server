<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/**
 * Partial physical delete primitive. The caller must retain its SQL job until
 * this worker returns and the API checks host evidence again. Not wired for
 * production: PHP path APIs cannot prevent a concurrent directory swap by an
 * untrusted writer, and parent-directory cleanup is deliberately omitted.
 */
final readonly class LocalEfsDeleteFence
{
    public function __construct(
        private string $root,
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
        private LocalEfsDeleteIo $io = new NativeLocalEfsDeleteIo(),
    ) {
        if ($root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Guarded local delete root differs from EFS evidence.');
        }
        $evidence->assertFresh();
    }

    public function deletePath(string $path, string $bucket): void
    {
        $this->evidence->assertFresh();
        $prefix = rtrim($this->root, '/') . '/';
        if (! str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Local delete path escapes its storage root.');
        }
        FlysystemPathScope::assertDeletePath(substr($path, strlen($prefix)), $bucket);
        $this->deleteFile($path);
    }

    public function abortMultipart(string $uploadId): void
    {
        $this->evidence->assertFresh();
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $directory = rtrim($this->root, '/') . '/.parts/' . $uploadId;
        if (! $this->assertDirectoryChain($directory)) {
            return;
        }
        $this->evidence->assertFresh();
        $entries = $this->io->listDirectory($directory);
        $this->evidence->assertFresh();
        if ($entries === false) {
            throw new \RuntimeException('Local multipart abort directory is unavailable.');
        }
        foreach ($entries as $entry) {
            $this->evidence->assertFresh();
            if (preg_match('/^[1-9][0-9]{0,4}-[a-f0-9]{32}-[a-f0-9]{32}$/D', $entry) === 1) {
                // A planned attempt or permanent seal remains occupied.
                continue;
            }
            if (! ctype_digit($entry)
                && preg_match('/^[1-9][0-9]{0,4}-[a-f0-9]{32}$/D', $entry) !== 1) {
                throw new \RuntimeException('Unexpected multipart path blocks legacy abort.');
            }
            $this->deleteFile($directory . '/' . $entry);
        }
    }

    private function deleteFile(string $path): void
    {
        $directory = dirname($path);
        if (! $this->assertDirectoryChain($directory)) {
            return;
        }
        $this->evidence->assertFresh();
        $entries = $this->io->listDirectory($directory);
        $this->evidence->assertFresh();
        if ($entries === false) {
            throw new \RuntimeException('Local delete directory is unavailable.');
        }
        if (! in_array(basename($path), $entries, true)) {
            $this->evidence->assertFresh();
            return;
        }
        $this->evidence->assertFresh();
        $linked = $this->io->isLink($path);
        $this->evidence->assertFresh();
        $file = $this->io->isFile($path);
        $this->evidence->assertFresh();
        if ($linked || ! $file) {
            throw new \RuntimeException('Local delete target is not a regular file.');
        }
        $this->evidence->assertFresh();
        $unlinked = $this->io->unlinkFile($path);
        $this->evidence->assertFresh();
        if (! $unlinked) {
            // A failed NFS unlink may already have taken effect. SQL must keep
            // the exact-path job until a healthy worker verifies absence.
            throw new \RuntimeException('Local EFS deletion is uncertain.');
        }
        $synced = $this->io->syncDirectory($directory, $this->evidence->assertFresh(...));
        $this->evidence->assertFresh();
        if (! $synced) {
            throw new \RuntimeException('Failed to synchronize local EFS deletion.');
        }
        $this->evidence->assertFresh();
    }

    private function assertDirectoryChain(string $directory): bool
    {
        $root = rtrim($this->root, '/');
        if (! str_starts_with($directory, $root . '/')) {
            throw new \InvalidArgumentException('Local delete directory escapes its storage root.');
        }
        $current = $root;
        foreach (explode('/', substr($directory, strlen($root) + 1)) as $segment) {
            $this->evidence->assertFresh();
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid local delete directory.');
            }
            $current .= '/' . $segment;
            $linked = $this->io->isLink($current);
            $this->evidence->assertFresh();
            if ($linked) {
                throw new \RuntimeException('Local delete directory is unavailable or unsafe.');
            }
            $isDirectory = $this->io->isDirectory($current);
            $this->evidence->assertFresh();
            if (! $isDirectory) {
                // is_dir() alone cannot distinguish an absent component from
                // an EFS I/O error. Require a successful parent listing, then
                // recheck local host evidence before accepting absence.
                $this->evidence->assertFresh();
                $entries = $this->io->listDirectory(dirname($current));
                $this->evidence->assertFresh();
                if ($entries === false || in_array($segment, $entries, true)) {
                    throw new \RuntimeException('Local delete directory is unavailable or unsafe.');
                }
                $this->evidence->assertFresh();
                return false;
            }
            $resolved = $this->io->realPath($current);
            $this->evidence->assertFresh();
            if ($resolved !== $current) {
                throw new \RuntimeException('Local delete directory is unavailable or unsafe.');
            }
        }

        $this->evidence->assertFresh();
        return true;
    }
}
