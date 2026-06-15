<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles ListBuckets (GET /).
 *
 * Returns an XML listing of all buckets owned by the authenticated user.
 * Uses the ownerId request attribute set by AuthMiddleware to scope
 * the query to the calling user's buckets.
 */
final class ListBucketsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $ownerId = $request->getAttribute('ownerId');

        // Retrieve all buckets for this owner.
        $buckets = $this->metadata->listBuckets($ownerId);

        // Resolve display name from credential if available.
        $credential = $request->getAttribute('credential');
        $displayName = ($credential !== null && $credential->ownerId === $ownerId && $credential->displayName !== '')
            ? $credential->displayName
            : $ownerId;

        $xml = XmlResponseBuilder::listBucketsResult(
            buckets: $buckets,
            ownerId: $ownerId,
            displayName: $displayName,
        );

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
