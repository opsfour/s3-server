<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\CancelledException;
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
        $this->assertRequestFresh();

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($this->requiresCommitCheck($request)) {
                $this->assertRequestFresh();
            }

            throw $error;
        }

        if (! $this->requiresCommitCheck($request)) {
            return $response;
        }

        try {
            $this->assertRequestFresh();
        } catch (\Throwable $error) {
            try {
                $response->getBody()->close();
            } catch (\Throwable) {
                // Preserve the admission failure or request cancellation.
            }
            throw $error;
        }

        return $response;
    }

    /** Share the same latched admission state with readiness checks. */
    public function assertFresh(): void
    {
        if ($this->revoked) {
            throw new \RuntimeException('Storage host evidence is unavailable.');
        }

        try {
            $this->evidence->assertFresh();
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('Storage host evidence is unavailable.');
        }

        // Another request can revoke the shared latch while the evidence
        // check above is suspended. Its terminal decision must win.
        $this->assertNotRevokedAfterYield();
    }

    private function assertRequestFresh(): void
    {
        try {
            $this->assertFresh();
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new SlowDownException('Storage host evidence is unavailable.');
        }
    }

    private function requiresCommitCheck(Request $request): bool
    {
        return in_array($request->getMethod(), ['PUT', 'POST', 'DELETE'], true);
    }

    private function assertNotRevokedAfterYield(): void
    {
        if ($this->revoked) {
            throw new \RuntimeException('Storage host evidence is unavailable.');
        }
    }
}
