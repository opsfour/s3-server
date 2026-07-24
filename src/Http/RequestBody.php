<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\EntityTooLargeException;

final class RequestBody
{
    public const int XML_LIMIT = 1_048_576;

    public static function buffer(Request $request, int $limit = self::XML_LIMIT): string
    {
        try {
            $body = $request->getBody()->buffer(limit: $limit);
        } catch (BufferException) {
            throw new EntityTooLargeException("Control-plane request body exceeds {$limit} bytes.");
        }

        if (strlen($body) > $limit) {
            throw new EntityTooLargeException("Control-plane request body exceeds {$limit} bytes.");
        }

        return $body;
    }
}
