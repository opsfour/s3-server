<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\BucketContinuationToken;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;
use OpsFour\S3Server\Xml\XmlText;

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
        $query = QueryStringParser::parse($request->getUri()->getQuery());
        $ownerId = $request->getAttribute('ownerId');
        $paginated = array_key_exists('max-buckets', $query)
            || array_key_exists('continuation-token', $query)
            || array_key_exists('prefix', $query)
            || array_key_exists('bucket-region', $query);
        $prefix = $query['prefix'] ?? null;
        $region = $query['bucket-region'] ?? null;
        $nextToken = null;

        if ($prefix !== null && ! XmlText::isValid($prefix)) {
            throw new InvalidArgumentException('prefix must contain only characters representable in XML 1.0.');
        }

        if ($paginated) {
            $maxBuckets = self::maxBuckets($query['max-buckets'] ?? null);
            $after = isset($query['continuation-token'])
                ? BucketContinuationToken::decode($query['continuation-token'], $ownerId, $prefix, $region)
                : null;
            $page = $this->metadata->listBucketsPage($ownerId, $after, $prefix, $region, $maxBuckets);
            $buckets = $page['buckets'];
            if ($page['nextMarker'] !== null) {
                $nextToken = BucketContinuationToken::encode($ownerId, $prefix, $region, $page['nextMarker']);
            }
        } else {
            $page = $this->metadata->listBucketsPage($ownerId, null, null, null, 10_000);
            if ($page['nextMarker'] !== null) {
                throw new InvalidArgumentException('Unpaginated ListBuckets is limited to 10000 buckets.');
            }
            $buckets = $page['buckets'];
        }

        // Resolve display name from credential if available.
        $credential = $request->getAttribute('credential');
        $displayName = ($credential !== null && $credential->ownerId === $ownerId && $credential->displayName !== '')
            ? $credential->displayName
            : $ownerId;

        $xml = XmlResponseBuilder::listBucketsResult(
            buckets: $buckets,
            ownerId: $ownerId,
            displayName: $displayName,
            includeRegion: $paginated,
            prefix: $prefix,
            continuationToken: $nextToken,
        );

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }

    private static function maxBuckets(?string $value): int
    {
        if ($value === null) {
            return 10_000;
        }
        if ($value === '' || ! ctype_digit($value) || strlen(ltrim($value, '0')) > 5) {
            throw new InvalidArgumentException('max-buckets must be between 1 and 10000.');
        }
        $number = (int) $value;
        if ($number < 1 || $number > 10_000) {
            throw new InvalidArgumentException('max-buckets must be between 1 and 10000.');
        }

        return $number;
    }
}
