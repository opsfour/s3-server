<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Runtime\EfsHostEvidence;

/** Latching API/readiness gate for the test profile or an inert EFS candidate; physical I/O needs its own evidence checks. */
final class EfsApiAdmissionMiddleware implements Middleware, EfsHostEvidence
{
    private bool $revoked = false;

    public function __construct(private readonly EfsHostEvidence $evidence) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        try {
            $this->assertFresh();
        } catch (\Throwable) {
            throw new SlowDownException('Storage host evidence is unavailable.');
        }

        return $requestHandler->handleRequest($request);
    }

    /** Share the same latched admission state with readiness checks. */
    public function assertFresh(): void
    {
        if ($this->revoked) {
            throw new \RuntimeException('Storage host evidence is unavailable.');
        }

        try {
            $this->evidence->assertFresh();
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('Storage host evidence is unavailable.');
        }
    }
}
