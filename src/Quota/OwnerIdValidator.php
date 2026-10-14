<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

use OpsFour\S3Server\Xml\XmlText;

final class OwnerIdValidator
{
    public const string ERROR_MESSAGE = 'Owner ID must contain 1 to 255 bytes of valid UTF-8, use only XML 1.0 characters, and contain no control characters.';

    public static function assertValid(string $ownerId): void
    {
        if (!self::isValid($ownerId)) {
            throw new \InvalidArgumentException(self::ERROR_MESSAGE);
        }
    }

    public static function isValid(string $ownerId): bool
    {
        return $ownerId !== ''
            && strlen($ownerId) <= 255
            && XmlText::isValid($ownerId)
            && preg_match('/\p{Cc}/u', $ownerId) !== 1;
    }
}
