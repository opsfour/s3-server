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
 * Validates the Content-MD5 header on PUT/POST requests.
 *
 * When Content-MD5 is present, the full request body is buffered,
 * the MD5 is computed, and compared against the header value.
 * On mismatch, a BadDigestException is thrown.
 *
 * The body is reconstructed as a ReadableBuffer so downstream
 * handlers can read it normally.
 *
 * IMPORTANT: This only buffers the body when Content-MD5 is present.
 */
final class ContentMd5Middleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $method = $request->getMethod();
        $contentMd5 = $request->getHeader('Content-MD5');

        // Only validate on PUT/POST when Content-MD5 header is present
        if ($contentMd5 === null || ! in_array($method, ['PUT', 'POST'], true)) {
            return $requestHandler->handleRequest($request);
        }

        // Skip buffering for large bodies to prevent OOM.
        // Content-MD5 is only sent for small XML payloads in practice.
        $maxBufferSize = 16 * 1024 * 1024; // 16 MiB

        $contentLength = $request->getHeader('content-length');
        if ($contentLength !== null && (int) $contentLength > $maxBufferSize) {
            return $requestHandler->handleRequest($request);
        }

        // Buffer the full body with a hard limit to prevent OOM even without Content-Length.
        $body = buffer($request->getBody(), limit: $maxBufferSize + 1);

        if (strlen($body) > $maxBufferSize) {
            // Body exceeds buffer limit and Content-Length was absent (otherwise
            // the pre-check above would have skipped validation entirely).
            // The stream has been partially consumed — we cannot forward
            // a truncated body without causing silent data corruption.
            // Reject the request rather than risk storing a truncated object.
            throw new BadDigestException(
                'Content-MD5 validation failed: body exceeds 16 MiB and no Content-Length header was provided.',
            );
        }

        // Compute base64-encoded MD5
        $computedMd5 = base64_encode(md5($body, binary: true));

        if (!hash_equals($contentMd5, $computedMd5)) {
            throw new BadDigestException(
                "The Content-MD5 you specified did not match what we received. Expected: {$contentMd5}, Computed: {$computedMd5}",
            );
        }

        // Replace the request body with a new readable buffer so downstream can read it
        $request->setBody(new ReadableBuffer($body));

        return $requestHandler->handleRequest($request);
    }
}
