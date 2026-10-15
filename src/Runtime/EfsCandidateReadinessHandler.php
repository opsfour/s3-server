<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Handler\HealthCheckHandler;

/**
 * Keeps candidate readiness closed before activation without poisoning the
 * inner health handler's terminal evidence-loss latch.
 *
 * @internal
 */
final readonly class EfsCandidateReadinessHandler implements RequestHandler
{
    public function __construct(
        private EfsCandidateServingAdmission $admission,
        private HealthCheckHandler $health,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($this->admission->state() !== EfsCandidateServingAdmissionState::ACTIVE) {
            return $this->degradedResponse();
        }

        return $this->health->handleRequest($request);
    }

    public function assertBoundTo(EfsCandidateServingAdmission $admission): void
    {
        if ($this->admission !== $admission) {
            throw new \RuntimeException('EFS readiness belongs to another serving admission.');
        }
    }

    private function degradedResponse(): Response
    {
        return new Response(
            status: 503,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: '{"status":"degraded"}',
        );
    }
}
