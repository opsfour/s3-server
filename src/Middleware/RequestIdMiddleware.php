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

        $response = $requestHandler->handleRequest($request);

        $response->setHeader('x-amz-request-id', $requestId);
        $response->setHeader('x-amz-id-2', $amzId2);
        $response->setHeader('Server', 'AmazonS3');

        return $response;
    }
}
