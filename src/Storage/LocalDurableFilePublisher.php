<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * Blocking local filesystem durability primitive. Invoke it from a worker.
 *
 * The storage root is exclusively owned by the S3 process. Path validation
 * still rejects escapes and symbolic-link parents before every operation.
 */
final readonly class LocalDurableFilePublisher
{
    private string $root;

    private string $inputRoot;

    public function __construct(
        string $storageRoot,
        private LocalDurableFileIo $io = new NativeLocalObjectWriteIo(),
        bool $createRoot = false,
    ) {
        $this->inputRoot = rtrim($storageRoot, '/');
        $root = realpath($storageRoot);
        if ($root === false && $createRoot) {
            $root = $this->createStorageRoot($this->inputRoot);
        }
        if ($root === false) {
            throw new \RuntimeException('Local storage root is unavailable.');
        }
        if (! is_dir($root)) {
            throw new \InvalidArgumentException('Local storage root must be a directory.');
        }
        $this->root = rtrim($root, '/');
    }

    /** Publish a complete staging file at a previously unused target path. */
    public function publish(string $stagedPath, string $targetPath): void
    {
        $stagedPath = $this->validateFilePath($stagedPath, true, true);
        $targetPath = $this->validateFilePath($targetPath, false, false);

        $file = @fopen($stagedPath, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Local staging file is unavailable.');
        }
        try {
            $stat = @fstat($file);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                throw new \RuntimeException('Local staging path is not a regular file.');
            }
            if (! $this->io->syncFile($file)) {
                throw new \RuntimeException('Failed to synchronize local staging data.');
            }
        } finally {
            @fclose($file);
        }

        // A failed acknowledgement may follow a successful link. Never remove
        // either path here; create-only publication keeps the target occupied.
        if (! $this->io->link($stagedPath, $targetPath)) {
            throw new \RuntimeException('Local create-only publication is uncertain.');
        }
        if (! $this->io->syncDirectory(dirname($targetPath))) {
            throw new \RuntimeException('Failed to synchronize local publication directory.');
        }
        if (! $this->io->unlink($stagedPath)) {
            throw new \RuntimeException('Local staging cleanup is uncertain.');
        }
        if (! $this->io->syncDirectory(dirname($stagedPath))) {
            throw new \RuntimeException('Failed to synchronize local staging directory.');
        }
    }

    /** Create a directory chain and make every directory entry durable. */
    public function ensureDirectory(string $path): void
    {
        $path = $this->normalizeDescendant($path);
        $current = $this->root;
        foreach (explode('/', substr($path, strlen($this->root) + 1)) as $segment) {
            $this->assertSegment($segment);
            $current .= '/' . $segment;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false && ! @mkdir($current, 0700) && ! is_dir($current)) {
                throw new \RuntimeException('Failed to create local storage directory.');
            }
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || realpath($current) !== $current) {
                throw new \InvalidArgumentException('Local storage directory is unsafe.');
            }
        }

        $directory = $path;
        while (true) {
            if (! $this->io->syncDirectory($directory)) {
                throw new \RuntimeException('Failed to synchronize local storage directory.');
            }
            if ($directory === $this->root) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    /** Delete a file and durably record its absence. */
    public function delete(string $path): void
    {
        $path = $this->normalizeDescendant($path);
        $segments = explode('/', substr($path, strlen($this->root) + 1));
        if (count($segments) < 2) {
            throw new \InvalidArgumentException('Local delete path has no parent below its storage root.');
        }
        foreach ($segments as $segment) {
            $this->assertSegment($segment);
        }

        $directory = $this->root;
        $parentExists = true;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $candidate = $directory . '/' . $segment;
            clearstatcache(true, $candidate);
            $stat = @lstat($candidate);
            if ($stat === false) {
                $parentExists = false;
                break;
            }
            if (($stat['mode'] & 0170000) !== 0040000 || realpath($candidate) !== $candidate) {
                throw new \InvalidArgumentException('Local delete path has an unsafe parent directory.');
            }
            $directory = $candidate;
        }

        if ($parentExists) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (is_array($stat)) {
                if (($stat['mode'] & 0170000) !== 0100000) {
                    throw new \InvalidArgumentException('Local delete path is not a regular file.');
                }
                if (! $this->io->unlink($path)) {
                    throw new \RuntimeException('Local file deletion is uncertain.');
                }
            }
        }
        if (! $this->io->syncDirectory($directory)) {
            throw new \RuntimeException('Failed to synchronize local deletion directory.');
        }
    }

    private function validateFilePath(string $path, bool $mustExist, bool $staging): string
    {
        $path = $this->normalizeDescendant($path);
        $relative = substr($path, strlen($this->root) + 1);
        $segments = explode('/', $relative);
        if (count($segments) < 2) {
            throw new \InvalidArgumentException('Local file path has no parent below its storage root.');
        }
        if (($segments[0] === '.tmp') !== $staging) {
            throw new \InvalidArgumentException($staging
                ? 'Local staging path is outside the temporary namespace.'
                : 'Local target path uses the temporary namespace.');
        }

        $current = $this->root;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $this->assertSegment($segment);
            $current .= '/' . $segment;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || realpath($current) !== $current) {
                throw new \InvalidArgumentException('Local file path has an unsafe parent directory.');
            }
        }
        $this->assertSegment($segments[array_key_last($segments)]);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($mustExist && (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000)) {
            throw new \InvalidArgumentException('Local staging path is not a regular file.');
        }
        if (! $mustExist && is_array($stat) && ($stat['mode'] & 0170000) !== 0100000) {
            throw new \InvalidArgumentException('Local file path is not a regular file.');
        }

        return $path;
    }

    private function normalizeDescendant(string $path): string
    {
        if (str_starts_with($path, $this->inputRoot . '/')) {
            $path = $this->root . substr($path, strlen($this->inputRoot));
        }
        if ($path === '' || ! str_starts_with($path, $this->root . '/')) {
            throw new \InvalidArgumentException('Local path escapes its storage root.');
        }

        return $path;
    }

    private function createStorageRoot(string $path): string
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Local storage root must be an absolute directory.');
        }

        $missing = [];
        $ancestor = $path;
        while (realpath($ancestor) === false) {
            $segment = basename($ancestor);
            $this->assertSegment($segment);
            array_unshift($missing, $segment);
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new \InvalidArgumentException('Local storage root has no existing ancestor.');
            }
            $ancestor = $parent;
        }

        $canonicalAncestor = realpath($ancestor);
        if ($canonicalAncestor === false || ! is_dir($canonicalAncestor)) {
            throw new \InvalidArgumentException('Local storage root has an unsafe ancestor.');
        }
        $current = rtrim($canonicalAncestor, '/') ?: '/';
        foreach ($missing as $segment) {
            $current = $current === '/' ? '/' . $segment : $current . '/' . $segment;
            clearstatcache(true, $current);
            if (! @mkdir($current, 0700) && ! is_dir($current)) {
                throw new \RuntimeException('Failed to create local storage root.');
            }
            $stat = @lstat($current);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || realpath($current) !== $current) {
                throw new \InvalidArgumentException('Local storage root is unsafe.');
            }
        }

        $root = $current;
        while (true) {
            if (! $this->io->syncDirectory($current)) {
                throw new \RuntimeException('Failed to synchronize local storage root.');
            }
            if ($current === (rtrim($canonicalAncestor, '/') ?: '/')) {
                return $root;
            }
            $current = dirname($current);
        }
    }

    private function assertSegment(string $segment): void
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new \InvalidArgumentException('Invalid local path segment.');
        }
    }
}
