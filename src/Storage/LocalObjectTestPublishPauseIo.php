<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * One-shot, node-local pause immediately before a fenced object's hard link.
 * Runtime installs this only inside the explicit unsafe qualification profile.
 * The private scratch control directory never contains object keys or bytes.
 */
final readonly class LocalObjectTestPublishPauseIo implements LocalObjectWriteIo
{
    public const string CONTROL_DIRECTORY = '.s3-local-publish-pause';

    private string $controlDirectory;

    public function __construct(
        string $scratchDirectory,
        private int $timeoutSeconds = 450,
        private LocalObjectWriteIo $inner = new NativeLocalObjectWriteIo(),
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 600) {
            throw new \InvalidArgumentException('Local test publish pause timeout must be between 1 and 600 seconds.');
        }

        $scratch = realpath($scratchDirectory);
        if ($scratch === false || ! self::isPrivateDirectory($scratch)) {
            throw new \InvalidArgumentException('Local test publish pause requires private node-local scratch.');
        }

        $control = $scratch . '/' . self::CONTROL_DIRECTORY;
        if (! is_dir($control) && ! @mkdir($control, 0700) && ! is_dir($control)) {
            throw new \RuntimeException('Cannot create local test publish pause control directory.');
        }
        if (is_link($control) || realpath($control) !== $control || ! self::isPrivateDirectory($control)) {
            throw new \InvalidArgumentException('Local test publish pause control directory must be private.');
        }
        $this->controlDirectory = $control;
    }

    public function syncFile($file): bool
    {
        return $this->inner->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->inner->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        $control = $this->controlDirectory;
        if (is_link($control) || realpath($control) !== $control || ! self::isPrivateDirectory($control)) {
            throw new \RuntimeException('Local test publish pause control directory changed before link.');
        }

        $arm = $control . '/arm';
        $armStat = self::currentMarkerStat($arm);
        if ($armStat === false) {
            return $this->inner->link($source, $target);
        }
        if (! self::isPrivateEmptyFile($arm, $armStat)) {
            throw new \RuntimeException('Local test publish pause arm marker must be private and empty.');
        }

        $id = hash('sha256', $target);
        $claimed = $control . '/claimed-' . $id;
        $ready = $control . '/ready-' . $id;
        $release = $control . '/release-' . $id;
        $result = $control . '/result-' . $id;
        if (self::currentMarkerStat($claimed) !== false
            || self::currentMarkerStat($ready) !== false
            || self::currentMarkerStat($release) !== false
            || self::currentMarkerStat($result) !== false) {
            throw new \RuntimeException('Local test publish pause marker already exists for this path.');
        }

        // Renaming one shared arm marker ensures only one worker is paused.
        if (! @rename($arm, $claimed)) {
            if (self::currentMarkerStat($arm) !== false) {
                throw new \RuntimeException('Cannot claim local test publish pause arm marker.');
            }

            return $this->inner->link($source, $target);
        }

        try {
            $signal = @fopen($ready, 'x+b');
            if ($signal === false) {
                throw new \RuntimeException('Cannot signal local test publish pause readiness.');
            }
            try {
                if (! @chmod($ready, 0600) || @fwrite($signal, "ready\n") !== 6 || ! @fflush($signal)) {
                    throw new \RuntimeException('Cannot protect local test publish pause readiness.');
                }
            } finally {
                @fclose($signal);
            }

            $deadline = hrtime(true) + $this->timeoutSeconds * 1_000_000_000;
            while (hrtime(true) < $deadline) {
                $releaseStat = self::currentMarkerStat($release);
                if ($releaseStat !== false) {
                    if (! self::isPrivateEmptyFile($release, $releaseStat)) {
                        throw new \RuntimeException('Local test publish pause release marker must be private and empty.');
                    }

                    $linked = $this->inner->link($source, $target);
                    $this->writeResult($result, $linked);

                    return $linked;
                }
                usleep(20_000);
            }
            throw new \RuntimeException('Local test publish pause timed out before the physical link.');
        } finally {
            @unlink($ready);
            @unlink($claimed);
            @unlink($release);
        }
    }

    public function rename(string $source, string $target): bool
    {
        return $this->inner->rename($source, $target);
    }

    private static function isPrivateDirectory(string $path): bool
    {
        $stat = @stat($path);

        return $stat !== false && is_dir($path) && ($stat['mode'] & 0077) === 0
            && function_exists('posix_geteuid') && $stat['uid'] === posix_geteuid();
    }

    private function writeResult(string $path, bool $linked): void
    {
        $file = @fopen($path, 'x+b');
        if ($file === false) {
            throw new \RuntimeException('Cannot record local test publish pause link result.');
        }
        try {
            $message = $linked ? "linked\n" : "denied\n";
            if (! @chmod($path, 0600) || @fwrite($file, $message) !== strlen($message) || ! @fflush($file)) {
                throw new \RuntimeException('Cannot protect local test publish pause link result.');
            }
        } finally {
            @fclose($file);
        }
    }

    /**
     * Marker state can change in the qualification controller between polls.
     *
     * @return array<string|int, int>|false
     * @phpstan-impure
     */
    private static function currentMarkerStat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    /** @param array<string|int, int> $stat */
    private static function isPrivateEmptyFile(string $path, array $stat): bool
    {
        return ! is_link($path) && is_file($path) && ($stat['mode'] & 0077) === 0
            && $stat['size'] === 0 && function_exists('posix_geteuid')
            && $stat['uid'] === posix_geteuid();
    }
}
