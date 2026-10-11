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
        $ifMatch = RequestHeaderValues::combined($request, 'if-match');
        $ifNoneMatch = RequestHeaderValues::combined($request, 'if-none-match');
        $ifModifiedSince = RequestHeaderValues::combined($request, 'if-modified-since');
        $ifUnmodifiedSince = RequestHeaderValues::combined($request, 'if-unmodified-since');

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
        $offset = 0;
        self::skipOptionalWhitespace($headerValue, $offset);
        if (($headerValue[$offset] ?? null) === '*') {
            $offset++;
            self::skipOptionalWhitespace($headerValue, $offset);

            return $offset === strlen($headerValue);
        }

        if (! self::validOpaqueTag($objectEtag)) {
            return false;
        }

        $candidates = self::parseEntityTagList($headerValue);
        if ($candidates === null) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if ((! $candidate['weak'] || $allowWeak) && $candidate['tag'] === $objectEtag) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{weak: bool, tag: string}>|null */
    private static function parseEntityTagList(string $value): ?array
    {
        $length = strlen($value);
        $offset = 0;
        $tags = [];

        while (true) {
            self::skipOptionalWhitespace($value, $offset);
            if ($offset >= $length) {
                return null;
            }

            $weak = substr($value, $offset, 2) === 'W/';
            if ($weak) {
                $offset += 2;
            }
            if (($value[$offset] ?? null) !== '"') {
                return null;
            }

            $start = $offset++;
            while ($offset < $length && $value[$offset] !== '"') {
                $byte = ord($value[$offset]);
                if ($byte !== 0x21 && ($byte < 0x23 || ($byte > 0x7e && $byte < 0x80))) {
                    return null;
                }
                $offset++;
            }
            if ($offset >= $length) {
                return null;
            }
            $offset++;
            $tags[] = ['weak' => $weak, 'tag' => substr($value, $start, $offset - $start)];

            self::skipOptionalWhitespace($value, $offset);
            if ($offset === $length) {
                return $tags;
            }
            if ($value[$offset] !== ',') {
                return null;
            }
            $offset++;
        }
    }

    private static function validOpaqueTag(string $value): bool
    {
        $tags = self::parseEntityTagList($value);

        return $tags !== null && count($tags) === 1 && ! $tags[0]['weak'];
    }

    private static function skipOptionalWhitespace(string $value, int &$offset): void
    {
        $length = strlen($value);
        while ($offset < $length && ($value[$offset] === ' ' || $value[$offset] === "\t")) {
            $offset++;
        }
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
