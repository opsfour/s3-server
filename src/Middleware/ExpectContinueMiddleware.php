<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Handles the Expect: 100-continue header.
 *
 * Amp's HTTP server handles the 100-continue response at the socket level.
 * This middleware strips the Expect header so downstream handlers do not
 * need to deal with it. Additional pre-validation can be added here in
 * future phases (e.g., checking authorization before accepting the body).
 */
final class ExpectContinueMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $expect = $request->getHeader('Expect');

        if ($expect !== null && strcasecmp($expect, '100-continue') === 0) {
            $request->removeHeader('Expect');
        }

        return $requestHandler->handleRequest($request);
    }
}
