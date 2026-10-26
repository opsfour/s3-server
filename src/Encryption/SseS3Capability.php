<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/**
 * Optional capability contract for encryption services that can run without
 * an SSE-S3 master key while still supporting SSE-C.
 *
 * Services that do not implement this interface retain the historical
 * assumption that they support every EncryptionServiceInterface operation.
 */
interface SseS3Capability
{
    public function supportsSseS3(): bool;
}
