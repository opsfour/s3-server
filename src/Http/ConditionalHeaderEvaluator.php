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
 * Evaluation order per RFC 9110 / S3 spec:
 * 1. If-Match (412 if ETag doesn't match)
 * 2. If-Unmodified-Since, only without If-Match (412 if modified since)
 * 3. If-None-Match (304 if ETag matches)
 * 4. If-Modified-Since, only without If-None-Match (304 if unchanged)
 */
final class ConditionalHeaderEvaluator
{
    /**
     * @throws PreconditionFailedException
     * @throws NotModifiedException
     */
    public static function evaluate(Request $request, ObjectInfo $objectInfo, bool $emitNullVersion = false): void
    {
        $ifMatch = $request->getHeader('if-match');
        $ifNoneMatch = $request->getHeader('if-none-match');
        $ifModifiedSince = $request->getHeader('if-modified-since');
        $ifUnmodifiedSince = $request->getHeader('if-unmodified-since');

        // If-Match: request ETag must match object ETag.
        if ($ifMatch !== null) {
            if (! self::etagMatches($ifMatch, $objectInfo->etag)) {
                throw new PreconditionFailedException();
            }
        }

        // If-Unmodified-Since: 412 if modified after the given date.
        if ($ifUnmodifiedSince !== null && $ifMatch === null) {
            $sinceTime = self::parseHttpDate($ifUnmodifiedSince);
            if ($sinceTime !== null && $objectInfo->lastModified->getTimestamp() > $sinceTime) {
                throw new PreconditionFailedException();
            }
        }

        // If-None-Match: return 304 if ETag matches.
        if ($ifNoneMatch !== null) {
            if (self::etagMatches($ifNoneMatch, $objectInfo->etag, allowWeak: true)) {
                $e = new NotModifiedException();
                $e->setExtraHeaders(self::notModifiedHeaders($objectInfo, $emitNullVersion));
                throw $e;
            }
        }

        // If-Modified-Since: 304 if not modified since the given date.
        if ($ifModifiedSince !== null && $ifNoneMatch === null) {
            $sinceTime = self::parseHttpDate($ifModifiedSince);
            if ($sinceTime !== null && $objectInfo->lastModified->getTimestamp() <= $sinceTime) {
                $e = new NotModifiedException();
                $e->setExtraHeaders(self::notModifiedHeaders($objectInfo, $emitNullVersion));
                throw $e;
            }
        }
    }

    /** @return array<string, string> */
    private static function notModifiedHeaders(ObjectInfo $objectInfo, bool $emitNullVersion): array
    {
        $headers = [
            'ETag' => $objectInfo->etag,
            'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \\G\\M\\T'),
        ];
        if ($objectInfo->versionId !== null) {
            $headers['x-amz-version-id'] = $objectInfo->versionId;
        } elseif ($emitNullVersion) {
            $headers['x-amz-version-id'] = 'null';
        }

        return $headers;
    }

    /**
     * Check whether an ETag value from a conditional header matches the object ETag.
     */
    public static function etagMatches(string $headerValue, string $objectEtag, bool $allowWeak = false): bool
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
                if (! $allowWeak) {
                    continue;
                }
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
        // RFC 9110 permits IMF-fixdate and the two obsolete HTTP-date forms.
        // DateTime accepts invalid calendar dates and mismatched weekdays by
        // normalizing them, so require an exact round trip as well.
        foreach ([
            'D, d M Y H:i:s \\G\\M\\T',
            'l, d-M-y H:i:s \\G\\M\\T',
            'D M j H:i:s Y',
        ] as $format) {
            $timestamp = \DateTimeImmutable::createFromFormat('!' . $format, $date, new \DateTimeZone('UTC'));
            if ($timestamp === false) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            $formatted = $format === 'D M j H:i:s Y'
                ? $timestamp->format('D M ') . ($timestamp->format('j') < 10 ? ' ' : '') . $timestamp->format('j H:i:s Y')
                : $timestamp->format($format);
            if ($formatted === $date) {
                return $timestamp->getTimestamp();
            }
        }

        return null;
    }
}
