<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

/** Parse package environment booleans without treating arbitrary strings as true. */
final class EnvironmentBoolean
{
    public static function boolean(string $name, bool $default): bool
    {
        $value = filter_var(env($name, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \InvalidArgumentException("{$name} must be true or false.");
        }

        return $value;
    }
}
