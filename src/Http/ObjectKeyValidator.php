<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\KeyTooLongException;
use OpsFour\S3Server\Xml\XmlText;

final class ObjectKeyValidator
{
    public static function assertValid(string $key): void
    {
        if (strlen($key) > 1024) {
            throw new KeyTooLongException();
        }

        if (! XmlText::isValid($key)) {
            throw new InvalidArgumentException('Object key must be valid UTF-8 XML 1.0 text.');
        }
    }

    private function __construct() {}
}
