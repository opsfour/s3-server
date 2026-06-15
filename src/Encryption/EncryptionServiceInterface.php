<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/**
 * Contract for S3 server-side encryption operations.
 *
 * Supports SSE-S3 (server-managed keys) and SSE-C (customer-provided keys)
 * using AES-256-GCM.
 */
interface EncryptionServiceInterface
{
    /**
     * Encrypt data using SSE-S3 (server-managed key).
     *
     * @return array{ciphertext: string, encryptedDataKey: string, iv: string, tag: string}
     */
    public function encryptSseS3(string $plaintext): array;

    /**
     * Decrypt data using SSE-S3.
     */
    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string;

    /**
     * Encrypt data using SSE-C (customer-provided key).
     *
     * @return array{ciphertext: string, iv: string, tag: string}
     */
    public function encryptSseC(string $plaintext, string $customerKey): array;

    /**
     * Decrypt data using SSE-C.
     */
    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string;
}
