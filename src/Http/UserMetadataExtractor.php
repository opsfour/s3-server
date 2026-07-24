<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidArgumentException;

/**
 * Extracts user-defined metadata from x-amz-meta-* request headers.
 *
 * Keys are lowercased per HTTP header normalization, with the "x-amz-meta-"
 * prefix stripped. Values use the first header value when multiple values
 * exist for the same header name. Keys starting with "__" are reserved
 * for internal use and will be rejected.
 */
final class UserMetadataExtractor
{
    private const int MAX_USER_METADATA_BYTES = 2048;

    /** @return array<string, string> */
    public static function extract(Request $request): array
    {
        $metadata = [];
        $bytes = 0;

        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'x-amz-meta-')) {
                $metaKey = substr($lower, 11);
                if (str_starts_with($metaKey, '__')) {
                    throw new InvalidArgumentException("Metadata key '{$metaKey}' uses reserved prefix '__'.");
                }
                if ($metaKey === '') {
                    throw new InvalidArgumentException('Metadata keys must not be empty.');
                }
                $metadata[$metaKey] = $values[0];
                $bytes += strlen($metaKey) + strlen($values[0]);
            }
        }
        if ($bytes > self::MAX_USER_METADATA_BYTES) {
            throw new InvalidArgumentException(
                'User-defined metadata exceeds the 2 KiB S3 limit.',
            );
        }

        return $metadata;
    }
}
