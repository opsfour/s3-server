<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

final class ObjectPartLayoutCapability
{
    public static function optional(MetadataStore $metadata): ?ObjectPartLayoutStore
    {
        return $metadata instanceof ObjectPartLayoutStore && $metadata->supportsObjectPartLayouts()
            ? $metadata
            : null;
    }

    public static function require(MetadataStore $metadata): ObjectPartLayoutStore
    {
        $capability = self::optional($metadata);
        if ($capability === null) {
            throw new \LogicException('Metadata store does not support authoritative object part layouts.');
        }

        return $capability;
    }
}
