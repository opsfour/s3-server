<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

/** @internal Standalone CLI environment lookup with absence-only defaults. */
final class Environment
{
    /**
     * @template TDefault
     *
     * @param TDefault $default
     *
     * @return string|TDefault
     */
    public static function value(string $name, mixed $default = null): mixed
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
