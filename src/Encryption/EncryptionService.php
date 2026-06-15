<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/**
 * Handles SSE-S3 and SSE-C encryption/decryption using AES-256-GCM.
 *
 * For SSE-S3: generates a per-object random data key, encrypts data with it,
 * then encrypts the data key with the master key. The encrypted data key blob
 * includes the key ID so objects can be decrypted after master key rotation.
 *
 * Blob formats:
 * - Legacy (60 bytes decoded): IV(12) + AuthTag(16) + EncryptedDataKey(32)
 * - V1 (64+ bytes decoded): 0x01(1) + keyIdLen(2, LE) + keyId(N) + IV(12) + Tag(16) + EncDK(32)
 *
 * For SSE-C: uses the customer-provided key directly.
 */
final class EncryptionService implements EncryptionServiceInterface
{
    private const string CIPHER = 'aes-256-gcm';
    private const int IV_LENGTH = 12; // GCM standard
    private const int TAG_LENGTH = 16;

    /** Legacy encrypted data key blob size: IV(12) + Tag(16) + EncDK(32). */
    private const int LEGACY_BLOB_SIZE = 60;

    public function __construct(
        private readonly ?MasterKeyProvider $masterKeyProvider = null,
    ) {}

    /**
     * Encrypt data using SSE-S3 (server-managed key).
     *
     * @return array{ciphertext: string, encryptedDataKey: string, iv: string, tag: string}
     */
    public function encryptSseS3(string $plaintext): array
    {
        if ($this->masterKeyProvider === null) {
            throw new \RuntimeException('Master key provider required for SSE-S3.');
        }

        // Generate per-object data key.
        $dataKey = random_bytes(32);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $dataKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        // Encrypt the data key with master key.
        $masterKey = $this->masterKeyProvider->getMasterKey();
        $dkIv = random_bytes(self::IV_LENGTH);
        $dkTag = '';

        $encryptedDataKey = openssl_encrypt(
            $dataKey,
            self::CIPHER,
            $masterKey,
            OPENSSL_RAW_DATA,
            $dkIv,
            $dkTag,
            '',
            self::TAG_LENGTH,
        );

        if ($encryptedDataKey === false) {
            throw new \RuntimeException('Data key encryption failed.');
        }

        // Build V1 blob with key ID for key rotation support.
        $keyId = $this->masterKeyProvider->getKeyId();
        $keyIdBytes = $keyId;
        $blob = chr(0x01) . pack('v', strlen($keyIdBytes)) . $keyIdBytes . $dkIv . $dkTag . $encryptedDataKey;

        return [
            'ciphertext' => $ciphertext,
            'encryptedDataKey' => base64_encode($blob),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * Decrypt data using SSE-S3.
     */
    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
    {
        if ($this->masterKeyProvider === null) {
            throw new \RuntimeException('Master key provider required for SSE-S3.');
        }

        $raw = base64_decode($encryptedDataKeyB64, true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Invalid encrypted data key.');
        }

        // Detect blob format and extract master key + remainder.
        if (strlen($raw) > self::LEGACY_BLOB_SIZE && ord($raw[0]) === 0x01) {
            // V1 format: extract key ID to resolve the correct master key.
            /** @var array{1: int}|false $unpacked */
            $unpacked = unpack('v', substr($raw, 1, 2));
            if ($unpacked === false) {
                throw new \RuntimeException('Corrupt V1 encrypted data key blob: invalid key ID length.');
            }
            $keyIdLen = $unpacked[1];
            if (strlen($raw) < 3 + $keyIdLen + self::IV_LENGTH + self::TAG_LENGTH + 1) {
                throw new \RuntimeException('Corrupt V1 encrypted data key blob: truncated.');
            }
            $keyId = substr($raw, 3, $keyIdLen);
            $remainder = substr($raw, 3 + $keyIdLen);
            $masterKey = $this->masterKeyProvider->getMasterKeyById($keyId);
        } else {
            // Legacy format: use active master key.
            $remainder = $raw;
            $masterKey = $this->masterKeyProvider->getMasterKey();
        }

        // Parse remainder: IV(12) + Tag(16) + EncDK(32+).
        if (strlen($remainder) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Invalid encrypted data key: remainder too short.');
        }

        $dkIv = substr($remainder, 0, self::IV_LENGTH);
        $dkTag = substr($remainder, self::IV_LENGTH, self::TAG_LENGTH);
        $dkCiphertext = substr($remainder, self::IV_LENGTH + self::TAG_LENGTH);

        $dataKey = openssl_decrypt(
            $dkCiphertext,
            self::CIPHER,
            $masterKey,
            OPENSSL_RAW_DATA,
            $dkIv,
            $dkTag,
        );

        if ($dataKey === false) {
            throw new \RuntimeException('Data key decryption failed.');
        }

        // Decrypt the data.
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);

        if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
            throw new \RuntimeException('Invalid IV: expected ' . self::IV_LENGTH . ' bytes.');
        }
        if ($tag === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('Invalid authentication tag: expected ' . self::TAG_LENGTH . ' bytes.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $dataKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Encrypt data using SSE-C (customer-provided key).
     *
     * @param string $plaintext The data to encrypt.
     * @param string $customerKey The raw 32-byte customer key.
     * @return array{ciphertext: string, iv: string, tag: string}
     */
    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $customerKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('SSE-C encryption failed.');
        }

        return [
            'ciphertext' => $ciphertext,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * Decrypt data using SSE-C.
     */
    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
    {
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);

        if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
            throw new \RuntimeException('Invalid IV: expected ' . self::IV_LENGTH . ' bytes.');
        }
        if ($tag === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('Invalid authentication tag: expected ' . self::TAG_LENGTH . ' bytes.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $customerKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('SSE-C decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Validate SSE-C request headers.
     *
     * @return string The raw 32-byte customer key.
     * @throws \OpsFour\S3Server\Exception\InvalidArgumentException
     */
    public static function validateSseCHeaders(string $algorithm, string $keyBase64, string $keyMd5): string
    {
        if ($algorithm !== 'AES256') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'The SSE-C algorithm must be AES256.',
            );
        }

        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'The SSE-C key must be 256 bits (32 bytes) base64-encoded.',
            );
        }

        $expectedMd5 = base64_encode(md5($key, true));
        if (!hash_equals($expectedMd5, $keyMd5)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'The SSE-C key MD5 does not match the provided key.',
            );
        }

        return $key;
    }
}
