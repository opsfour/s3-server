<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Auth\AuthType;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Security\RequestNetworkContext;

/** Resolve the 403/404 missing-key distinction with the normal bucket authorization rules. */
final class MissingObjectListVisibility
{
    public static function canList(MetadataStore $metadata, Request $request, string $bucket, bool $copySource = false): bool
    {
        // Build a separate authorization context. Reusing the object request's
        // policy result or cached object owner would grant the wrong resource.
        $listRequest = clone $request;
        $listRequest->removeAttributes();
        foreach ([
            'credential',
            'ownerId',
            RequestNetworkContext::CLIENT_IP_ATTRIBUTE,
            RequestNetworkContext::SECURE_TRANSPORT_ATTRIBUTE,
        ] as $name) {
            if ($request->hasAttribute($name)) {
                $listRequest->setAttribute($name, $request->getAttribute($name));
            }
        }
        AuthType::copy($request, $listRequest);
        $listRequest->setAttribute('s3.bucket', $bucket);
        $listRequest->setAttribute('s3.key', '');
        $listRequest->setAttribute('s3.operation', S3Operation::ListObjectsV2);
        $listRequest->setMethod('GET');
        $listRequest->setUri($request->getUri()->withPath('/' . $bucket)->withQuery(''));
        if ($copySource) {
            // The destination's expected-owner header does not apply to the
            // source bucket. Keep the source-specific assertion instead.
            $listRequest->removeHeader('x-amz-expected-bucket-owner');
            $sourceExpectedOwner = $request->getHeader('x-amz-source-expected-bucket-owner');
            if ($sourceExpectedOwner !== null) {
                $listRequest->setHeader('x-amz-expected-bucket-owner', $sourceExpectedOwner);
            }
        }

        try {
            (new PolicyEnforcementMiddleware($metadata))->handleRequest(
                $listRequest,
                new class ($metadata) implements RequestHandler {
                    public function __construct(private readonly MetadataStore $metadata) {}

                    public function handleRequest(Request $request): Response
                    {
                        return (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                            $request,
                            new class implements RequestHandler {
                                public function handleRequest(Request $request): Response
                                {
                                    return new Response(204);
                                }
                            },
                        );
                    }
                },
            );
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }
}
