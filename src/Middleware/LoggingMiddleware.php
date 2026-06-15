<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
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
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $startTime = hrtime(true);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        $fullPath = $query !== '' ? "{$path}?{$query}" : $path;
        $requestContentLength = $request->getHeader('Content-Length') ?? '-';
        $requestId = $request->getAttribute('requestId') ?? '-';

        $response = $requestHandler->handleRequest($request);

        $elapsedNs = hrtime(true) - $startTime;
        $elapsedMs = round($elapsedNs / 1_000_000, 2);

        $statusCode = $response->getStatus();
        $responseContentLength = $response->getHeader('Content-Length') ?? '-';

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

        return $response;
    }
}
