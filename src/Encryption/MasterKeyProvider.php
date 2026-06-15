<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/**
 * Interface for retrieving master encryption keys.
 *
 * Supports key rotation: getKeyId() identifies the active key,
 * getMasterKeyById() resolves any historical key for decryption.
 */
interface MasterKeyProvider
{
    /**
     * Get the active master key bytes.
     *
     * @return string The 32-byte master key for AES-256.
     */
    public function getMasterKey(): string;

    /**
     * Get the ID of the active master key.
     */
    public function getKeyId(): string;

    /**
     * Resolve a master key by its ID (for decrypting objects encrypted with historical keys).
     *
     * @throws \RuntimeException If the key ID is unknown.
     */
    public function getMasterKeyById(string $keyId): string;
}
