<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Runtime\NodeDrainController;
use OpsFour\S3Server\Xml\ErrorResponseBuilder;

/** Rejects work raced after drain without invoking auth, body, metadata, or storage code. */
final readonly class DrainAdmissionMiddleware implements Middleware
{
    public function __construct(
        private NodeDrainController $drain,
        private bool $managementRequest = false,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if ($this->drain->acceptsNewWork()) {
            return $requestHandler->handleRequest($request);
        }

        return $this->managementRequest
            ? $this->managementResponse()
            : $this->s3Response($request);
    }

    private function managementResponse(): Response
    {
        return new Response(
            status: 503,
            headers: [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Retry-After' => '1',
            ],
            body: '{"error":"service_unavailable","message":"The API node is draining."}',
        );
    }

    private function s3Response(Request $request): Response
    {
        $requestId = $request->hasAttribute('requestId') ? (string) $request->getAttribute('requestId') : '';
        $body = ErrorResponseBuilder::build(
            'SlowDown',
            'The API node is draining. Please retry on another ready node.',
            $request->getUri()->getPath(),
            $requestId,
            $request->hasAttribute('amzId2') ? (string) $request->getAttribute('amzId2') : '',
        );

        return new Response(
            status: 503,
            headers: [
                'Content-Type' => 'application/xml',
                'Cache-Control' => 'no-store',
                'Retry-After' => '1',
                'x-amz-request-id' => $requestId,
            ],
            body: $request->getMethod() === 'HEAD' ? '' : $body,
        );
    }
}
