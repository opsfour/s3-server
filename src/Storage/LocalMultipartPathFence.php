<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/**
 * Physical create-only publication and permanent same-path sealing for local
 * multipart attempts. The caller must first commit a one-shot SQL dispatch or
 * collector claim. All methods perform blocking syscalls and run in a worker.
 */
final readonly class LocalMultipartPathFence
{
    private string $root;

    private string $inputRoot;

    public function __construct(
        string $storageRoot,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence|null $efsEvidence = null,
    ) {
        if ($efsEvidence !== null) {
            if (! $io instanceof EfsAwareLocalObjectWriteIo) {
                throw new \InvalidArgumentException('Guarded multipart I/O must support evidence-aware directory synchronization.');
            }
            if ($storageRoot !== $efsEvidence->storageRoot()) {
                throw new \InvalidArgumentException('Guarded local multipart root differs from EFS evidence.');
            }
            $efsEvidence->assertFresh();
            $this->root = rtrim($storageRoot, '/');
            $this->inputRoot = rtrim($storageRoot, '/');
            return;
        }
        $root = realpath($storageRoot);
        if ($root === false || ! is_dir($root)) {
            throw new \InvalidArgumentException('Local multipart storage root must exist.');
        }
        $this->root = rtrim($root, '/');
        $this->inputRoot = rtrim($storageRoot, '/');
    }

    /** The staged file must already be complete and protected by its temp lease. */
    public function publish(string $stagedPath, string $targetPath): void
    {
        $this->publishInternal($stagedPath, $targetPath, null);
    }

    /** @internal The caller already synced and read back this exact inode. */
    public function publishVerifiedTemp(string $stagedPath, string $targetPath, int $device, int $inode): void
    {
        $this->publishInternal($stagedPath, $targetPath, [$device, $inode]);
    }

    /** @param array{int, int}|null $verifiedInode */
    private function publishInternal(string $stagedPath, string $targetPath, ?array $verifiedInode): void
    {
        $this->efsEvidence?->assertFresh();
        $stagedPath = $this->normalizePath($stagedPath);
        $targetPath = $this->normalizePath($targetPath);
        $this->assertPath($stagedPath, '.tmp');
        $this->assertPath($targetPath, null);

        $this->efsEvidence?->assertFresh();
        $file = @fopen($stagedPath, 'rb');
        $this->efsEvidence?->assertFresh();
        if ($file === false) {
            throw new \RuntimeException('Local multipart staging file is unavailable.');
        }
        try {
            $this->efsEvidence?->assertFresh();
            $opened = @fstat($file);
            $this->efsEvidence?->assertFresh();
            if ($verifiedInode !== null && (! is_array($opened)
                || $opened['dev'] !== $verifiedInode[0] || $opened['ino'] !== $verifiedInode[1])) {
                throw new \RuntimeException('Verified multipart staging inode changed before publication.');
            }
            if ($verifiedInode === null) {
                $synced = $this->io->syncFile($file);
                $this->efsEvidence?->assertFresh();
                if (! $synced) {
                    throw new \RuntimeException('Failed to synchronize local multipart data.');
                }
            }
        } finally {
            // Descriptor-only cleanup is the sole best-effort syscall allowed
            // after evidence loss; it cannot start a new pathname operation.
            @fclose($file);
        }

        // A failed link may already have reached another host. Never retry a
        // different payload at this path or remove the possible publication.
        $this->efsEvidence?->assertFresh();
        $linked = $this->io->link($stagedPath, $targetPath);
        $this->efsEvidence?->assertFresh();
        if (! $linked) {
            throw new \RuntimeException('Local multipart create-only publication is uncertain.');
        }
        $this->syncDirectoryChain(dirname($targetPath));
    }

    /** Replace one uncommitted path with a permanent seal after a SQL claim. */
    public function seal(string $targetPath, string $attemptId): void
    {
        $this->efsEvidence?->assertFresh();
        $targetPath = $this->normalizePath($targetPath);
        $this->ensureDirectory(dirname($targetPath));
        $this->assertPath($targetPath, null);
        if (! preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new \InvalidArgumentException('Invalid local multipart attempt ID.');
        }

        $directory = dirname($targetPath);
        $marker = "OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . $attemptId . "\n" . bin2hex(random_bytes(32)) . "\n";
        $sealPath = $directory . '/.seal-' . $attemptId . '-' . bin2hex(random_bytes(16));
        $this->efsEvidence?->assertFresh();
        $file = @fopen($sealPath, 'x+b');
        $this->efsEvidence?->assertFresh();
        if ($file === false) {
            throw new \RuntimeException('Failed to create local multipart seal.');
        }
        try {
            $this->efsEvidence?->assertFresh();
            $protected = @chmod($sealPath, 0600);
            $this->efsEvidence?->assertFresh();
            if (! $protected) {
                throw new \RuntimeException('Failed to protect local multipart seal.');
            }
            $this->writeAll($file, $marker);
            $this->efsEvidence?->assertFresh();
            $synced = $this->io->syncFile($file);
            $this->efsEvidence?->assertFresh();
            $closed = $synced && @fclose($file);
            $this->efsEvidence?->assertFresh();
            if (! $synced || ! $closed) {
                throw new \RuntimeException('Failed to synchronize local multipart seal.');
            }
            $file = null;

            $this->efsEvidence?->assertFresh();
            $renamed = $this->io->rename($sealPath, $targetPath);
            $this->efsEvidence?->assertFresh();
            $this->syncDirectoryChain($directory);
            $this->efsEvidence?->assertFresh();
            if (! $this->hasMarker($targetPath, $marker)) {
                throw new \RuntimeException($renamed
                    ? 'Local multipart seal was replaced before verification.'
                    : 'Local multipart seal publication is uncertain.');
            }
        } finally {
            if (is_resource($file)) {
                // Descriptor-only best-effort cleanup; no new EFS path lookup.
                @fclose($file);
            }
            $this->efsEvidence?->assertFresh();
            @unlink($sealPath);
            $this->efsEvidence?->assertFresh();
        }
    }

    private function assertPath(string $path, ?string $requiredNamespace): void
    {
        $rootPrefix = $this->root . '/';
        if (! str_starts_with($path, $rootPrefix)) {
            throw new \InvalidArgumentException('Local multipart path escapes its storage root.');
        }
        $relative = substr($path, strlen($rootPrefix));
        $segments = explode('/', $relative);
        $namespace = $segments[0] ?? '';
        if ($requiredNamespace !== null ? $namespace !== $requiredNamespace
            : ! in_array($namespace, ['.parts', '.assemblies'], true)) {
            throw new \InvalidArgumentException('Local multipart path is outside its write namespace.');
        }
        if (count($segments) < 2) {
            throw new \InvalidArgumentException('Local multipart path has no target.');
        }
        $current = $this->root;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $this->efsEvidence?->assertFresh();
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid local multipart path segment.');
            }
            $current .= '/' . $segment;
            clearstatcache(true, $current);
            $linked = is_link($current);
            $this->efsEvidence?->assertFresh();
            $resolved = realpath($current);
            $this->efsEvidence?->assertFresh();
            if ($linked || $resolved !== $current) {
                throw new \InvalidArgumentException('Local multipart path has an unsafe directory.');
            }
            $this->assertPrivateEfsDirectory($current);
        }
        $basename = end($segments);
        $this->efsEvidence?->assertFresh();
        $linked = is_link($path);
        $this->efsEvidence?->assertFresh();
        if ($basename === '' || $basename === '.' || $basename === '..' || $linked) {
            throw new \InvalidArgumentException('Invalid local multipart target.');
        }
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, $this->inputRoot . '/')) {
            return $this->root . substr($path, strlen($this->inputRoot));
        }

        return $path;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! str_starts_with($directory, $this->root . '/')) {
            throw new \InvalidArgumentException('Local multipart seal escapes its storage root.');
        }
        $current = $this->root;
        foreach (explode('/', substr($directory, strlen($this->root) + 1)) as $segment) {
            $this->efsEvidence?->assertFresh();
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid local multipart seal directory.');
            }
            $current .= '/' . $segment;
            clearstatcache(true, $current);
            $linked = is_link($current);
            $this->efsEvidence?->assertFresh();
            if ($linked) {
                throw new \InvalidArgumentException('Local multipart seal directory is a symbolic link.');
            }
            $directoryExists = is_dir($current);
            $this->efsEvidence?->assertFresh();
            $created = $directoryExists;
            if (! $directoryExists) {
                $created = @mkdir($current, 0700);
                $this->efsEvidence?->assertFresh();
                if (! $created) {
                    $directoryExists = is_dir($current);
                    $this->efsEvidence?->assertFresh();
                }
            }
            if (! $directoryExists && ! $created) {
                throw new \RuntimeException('Failed to create local multipart seal directory.');
            }
            $resolved = realpath($current);
            $this->efsEvidence?->assertFresh();
            if ($resolved !== $current) {
                throw new \InvalidArgumentException('Local multipart seal directory escapes its storage root.');
            }
            $this->assertPrivateEfsDirectory($current);
        }
    }

    private function assertPrivateEfsDirectory(string $path): void
    {
        if ($this->efsEvidence === null) {
            return;
        }
        $this->efsEvidence->assertFresh();
        $entry = @lstat($path);
        $this->efsEvidence->assertFresh();
        if (! function_exists('posix_geteuid') || $entry === false
            || ($entry['mode'] & 0o170000) !== 0o040000
            || ($entry['mode'] & 0o077) !== 0
            || $entry['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Guarded local multipart directory must be private and process-owned.');
        }
    }

    private function syncDirectoryChain(string $directory): void
    {
        while (true) {
            $this->efsEvidence?->assertFresh();
            if ($this->efsEvidence !== null && ! $this->io instanceof EfsAwareLocalObjectWriteIo) {
                throw new \LogicException('Guarded multipart I/O lost evidence-aware directory synchronization.');
            }
            $synced = $this->efsEvidence !== null
                ? $this->io->syncDirectoryWithEvidence($directory, $this->efsEvidence)
                : $this->io->syncDirectory($directory);
            $this->efsEvidence?->assertFresh();
            if (! $synced) {
                throw new \RuntimeException('Failed to synchronize local multipart directory.');
            }
            if ($directory === $this->root) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    /** @param resource $file */
    private function writeAll($file, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->efsEvidence?->assertFresh();
            $written = @fwrite($file, substr($bytes, $offset));
            $this->efsEvidence?->assertFresh();
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write local multipart seal.');
            }
            $offset += $written;
        }
    }

    private function hasMarker(string $path, string $marker): bool
    {
        $length = strlen($marker);
        if ($length === 0) {
            return false;
        }
        $this->efsEvidence?->assertFresh();
        $file = @fopen($path, 'rb');
        $this->efsEvidence?->assertFresh();
        if ($file === false) {
            return false;
        }
        try {
            $stat = @fstat($file);
            $this->efsEvidence?->assertFresh();
            if ($stat === false || $stat['size'] !== $length) {
                return false;
            }
            $actual = '';
            while (strlen($actual) < $length) {
                $this->efsEvidence?->assertFresh();
                $remaining = $length - strlen($actual);
                if ($remaining < 1) {
                    break;
                }
                $chunk = @fread($file, $remaining);
                $this->efsEvidence?->assertFresh();
                if ($chunk === false || $chunk === '') {
                    return false;
                }
                $actual .= $chunk;
            }

            return hash_equals($marker, $actual);
        } finally {
            // Descriptor-only best-effort cleanup after the last validated read.
            @fclose($file);
        }
    }
}
