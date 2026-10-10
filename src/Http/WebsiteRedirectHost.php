<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

/** Shared validation for persisted website redirect hosts and response use. */
final class WebsiteRedirectHost
{
    public static function isValid(string $host): bool
    {
        return preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z/D', $host) === 1;
    }
}
