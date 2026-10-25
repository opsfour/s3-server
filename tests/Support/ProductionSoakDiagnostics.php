<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

final class ProductionSoakDiagnostics
{
    private const MAX_REJECTIONS = 100;

    private const MAX_HANDLER_ERROR_BYTES = 512;

    private const MAX_LOG_BYTES = 256 * 1024;

    private const MAX_MEMORY_SERIES_BYTES = 1024 * 1024;

    private const RETAINED_MEMORY_SERIES_BYTES = 512 * 1024;

    /**
     * @return array{limit: int|null, current: int|null, peak: int|null, anon: int|null, file: int|null, shmem: int|null, kernel: int|null, slab: int|null}
     */
    public static function cgroupMemorySnapshot(string $directory = '/sys/fs/cgroup'): array
    {
        $statistics = [];
        $contents = @file_get_contents($directory . '/memory.stat');
        if (is_string($contents)) {
            foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
                if (preg_match('/\A([a-z_]+) ([0-9]+)\z/', $line, $matches) === 1) {
                    $statistics[$matches[1]] = (int) $matches[2];
                }
            }
        }

        return [
            'limit' => self::readUnsignedInteger($directory . '/memory.max'),
            'current' => self::readUnsignedInteger($directory . '/memory.current'),
            'peak' => self::readUnsignedInteger($directory . '/memory.peak'),
            'anon' => $statistics['anon'] ?? null,
            'file' => $statistics['file'] ?? null,
            'shmem' => $statistics['shmem'] ?? null,
            'kernel' => $statistics['kernel'] ?? null,
            'slab' => $statistics['slab'] ?? null,
        ];
    }

    /**
     * @param array{limit: int|null, current: int|null, peak: int|null, anon: int|null, file: int|null, shmem: int|null, kernel: int|null, slab: int|null} $cgroup
     */
    public static function persistMemorySample(
        string $directory,
        int $iteration,
        string $stage,
        int $warmupBatches,
        ?int $serverRssBytes,
        array $cgroup,
    ): void {
        if (! in_array($stage, ['warmup', 'baseline', 'steady', 'final'], true)) {
            throw new \InvalidArgumentException('Unknown production soak memory-sample stage.');
        }
        self::assertDirectory($directory);
        $path = $directory . '/memory.tsv';
        $header = "captured_at\titeration\tstage\twarmup_batches\tserver_rss_bytes\tcgroup_limit_bytes"
            . "\tcgroup_current_bytes\tcgroup_peak_bytes"
            . "\tcgroup_anon_bytes\tcgroup_file_bytes\tcgroup_shmem_bytes\tcgroup_kernel_bytes"
            . "\tcgroup_slab_bytes\n";
        $line = implode("\t", [
            gmdate('Y-m-d\TH:i:s\Z'),
            (string) $iteration,
            $stage,
            (string) $warmupBatches,
            self::nullableInteger($serverRssBytes),
            self::nullableInteger($cgroup['limit']),
            self::nullableInteger($cgroup['current']),
            self::nullableInteger($cgroup['peak']),
            self::nullableInteger($cgroup['anon']),
            self::nullableInteger($cgroup['file']),
            self::nullableInteger($cgroup['shmem']),
            self::nullableInteger($cgroup['kernel']),
            self::nullableInteger($cgroup['slab']),
        ]) . "\n";

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the production soak memory series.');
        }

        $rotated = false;
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the production soak memory series.');
            }
            $status = fstat($handle);
            if ($status === false) {
                throw new \RuntimeException('Could not inspect the production soak memory series.');
            }
            $prefix = $status['size'] === 0 ? $header : '';
            $payload = $prefix . $line;
            if ($status['size'] + strlen($payload) > self::MAX_MEMORY_SERIES_BYTES) {
                $tailBytes = min(self::RETAINED_MEMORY_SERIES_BYTES, $status['size']);
                if (fseek($handle, -$tailBytes, SEEK_END) !== 0) {
                    throw new \RuntimeException('Could not seek in the production soak memory series.');
                }
                $tail = stream_get_contents($handle);
                if ($tail === false) {
                    throw new \RuntimeException('Could not read the production soak memory-series tail.');
                }
                if ($status['size'] > $tailBytes) {
                    $firstLineEnd = strpos($tail, "\n");
                    $tail = $firstLineEnd === false ? '' : substr($tail, $firstLineEnd + 1);
                }
                if (ftruncate($handle, 0) === false || rewind($handle) === false) {
                    throw new \RuntimeException('Could not rotate the production soak memory series.');
                }
                self::writeStream($handle, $header . $tail . $line);
                $rotated = true;
            } else {
                if (fseek($handle, 0, SEEK_END) !== 0) {
                    throw new \RuntimeException('Could not seek in the production soak memory series.');
                }
                self::writeStream($handle, $payload);
            }
            if (! fflush($handle)) {
                throw new \RuntimeException('Could not flush the production soak memory series.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (! chmod($path, 0o600)) {
            throw new \RuntimeException('Could not restrict production soak memory-series permissions.');
        }
        if ($rotated) {
            self::writePrivateFile(
                $directory . '/memory-series-rotated',
                'rotated_at=' . gmdate('Y-m-d\TH:i:s\Z') . "\n",
            );
        }
    }

    /**
     * @return array{
     *     index: int|string,
     *     exception_class: class-string<\Throwable>|string,
     *     aws_error_code: string|null,
     *     http_status: int|null,
     *     handler_errno: int|null,
     *     handler_error: string|null
     * }
     */
    public static function rejection(int|string $index, mixed $reason): array
    {
        $exceptionClass = 'non-throwable rejection';
        $awsErrorCode = null;
        $httpStatus = null;
        $handlerErrno = null;
        $handlerError = null;

        if ($reason instanceof \Throwable) {
            $exceptionClass = $reason::class;

            for ($error = $reason; $error !== null; $error = $error->getPrevious()) {
                if ($error instanceof AwsException) {
                    $awsErrorCode ??= self::safeToken($error->getAwsErrorCode());
                    $status = $error->getStatusCode();
                    $httpStatus ??= is_int($status) ? $status : null;
                }

                if ($error instanceof RequestException || $error instanceof ConnectException) {
                    $context = $error->getHandlerContext();
                    $handlerErrno ??= self::safeInteger($context['errno'] ?? null);
                    $handlerError ??= self::safeHandlerError($context['error'] ?? null);

                    if ($error instanceof RequestException && $error->hasResponse()) {
                        $httpStatus ??= $error->getResponse()?->getStatusCode();
                    }
                }
            }
        }

        return [
            'index' => self::safeIndex($index),
            'exception_class' => $exceptionClass,
            'aws_error_code' => $awsErrorCode,
            'http_status' => $httpStatus,
            'handler_errno' => $handlerErrno,
            'handler_error' => $handlerError,
        ];
    }

    /**
     * @param list<array<string, int|string|null>> $rejections
     */
    public static function persistRejections(string $directory, array $rejections): void
    {
        self::writeJson($directory, 'command-pool-rejections.json', [
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'rejections' => array_slice($rejections, 0, self::MAX_REJECTIONS),
            'truncated' => count($rejections) > self::MAX_REJECTIONS,
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public static function persistRuntime(string $directory, array $snapshot): void
    {
        self::writeJson($directory, 'runtime.json', $snapshot);
    }

    public static function persistLogTail(string $source, string $directory, string $filename): void
    {
        self::assertDirectory($directory);
        if ($source === '' || ! is_file($source)) {
            self::writePrivateFile($directory . '/' . $filename, '');

            return;
        }

        $size = filesize($source);
        if ($size === false) {
            throw new \RuntimeException("Could not inspect production soak log: {$filename}");
        }

        $offset = max(0, $size - self::MAX_LOG_BYTES);
        $contents = file_get_contents($source, false, null, $offset, self::MAX_LOG_BYTES);
        if ($contents === false) {
            throw new \RuntimeException("Could not read production soak log: {$filename}");
        }

        self::writePrivateFile(
            $directory . '/' . $filename,
            substr(self::redactText($contents), 0, self::MAX_LOG_BYTES),
        );
    }

    private static function safeIndex(int|string $index): int|string
    {
        if (is_int($index) || ctype_digit($index)) {
            return $index;
        }

        return 'string-sha256:' . hash('sha256', $index);
    }

    private static function readUnsignedInteger(string $path): ?int
    {
        $value = @file_get_contents($path);
        if ($value === false || ! ctype_digit(trim($value))) {
            return null;
        }

        return (int) trim($value);
    }

    private static function nullableInteger(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /** @param resource $handle */
    private static function writeStream($handle, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not persist the production soak memory series.');
            }
            $offset += $written;
        }
    }

    private static function safeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?[0-9]{1,10}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function safeToken(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private static function safeHandlerError(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr(self::redactText($value), 0, self::MAX_HANDLER_ERROR_BYTES);
    }

    private static function redactText(string $contents): string
    {
        $contents = preg_replace(
            '~\b(?:https?|ftp)://[^\s\"\'<>]+~i',
            '[redacted-url]',
            $contents,
        ) ?? '';
        $contents = preg_replace(
            '/\b(GET|HEAD|PUT|POST|DELETE|OPTIONS|PATCH) \/\S*/',
            '$1 [redacted-path]',
            $contents,
        ) ?? '';
        $contents = preg_replace(
            '/("(?:path|storage)"\s*:\s*")[^"]*(")/i',
            '$1[redacted-path]$2',
            $contents,
        ) ?? '';
        $contents = preg_replace(
            '/(?im)^.*(?:authorization|x-amz-(?:credential|signature|security-token)|awsaccesskeyid|secret[ _-]?key|access[ _-]?key|password).*$/',
            '[redacted-sensitive-line]',
            $contents,
        ) ?? '';

        return $contents;
    }

    /** @param array<string, mixed> $contents */
    private static function writeJson(string $directory, string $filename, array $contents): void
    {
        $json = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        self::assertDirectory($directory);
        self::writePrivateFile($directory . '/' . $filename, $json);
    }

    private static function assertDirectory(string $directory): void
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new \RuntimeException('Production soak diagnostics directory must exist and be writable.');
        }
    }

    private static function writePrivateFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not persist production soak diagnostics.');
        }
        if (! chmod($path, 0o600)) {
            throw new \RuntimeException('Could not restrict production soak diagnostic permissions.');
        }
    }
}
