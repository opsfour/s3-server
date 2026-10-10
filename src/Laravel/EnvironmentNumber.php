<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

/** Parse package environment numbers before casts can silently disable limits. */
final class EnvironmentNumber
{
    public static function integer(string $name, int $default): int
    {
        $raw = env($name, $default);
        if (! is_int($raw) && ! is_string($raw)) {
            throw new \InvalidArgumentException("{$name} must be a decimal integer.");
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException("{$name} must be a decimal integer within the supported range.");
        }

        return $value;
    }

    public static function finiteFloat(string $name, float $default): float
    {
        $raw = env($name, $default);
        if ((! is_int($raw) && ! is_float($raw) && ! is_string($raw)) || ! is_numeric($raw)) {
            throw new \InvalidArgumentException("{$name} must be a finite number.");
        }

        $value = (float) $raw;
        if (! is_finite($value)) {
            throw new \InvalidArgumentException("{$name} must be a finite number.");
        }

        return $value;
    }
}
