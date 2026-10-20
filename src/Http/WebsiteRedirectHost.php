<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

/** Shared validation for persisted website redirect hosts and response use. */
final class WebsiteRedirectHost
{
    public static function isValid(string $host): bool
    {
        if (preg_match('/\A[a-zA-Z0-9.-]+(?::([0-9]{1,5}))?\z/D', $host, $matches) !== 1) {
            return false;
        }

        return ! isset($matches[1]) || ((int) $matches[1] >= 1 && (int) $matches[1] <= 65_535);
    }
}
