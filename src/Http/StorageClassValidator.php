<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;

final class StorageClassValidator
{
    private const array SUPPORTED_CLASSES = [
        'STANDARD',
        'REDUCED_REDUNDANCY',
        'STANDARD_IA',
        'ONEZONE_IA',
        'INTELLIGENT_TIERING',
        'GLACIER',
        'DEEP_ARCHIVE',
        'GLACIER_IR',
    ];

    public static function requested(?string $value, string $default = 'STANDARD'): string
    {
        if ($value === null) {
            return $default;
        }
        if (!in_array($value, self::SUPPORTED_CLASSES, true)) {
            throw new InvalidArgumentException('Unsupported storage class.');
        }

        return $value;
    }
}
