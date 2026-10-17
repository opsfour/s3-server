<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;

/**
 * Process-lifetime ownership of every configured local filesystem storage root.
 *
 * The lock prevents two API processes from concurrently using a storage layout
 * whose local publish and cleanup operations are qualified for one process.
 */
final class LocalStorageRootLocks
{
    private const string INTERNAL_DIRECTORY = '.opsfour';

    private const string LOCK_FILE = 'runtime.lock';

    /** @var list<resource> */
    private array $handles = [];

    /** @var list<string> */
    private array $acquiredRoots = [];

    /** @var array<int, resource> Intentionally leaked until PHP closes process resources. */
    private static array $retainedHandles = [];

    private bool $retained = false;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /** @param iterable<StorageBackend> $backends */
    public function acquireBackends(iterable $backends): void
    {
        $roots = [];

        foreach ($backends as $backend) {
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof FilesystemBackend) {
                continue;
            }

            $roots[] = $backend->getBasePath();
        }

        $this->acquireRoots($roots);
    }

    /**
     * Initialize and acquire roots. The persistent internal lock file is
     * control state and may be created by this operation.
     *
     * @param iterable<string> $configuredRoots
     */
    public function acquireRoots(iterable $configuredRoots): void
    {
        $this->acquireConfiguredRoots($configuredRoots);
    }

    /** @param iterable<string> $configuredRoots */
    private function acquireConfiguredRoots(iterable $configuredRoots): void
    {
        $roots = [];
        foreach ($configuredRoots as $configuredRoot) {
            $root = self::canonicalRoot($configuredRoot);
            $roots[$root] = true;
        }

        $paths = array_keys($roots);
        sort($paths, SORT_STRING);
        foreach ($paths as $index => $root) {
            foreach (array_slice($paths, 0, $index) as $otherRoot) {
                if (str_starts_with($root, rtrim($otherRoot, '/') . '/')) {
                    throw new \RuntimeException('Configured local storage roots must not overlap.');
                }
            }
        }

        if ($this->handles !== []) {
            if ($paths === $this->acquiredRoots) {
                return;
            }

            throw new \LogicException('Local storage root ownership was already acquired for different roots.');
        }

        try {
            foreach ($paths as $root) {
                $this->acquireRoot($root);
            }
            $this->acquiredRoots = $paths;
        } catch (\Throwable $error) {
            $this->release();
            throw $error;
        }
    }

    public function release(): void
    {
        foreach (array_reverse($this->handles) as $handle) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->handles = [];
        $this->acquiredRoots = [];
        $this->retained = false;
    }

    /** Keep ownership when shutdown cannot prove that every writer stopped. */
    public function retainUntilProcessExit(): void
    {
        if ($this->retained || $this->handles === []) {
            return;
        }

        $this->retained = true;
        foreach ($this->handles as $handle) {
            self::$retainedHandles[count(self::$retainedHandles)] = $handle;
        }
    }

    private static function canonicalRoot(string $configuredRoot): string
    {
        if (! is_dir($configuredRoot)) {
            if (! @mkdir($configuredRoot, 0o700, true) && ! is_dir($configuredRoot)) {
                throw new \RuntimeException('Cannot prepare a configured local storage root for exclusive ownership.');
            }
        }

        $root = realpath($configuredRoot);
        if ($root === false || ! is_dir($root)) {
            throw new \RuntimeException('Cannot resolve a configured local storage root for exclusive ownership.');
        }

        return $root;
    }

    private function acquireRoot(string $root): void
    {
        $internalDirectory = $root . '/' . self::INTERNAL_DIRECTORY;
        if (is_link($internalDirectory)) {
            throw new \RuntimeException('Cannot use the reserved local storage runtime namespace.');
        }
        if (! is_dir($internalDirectory)) {
            if (! @mkdir($internalDirectory, 0o700) && ! is_dir($internalDirectory)) {
                throw new \RuntimeException('Cannot create the reserved local storage runtime namespace.');
            }
        }

        $canonicalInternalDirectory = realpath($internalDirectory);
        if ($canonicalInternalDirectory === false
            || dirname($canonicalInternalDirectory) !== $root) {
            throw new \RuntimeException('Cannot use the reserved local storage runtime namespace.');
        }

        $lockPath = $canonicalInternalDirectory . '/' . self::LOCK_FILE;
        if (is_link($lockPath)) {
            throw new \RuntimeException('Cannot use the reserved local storage runtime lock.');
        }
        clearstatcache(true, $lockPath);
        $existingStat = @lstat($lockPath);
        if ($existingStat !== false
            && (($existingStat['mode'] & 0o170000) !== 0o100000 || $existingStat['nlink'] !== 1)) {
            throw new \RuntimeException('Cannot safely acquire the local storage runtime lock.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot acquire exclusive ownership of a configured local storage root.');
        }

        try {
            $handleStat = @fstat($handle);
            if ($handleStat === false
                || ($handleStat['mode'] & 0o170000) !== 0o100000
                || $handleStat['nlink'] !== 1) {
                throw new \RuntimeException('Cannot safely acquire the local storage runtime lock.');
            }
            if (! @chmod($lockPath, 0o600)) {
                throw new \RuntimeException('Cannot protect the local storage runtime lock.');
            }
            clearstatcache(true, $lockPath);
            $pathStat = @lstat($lockPath);
            if ($pathStat === false
                || ($pathStat['mode'] & 0o170000) !== 0o100000
                || $pathStat['nlink'] !== 1
                || $pathStat['dev'] !== $handleStat['dev']
                || $pathStat['ino'] !== $handleStat['ino']) {
                throw new \RuntimeException('Cannot safely acquire the local storage runtime lock.');
            }
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException(
                    'A configured local storage root is already owned by another S3 server process.',
                );
            }

            $this->handles[] = $handle;
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }
}
