<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\CancelledException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\S3Exception;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use Psr\Log\LoggerInterface;

/** Keeps reserved management paths and failures outside S3 routing and XML errors. */
final class ManagementBoundaryMiddleware implements Middleware
{
    private const int MAX_PERCENT_DECODE_DEPTH = 4;

    private readonly FailureIsolatingLogger $logger;

    public function __construct(
        private readonly bool $credentialsEnabled,
        private readonly bool $quotasEnabled,
        LoggerInterface $logger,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        // Keep the reserved namespace outside the S3 fallback even when a
        // proxy and the application decode different numbers of times.
        $encodedPath = $request->getUri()->getPath();
        $routerPath = rawurldecode($encodedPath);
        $path = self::fullyDecodedPath($encodedPath);
        $kind = $this->kind($path);
        if ($kind === null) {
            return $requestHandler->handleRequest($request);
        }
        // Amp Router decodes once. A reserved path that appears only after
        // additional passes cannot be dispatched reliably, even when its HTTP
        // method would otherwise be allowed, so never send it to the fallback.
        if ($path !== $routerPath) {
            return $this->error($kind, 404);
        }

        $allowed = $this->allowedMethods($path);
        if ($allowed === null) {
            return $this->error($kind, 404);
        }
        if (!in_array($request->getMethod(), $allowed, true)) {
            $response = $this->error($kind, 405);
            $response->setHeader('allow', implode(', ', $allowed));

            return $response;
        }

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->logger->error('Management request failed.', ['exception' => $error::class]);
            $status = $kind === 'probe' ? 503 : ($error instanceof S3Exception ? $error->getHttpStatus() : 500);

            return $this->error($kind, $status >= 400 && $status <= 599 ? $status : 500);
        }

        // Shared admission and rate limiting use S3 XML for data-plane calls.
        // The management surface must keep its own media type and generic body.
        if ($response->getStatus() >= 400
            && str_starts_with(strtolower((string) $response->getHeader('content-type')), 'application/xml')) {
            $replacement = $this->error($kind, $response->getStatus());
            if ($response->getHeader('retry-after') !== null) {
                $replacement->setHeader('retry-after', (string) $response->getHeader('retry-after'));
            }
            $response->getBody()->close();

            return $replacement;
        }

        $response->setHeader('cache-control', 'no-store');

        return $response;
    }

    private function kind(string $path): ?string
    {
        if ($path === '/.live' || str_starts_with($path, '/.live/')) {
            return 'probe';
        }
        if ($path === '/.health' || str_starts_with($path, '/.health/')) {
            return 'probe';
        }
        if ($path === '/.metrics' || str_starts_with($path, '/.metrics/')) {
            return 'metrics';
        }
        if ($path === '/.admin' || str_starts_with($path, '/.admin/')) {
            return 'admin';
        }

        return null;
    }

    private static function fullyDecodedPath(string $path): string
    {
        for ($depth = 0; $depth <= self::MAX_PERCENT_DECODE_DEPTH; ++$depth) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                return $path;
            }
            if ($depth === self::MAX_PERCENT_DECODE_DEPTH) {
                throw new InvalidArgumentException('Request path exceeds the percent-decoding limit.');
            }
            $path = $decoded;
        }

        throw new \LogicException('Unreachable percent-decoding state.');
    }

    /** @return list<string>|null */
    private function allowedMethods(string $path): ?array
    {
        if (in_array($path, ['/.live', '/.health', '/.metrics'], true)) {
            return ['GET'];
        }
        if ($this->credentialsEnabled) {
            if ($path === '/.admin/credentials') {
                return ['POST'];
            }
            if (preg_match('~^/\.admin/credentials/[^/]+$~D', $path) === 1) {
                return ['DELETE'];
            }
        }
        if ($this->quotasEnabled) {
            if ($path === '/.admin/quotas') {
                return ['GET'];
            }
            if (preg_match('~^/\.admin/quotas/.+$~D', $path) === 1) {
                return ['GET', 'PUT', 'DELETE'];
            }
        }

        return null;
    }

    private function error(string $kind, int $status): Response
    {
        if ($kind === 'metrics') {
            $message = match ($status) {
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                409 => 'Conflict',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                503 => 'Service Unavailable',
                default => 'HTTP Error',
            };

            return new Response(
                status: $status,
                headers: ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store'],
                body: $message,
            );
        }

        $error = match ($status) {
            404 => 'not_found',
            405 => 'method_not_allowed',
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            409 => 'conflict',
            429 => 'too_many_requests',
            503 => 'service_unavailable',
            default => 'internal_error',
        };

        return new Response(
            status: $status,
            headers: ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            body: json_encode($kind === 'probe' && $status >= 500 ? ['status' => 'degraded'] : ['error' => $error], JSON_THROW_ON_ERROR),
        );
    }
}
