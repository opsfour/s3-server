<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

/** Validates text that will be serialized into an XML 1.0 response. */
final class XmlText
{
    public static function isValid(string $value): bool
    {
        if (preg_match('//u', $value) !== 1) {
            return false;
        }

        return preg_match(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]/u',
            $value,
        ) === 0;
    }

    private function __construct() {}
}
