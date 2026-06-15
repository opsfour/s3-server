<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NotModifiedException;
use OpsFour\S3Server\Exception\PreconditionFailedException;

/**
 * Evaluates conditional request headers (If-Match, If-None-Match,
 * If-Modified-Since, If-Unmodified-Since) against object metadata.
 *
 * Evaluation order per RFC 7232 / S3 spec:
 * 1. If-Match (412 if ETag doesn't match)
 * 2. If-Unmodified-Since (412 if modified since the given date)
 * 3. If-None-Match (304 if ETag matches)
 * 4. If-Modified-Since (304 if not modified since the given date)
 */
final class ConditionalHeaderEvaluator
{
    /**
     * @throws PreconditionFailedException
     * @throws NotModifiedException
     */
    public static function evaluate(Request $request, ObjectInfo $objectInfo): void
    {
        $ifMatch = $request->getHeader('if-match');
        $ifNoneMatch = $request->getHeader('if-none-match');
        $ifModifiedSince = $request->getHeader('if-modified-since');
        $ifUnmodifiedSince = $request->getHeader('if-unmodified-since');

        // If-Match: request ETag must match object ETag.
        if ($ifMatch !== null) {
            if (! self::etagMatches($ifMatch, $objectInfo->etag)) {
                throw new PreconditionFailedException;
            }
        }

        // If-Unmodified-Since: 412 if modified after the given date.
        if ($ifUnmodifiedSince !== null) {
            $sinceTime = self::parseHttpDate($ifUnmodifiedSince);
            if ($sinceTime !== null && $objectInfo->lastModified->getTimestamp() > $sinceTime) {
                throw new PreconditionFailedException;
            }
        }

        // If-None-Match: return 304 if ETag matches.
        if ($ifNoneMatch !== null) {
            if (self::etagMatches($ifNoneMatch, $objectInfo->etag)) {
                $e = new NotModifiedException;
                $e->setExtraHeaders([
                    'ETag' => $objectInfo->etag,
                    'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \\G\\M\\T'),
                ]);
                throw $e;
            }
        }

        // If-Modified-Since: 304 if not modified since the given date.
        if ($ifModifiedSince !== null) {
            $sinceTime = self::parseHttpDate($ifModifiedSince);
            if ($sinceTime !== null && $objectInfo->lastModified->getTimestamp() <= $sinceTime) {
                $e = new NotModifiedException;
                $e->setExtraHeaders([
                    'ETag' => $objectInfo->etag,
                    'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \\G\\M\\T'),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Check whether an ETag value from a conditional header matches the object ETag.
     */
    public static function etagMatches(string $headerValue, string $objectEtag): bool
    {
        $headerValue = trim($headerValue);

        if ($headerValue === '*') {
            return true;
        }

        $normalizedObjectEtag = trim($objectEtag, '"');
        $candidates = explode(',', $headerValue);

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            $candidate = trim($candidate, '"');

            if ($candidate === $normalizedObjectEtag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse an HTTP-date string to a Unix timestamp.
     *
     * @return int|null Unix timestamp, or null if parsing fails.
     */
    public static function parseHttpDate(string $date): ?int
    {
        // RFC 7231 preferred format.
        $timestamp = \DateTimeImmutable::createFromFormat(
            'D, d M Y H:i:s \\G\\M\\T',
            $date,
            new \DateTimeZone('UTC'),
        );

        if ($timestamp !== false) {
            return $timestamp->getTimestamp();
        }

        // RFC 850 format.
        $timestamp = \DateTimeImmutable::createFromFormat(
            'l, d-M-y H:i:s \\G\\M\\T',
            $date,
            new \DateTimeZone('UTC'),
        );

        if ($timestamp !== false) {
            return $timestamp->getTimestamp();
        }

        // asctime format.
        $timestamp = \DateTimeImmutable::createFromFormat(
            'D M j H:i:s Y',
            $date,
            new \DateTimeZone('UTC'),
        );

        if ($timestamp !== false) {
            return $timestamp->getTimestamp();
        }

        $result = strtotime($date);

        return $result !== false ? $result : null;
    }
}
