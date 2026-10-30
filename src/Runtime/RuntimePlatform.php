<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

final class RuntimePlatform
{
    public static function assertSupported(?int $integerBytes = null): void
    {
        if (($integerBytes ?? PHP_INT_SIZE) !== 8) {
            throw new \RuntimeException(
                'opsfour/s3-server requires 64-bit PHP; install a 64-bit PHP runtime before accepting S3 traffic.',
            );
        }
    }
}
