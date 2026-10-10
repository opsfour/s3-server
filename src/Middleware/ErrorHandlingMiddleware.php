<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\S3Exception;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Xml\ErrorResponseBuilder;
use Psr\Log\LoggerInterface;

/**
 * Catches exceptions and converts them to S3 XML error responses.
 *
 * - S3Exception instances are mapped to their specific error code and HTTP status.
 * - All other Throwables are logged and returned as 500 InternalError.
 */
final class ErrorHandlingMiddleware implements Middleware
{
    private readonly ?FailureIsolatingLogger $logger;

    public function __construct(
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger === null ? null : new FailureIsolatingLogger($logger);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        try {
            return $requestHandler->handleRequest($request);
        } catch (S3Exception $e) {
            $requestId = $request->getAttribute('requestId') ?? '';
            $resource = $request->getUri()->getPath();
            $status = $e->getHttpStatus();
            $internalFailure = $status === 500;

            if ($internalFailure) {
                $this->logger?->error('Internal S3 error', [
                    'exception' => $e::class,
                    'requestId' => $requestId,
                ]);
            }

            if ($e instanceof AccessDeniedException || $e instanceof SignatureDoesNotMatchException) {
                $this->logger?->warning('Auth failure: ' . $e->getErrorCode(), [
                    'requestId' => $requestId,
                    'path' => $resource,
                    'client' => $request->getClient()->getRemoteAddress()->toString(),
                ]);
            }

            // 304 Not Modified must have no body per HTTP spec.
            if ($status === 304) {
                /** @var array<non-empty-string, string> $headers304 */
                $headers304 = ['x-amz-request-id' => (string) $requestId];
                foreach ($e->getExtraHeaders() as $k => $v) {
                    if ($k === '') {
                        continue;
                    }
                    $headers304[$k] = $v;
                }
                return new Response(
                    status: 304,
                    headers: $headers304,
                );
            }

            $xml = ErrorResponseBuilder::build(
                $e->getErrorCode(),
                $internalFailure ? 'We encountered an internal error. Please try again.' : $e->getMessage(),
                $resource,
                $requestId,
            );

            /** @var array<non-empty-string, string> $headers */
            $headers = [
                'content-type' => 'application/xml',
                'x-amz-request-id' => (string) $requestId,
                ...($internalFailure ? [] : $e->getExtraHeaders()),
            ];

            return new Response(
                status: $status,
                headers: $headers,
                body: $xml,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Internal server error', [
                'exception' => $e::class,
            ]);

            $requestId = $request->getAttribute('requestId') ?? '';
            $resource = $request->getUri()->getPath();

            $xml = ErrorResponseBuilder::build(
                'InternalError',
                'We encountered an internal error. Please try again.',
                $resource,
                $requestId,
            );

            return new Response(
                status: 500,
                headers: [
                    'content-type' => 'application/xml',
                    'x-amz-request-id' => (string) $requestId,
                ],
                body: $xml,
            );
        }
    }
}
