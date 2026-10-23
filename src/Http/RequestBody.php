<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Routing\S3Operation;

final class RequestBody
{
    /** @var list<S3Operation> Supported PUT/POST operations whose request body is ignored. */
    private const array BODYLESS_WRITE_OPERATIONS = [
        S3Operation::CopyObject,
        S3Operation::CreateMultipartUpload,
        S3Operation::UploadPartCopy,
    ];

    public const int XML_LIMIT = 1_048_576;

    public static function isBodylessOperation(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['PUT', 'POST'], true)) {
            return true;
        }

        $operation = $request->hasAttribute('s3.operation')
            ? $request->getAttribute('s3.operation')
            : null;

        return $operation instanceof S3Operation
            && in_array($operation, self::BODYLESS_WRITE_OPERATIONS, true);
    }

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
