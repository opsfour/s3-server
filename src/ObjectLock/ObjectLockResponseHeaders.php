<?php

declare(strict_types=1);

namespace OpsFour\S3Server\ObjectLock;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;

/** Builds the permission-filtered Object Lock headers returned by GET and HEAD. */
final readonly class ObjectLockResponseHeaders
{
    public function __construct(private MetadataStore $metadata) {}

    /** @return array<non-empty-string, string> */
    public function forObject(Request $request, ObjectInfo $object): array
    {
        // Direct handler callers have no authorization decision to extend.
        if (! $request->hasAttribute('s3.policyResult')) {
            return [];
        }

        $headers = [];
        $retention = $this->metadata->getObjectRetention(
            $object->bucket,
            $object->key,
            $object->versionId,
        );
        if ($retention !== null && $this->isAllowed($request, S3Operation::GetObjectRetention)) {
            $headers['x-amz-object-lock-mode'] = $retention['mode'];
            $headers['x-amz-object-lock-retain-until-date'] = $retention['retainUntilDate'];
        }

        $status = $this->metadata->getObjectLegalHold(
            $object->bucket,
            $object->key,
            $object->versionId,
        );
        if ($status !== null && $this->isAllowed($request, S3Operation::GetObjectLegalHold)) {
            $headers['x-amz-object-lock-legal-hold'] = $status;
        }

        return $headers;
    }

    private function isAllowed(Request $request, S3Operation $operation): bool
    {
        $probe = clone $request;
        $probe->setAttribute('s3.operation', $operation);

        try {
            (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
                $probe,
                new class ($this->metadata) implements RequestHandler {
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

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
