<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Generates a unique request ID for each request.
 *
 * Sets the request ID as a request attribute and adds standard S3
 * response headers: x-amz-request-id and x-amz-id-2.
 */
final class RequestIdMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        // Single syscall for both UUID (16 bytes) and x-amz-id-2 (36 bytes).
        $randomBytes = random_bytes(52);

        $uuidBytes = substr($randomBytes, 0, 16);

        // Set version to 0100 (UUID v4)
        $uuidBytes[6] = chr(ord($uuidBytes[6]) & 0x0F | 0x40);

        // Set variant to 10xx (RFC 4122)
        $uuidBytes[8] = chr(ord($uuidBytes[8]) & 0x3F | 0x80);

        $requestId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuidBytes), 4));
        $amzId2 = base64_encode(substr($randomBytes, 16, 36));

        $request->setAttribute('requestId', $requestId);
        $request->setAttribute('amzId2', $amzId2);

        $response = $requestHandler->handleRequest($request);

        $response->setHeader('x-amz-request-id', $requestId);
        $response->setHeader('x-amz-id-2', $amzId2);
        $response->setHeader('Server', 'AmazonS3');

        // Amp's HTTP/1 writer does not special-case bodyless statuses. An
        // absent length on a persistent 204/304 response makes it append a
        // chunk terminator, which a client can parse as the next status line.
        // Content-Length is also forbidden on 204 by RFC 9110. Closing only
        // HTTP/1 connections gives these responses unambiguous framing;
        // HTTP/2 forbids the hop-by-hop Connection header.
        if (in_array($response->getStatus(), [204, 304], true)) {
            $response->setBody('');
            $response->removeHeader('Content-Length');
            $response->removeHeader('Transfer-Encoding');
        }

        // Amp suppresses HEAD bodies at the wire writer, but can still select
        // chunked framing when a response has no Content-Length. It then omits
        // the terminating chunk together with the body, so a persistent client
        // can parse the next response as chunk data. Close only those HEAD
        // responses; object HEAD responses already carry their stored length.
        if (str_starts_with($request->getProtocolVersion(), '1.')
            && (($request->getMethod() === 'HEAD' && $response->getHeader('Content-Length') === null)
                || in_array($response->getStatus(), [204, 304], true))) {
            $response->setHeader('Connection', 'close');
        }

        return $response;
    }
}
