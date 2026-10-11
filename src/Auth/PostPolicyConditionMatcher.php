<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/** Applies the field-specific matching rules from the S3 POST policy format. */
final class PostPolicyConditionMatcher
{
    public static function startsWith(string $field, string $actual, string $expected): bool
    {
        if (strcasecmp($field, 'Content-Type') !== 0) {
            return str_starts_with($actual, $expected);
        }

        foreach (explode(',', $actual) as $contentType) {
            if (! str_starts_with($contentType, $expected)) {
                return false;
            }
        }

        return true;
    }
}
