<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\S3Exception;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Routing\S3Operation;
use Psr\Log\LoggerInterface;

/**
 * Logs S3 request/response details at PSR-3 info level.
 *
 * Records: HTTP method, path, status code, response time (ms),
 * request Content-Length, and response Content-Length.
 */
final class LoggingMiddleware implements Middleware
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?AccessLogWriter $accessLogs = null,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $startTime = hrtime(true);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $query = self::redactSensitiveQueryValues($request->getUri()->getQuery());
        $fullPath = $query !== '' ? "{$path}?{$query}" : $path;
        $requestContentLength = $request->getHeader('Content-Length') ?? '-';
        $requestId = $request->hasAttribute('requestId')
            ? (string) $request->getAttribute('requestId')
            : '-';

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $e) {
            $this->writeLogEntry(
                $request,
                $method,
                $fullPath,
                $requestContentLength,
                $requestId,
                $e instanceof S3Exception ? $e->getHttpStatus() : 500,
                '-',
                $startTime,
            );

            throw $e;
        }

        $this->writeLogEntry(
            $request,
            $method,
            $fullPath,
            $requestContentLength,
            $requestId,
            $response->getStatus(),
            $response->getHeader('Content-Length') ?? '-',
            $startTime,
        );

        return $response;
    }

    private function writeLogEntry(
        Request $request,
        string $method,
        string $fullPath,
        string $requestContentLength,
        string $requestId,
        int $statusCode,
        string $responseContentLength,
        int $startTime,
    ): void {
        $elapsedMs = round((hrtime(true) - $startTime) / 1_000_000, 2);
        $this->logger->info(
            '{method} {path} {status} {time}ms',
            [
                'method' => $method,
                'path' => $fullPath,
                'status' => $statusCode,
                'time' => $elapsedMs,
                'requestId' => $requestId,
                'requestContentLength' => $requestContentLength,
                'responseContentLength' => $responseContentLength,
            ],
        );

        $bucket = $request->hasAttribute('s3.bucket')
            ? (string) $request->getAttribute('s3.bucket')
            : '';
        if ($bucket !== '') {
            $operation = $request->hasAttribute('s3.operation')
                ? $request->getAttribute('s3.operation')
                : null;
            $this->accessLogs?->log(
                bucket: $bucket,
                key: $request->hasAttribute('s3.key') ? (string) $request->getAttribute('s3.key') : '',
                operation: $operation instanceof S3Operation ? $operation->value : $method,
                httpStatus: $statusCode,
                bytesTransferred: is_numeric($responseContentLength) ? (int) $responseContentLength : 0,
                remoteIp: $request->getClient()->getRemoteAddress()->toString(),
                requesterId: $request->hasAttribute('ownerId') ? (string) $request->getAttribute('ownerId') : '',
            );
        }
    }

    private static function redactSensitiveQueryValues(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $sensitive = [
            'awsaccesskeyid' => true,
            'signature' => true,
            'securitytoken' => true,
            'x-amz-credential' => true,
            'x-amz-security-token' => true,
            'x-amz-signature' => true,
        ];
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$rawName] = array_pad(explode('=', $pair, 2), 2, '');
            if (isset($sensitive[strtolower(rawurldecode($rawName))])) {
                $pairs[] = $rawName . '=' . rawurlencode('[REDACTED]');
            } else {
                $pairs[] = $pair;
            }
        }

        return implode('&', $pairs);
    }
}
