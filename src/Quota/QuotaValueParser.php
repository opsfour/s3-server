<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

final class QuotaValueParser
{
    public static function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException("{$label} must be an integer >= 0 within the supported range.");
        }

        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new \InvalidArgumentException("{$label} must be an integer >= 0 within the supported range.");
        }

        return (int) $normalized;
    }
}
