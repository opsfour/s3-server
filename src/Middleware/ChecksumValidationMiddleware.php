<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BadDigestException;

use function Amp\ByteStream\buffer;

/**
 * Validates the x-amz-content-sha256 header when present.
 *
 * Special values that bypass validation:
 * - UNSIGNED-PAYLOAD
 * - STREAMING-AWS4-HMAC-SHA256-PAYLOAD
 * - STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER
 *
 * For all other values, the header is treated as the expected hex-encoded
 * SHA-256 of the request body. The body is buffered, the hash computed,
 * and compared. On mismatch, a BadDigestException is thrown.
 */
final class ChecksumValidationMiddleware implements Middleware
{
    /** @var list<string> Special header values that skip SHA-256 body validation. */
    private const array SKIP_VALUES = [
        'UNSIGNED-PAYLOAD',
        'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
    ];

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $contentSha256 = $request->getHeader('x-amz-content-sha256');

        // No header or special value — pass through
        if ($contentSha256 === null || in_array($contentSha256, self::SKIP_VALUES, true)) {
            return $requestHandler->handleRequest($request);
        }

        // Reject literal SHA-256 validation for large bodies to prevent OOM.
        // AWS SDKs always send UNSIGNED-PAYLOAD or STREAMING-* for large uploads.
        $maxBufferSize = 16 * 1024 * 1024; // 16 MiB

        $contentLength = $request->getHeader('content-length');
        if ($contentLength !== null && (int) $contentLength > $maxBufferSize) {
            throw new BadDigestException(
                'Literal x-amz-content-sha256 not supported for bodies >16 MiB. Use UNSIGNED-PAYLOAD.',
            );
        }

        // Buffer the full body with a hard limit to prevent OOM even without Content-Length.
        $body = buffer($request->getBody(), limit: $maxBufferSize + 1);

        if (strlen($body) > $maxBufferSize) {
            throw new BadDigestException(
                'Literal x-amz-content-sha256 not supported for bodies >16 MiB. Use UNSIGNED-PAYLOAD.',
            );
        }

        // Compute SHA-256 hex digest
        $computedSha256 = hash('sha256', $body);

        if (!hash_equals($contentSha256, $computedSha256)) {
            throw new BadDigestException(
                "The SHA-256 checksum you specified did not match what we received. Expected: {$contentSha256}, Computed: {$computedSha256}",
            );
        }

        // Replace the request body with a new readable buffer so downstream can read it
        $request->setBody(new ReadableBuffer($body));

        return $requestHandler->handleRequest($request);
    }
}
