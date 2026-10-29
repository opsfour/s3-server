<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\ClusterRoleSet;

/** Rejects reconciliation-dependent mutations before Expect/body processing. */
final readonly class ClusterRoleAdmissionMiddleware implements Middleware
{
    public function __construct(private ClusterRoleController $roles) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        if ($operation instanceof S3Operation
            && $operation->requiresReconciliationCoverage()
            && ! $this->roles->hasLiveRole(ClusterRoleSet::RECONCILE)) {
            throw new SlowDownException('Cluster reconciliation coverage is unavailable.');
        }

        return $requestHandler->handleRequest($request);
    }
}
