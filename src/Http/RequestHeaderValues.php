<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidArgumentException;

/** Normalizes repeated HTTP field lines according to each field's grammar. */
final class RequestHeaderValues
{
    /** Combine repeated list-valued field lines as required by HTTP semantics. */
    public static function combined(Request $request, string $name): ?string
    {
        $values = $request->getHeaderArray($name);

        return $values === [] ? null : implode(', ', $values);
    }

    /** @throws InvalidArgumentException */
    public static function singleton(Request $request, string $name): ?string
    {
        $values = $request->getHeaderArray($name);
        if (count($values) > 1) {
            throw new InvalidArgumentException("{$name} must be supplied at most once.");
        }

        return $values[0] ?? null;
    }
}
