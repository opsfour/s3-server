<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/** The only bucket default supported until a KMS adapter exists. */
final class StoredBucketEncryptionConfiguration
{
    /** @param array{sseAlgorithm: string, kmsMasterKeyId?: ?string, bucketKeyEnabled?: bool}|null $config */
    public static function assertSupported(?array $config): void
    {
        if ($config === null) {
            return;
        }

        if ($config['sseAlgorithm'] !== EncryptionRequestResolver::SSE_S3
            || ($config['kmsMasterKeyId'] ?? null) !== null
            || ($config['bucketKeyEnabled'] ?? false)) {
            throw new \RuntimeException('Stored bucket encryption configuration is unsupported; repair metadata before serving.');
        }
    }

    public static function invalidRowQuery(string $driver): string
    {
        $disabled = match ($driver) {
            'postgres' => 'FALSE',
            'mysql', 'sqlite' => '0',
            default => throw new \InvalidArgumentException('Unsupported metadata driver.'),
        };

        return "SELECT 1 FROM s3_encryption_configs WHERE sse_algorithm <> 'AES256' "
            . "OR kms_master_key_id IS NOT NULL OR bucket_key_enabled <> {$disabled} LIMIT 1";
    }
}
