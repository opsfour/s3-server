<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Runtime\ClusterAdmissionController;
use OpsFour\S3Server\Runtime\ClusterRoleController;

/** Rejects protected work before authorization, body-spool, or mutation work begins. */
final readonly class ClusterAdmissionMiddleware implements Middleware
{
    public function __construct(
        private ClusterAdmissionController $admission,
        private ?ClusterRoleController $roles = null,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if (! $this->admission->admitNewClaim()) {
            $this->roles?->withdraw();

            throw new SlowDownException('Cluster admission is closed.');
        }

        return $requestHandler->handleRequest($request);
    }
}
