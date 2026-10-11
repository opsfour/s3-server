<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\PreconditionFailedException;

final class CopySourceConditionEvaluator
{
    public static function assertSatisfied(Request $request, ObjectInfo $source): void
    {
        $ifMatch = RequestHeaderValues::combined($request, 'x-amz-copy-source-if-match');
        $ifNoneMatch = RequestHeaderValues::combined($request, 'x-amz-copy-source-if-none-match');
        $ifModifiedSince = RequestHeaderValues::combined($request, 'x-amz-copy-source-if-modified-since');
        $ifUnmodifiedSince = RequestHeaderValues::combined($request, 'x-amz-copy-source-if-unmodified-since');

        if ($ifMatch !== null) {
            if (! ConditionalHeaderEvaluator::etagMatches($ifMatch, $source->etag)) {
                throw new PreconditionFailedException();
            }
        }

        if ($ifUnmodifiedSince !== null && $ifMatch === null) {
            $sinceTime = ConditionalHeaderEvaluator::parseHttpDate($ifUnmodifiedSince);
            if ($sinceTime !== null && $source->lastModified->getTimestamp() > $sinceTime) {
                throw new PreconditionFailedException();
            }
        }

        if ($ifNoneMatch !== null) {
            if (ConditionalHeaderEvaluator::etagMatches($ifNoneMatch, $source->etag, allowWeak: true)) {
                throw new PreconditionFailedException();
            }
        }

        if ($ifModifiedSince !== null && $ifNoneMatch === null) {
            $sinceTime = ConditionalHeaderEvaluator::parseHttpDate($ifModifiedSince);
            if ($sinceTime !== null && $source->lastModified->getTimestamp() <= $sinceTime) {
                throw new PreconditionFailedException();
            }
        }
    }
}
