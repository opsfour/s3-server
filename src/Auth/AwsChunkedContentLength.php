<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidArgumentException;

/** Parses the mandatory logical length carried by AWS chunked requests. */
final class AwsChunkedContentLength
{
    /** @var list<string> */
    private const array PAYLOAD_MARKERS = [
        'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
        'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
    ];

    public static function fromRequest(Request $request): ?int
    {
        if (! in_array($request->getHeader('x-amz-content-sha256'), self::PAYLOAD_MARKERS, true)) {
            return null;
        }

        $value = $request->getHeader('x-amz-decoded-content-length');
        if ($value === null || $value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException(
                'x-amz-decoded-content-length must be a non-negative decimal integer for aws-chunked requests.',
            );
        }

        $normalized = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new InvalidArgumentException('x-amz-decoded-content-length is too large.');
        }

        return (int) $value;
    }
}
