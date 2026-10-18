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
