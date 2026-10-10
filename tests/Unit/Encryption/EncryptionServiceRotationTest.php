<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceRotationTest extends TestCase
{
    public function test_config_provider_accepts_numeric_key_ids_as_strings(): void
    {
        $key = random_bytes(32);
        $provider = new ConfigMasterKeyProvider(keysJson: json_encode(['123' => base64_encode($key)], JSON_THROW_ON_ERROR));

        self::assertSame('123', $provider->getKeyId());
        self::assertSame($key, $provider->getMasterKey());
        self::assertSame($key, $provider->getMasterKeyById('123'));
    }

    public function test_old_sse_s3_object_remains_readable_after_active_key_rotation(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);
        $oldProvider = new ConfigMasterKeyProvider(keysJson: json_encode([
            'old' => base64_encode($oldKey),
            'new' => base64_encode($newKey),
        ], JSON_THROW_ON_ERROR));
        $encrypted = (new EncryptionService($oldProvider))->encryptSseS3('before rotation');

        $rotatedProvider = new ConfigMasterKeyProvider(keysJson: json_encode([
            'new' => base64_encode($newKey),
            'old' => base64_encode($oldKey),
        ], JSON_THROW_ON_ERROR));
        self::assertSame('before rotation', (new EncryptionService($rotatedProvider))->decryptSseS3(
            $encrypted['ciphertext'],
            $encrypted['encryptedDataKey'],
            $encrypted['iv'],
            $encrypted['tag'],
        ));
    }

    public function test_legacy_blob_remains_readable_when_old_key_is_retained_as_default(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);
        $dataKey = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt('legacy object', 'aes-256-gcm', $dataKey, OPENSSL_RAW_DATA, $iv, $tag);
        self::assertIsString($ciphertext);
        $dataKeyIv = random_bytes(12);
        $dataKeyTag = '';
        $encryptedDataKey = openssl_encrypt($dataKey, 'aes-256-gcm', $oldKey, OPENSSL_RAW_DATA, $dataKeyIv, $dataKeyTag);
        self::assertIsString($encryptedDataKey);

        $provider = new ConfigMasterKeyProvider(keysJson: json_encode([
            'new' => base64_encode($newKey),
            'default' => base64_encode($oldKey),
        ], JSON_THROW_ON_ERROR));
        self::assertSame('new', $provider->getKeyId());
        self::assertSame('legacy object', (new EncryptionService($provider))->decryptSseS3(
            $ciphertext,
            base64_encode($dataKeyIv . $dataKeyTag . $encryptedDataKey),
            base64_encode($iv),
            base64_encode($tag),
        ));
    }

    public function test_encryption_uses_master_key_matching_stored_key_id(): void
    {
        $correctKey = random_bytes(32);
        $provider = $this->createMock(MasterKeyProvider::class);
        $provider->expects(self::never())->method('getMasterKey');
        $provider->method('getKeyId')->willReturn('new');
        $provider->method('getMasterKeyById')->with('new')->willReturn($correctKey);

        $service = new EncryptionService($provider);
        $encrypted = $service->encryptSseS3('consistent');
        self::assertSame('consistent', $service->decryptSseS3(
            $encrypted['ciphertext'],
            $encrypted['encryptedDataKey'],
            $encrypted['iv'],
            $encrypted['tag'],
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeyIds(): iterable
    {
        yield 'empty' => [''];
        yield 'overlong' => [str_repeat('x', 256)];
    }

    #[DataProvider('invalidKeyIds')]
    public function test_rejects_key_ids_that_cannot_be_stored_in_blob(string $keyId): void
    {
        $provider = $this->createMock(MasterKeyProvider::class);
        $provider->method('getKeyId')->willReturn($keyId);
        $provider->expects(self::never())->method('getMasterKeyById');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key ID must contain between 1 and 255 bytes.');
        (new EncryptionService($provider))->encryptSseS3('plaintext');
    }

    public function test_rejects_short_master_key_before_encrypting(): void
    {
        $provider = $this->createMock(MasterKeyProvider::class);
        $provider->method('getKeyId')->willReturn('short');
        $provider->method('getMasterKeyById')->with('short')->willReturn(random_bytes(31));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key must be exactly 32 bytes.');
        (new EncryptionService($provider))->encryptSseS3('plaintext');
    }

    public function test_rejects_tampered_encrypted_data_key_length(): void
    {
        $service = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $encrypted = $service->encryptSseS3('plaintext');
        $rawKey = base64_decode($encrypted['encryptedDataKey'], true);
        self::assertIsString($rawKey);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid encrypted data key length.');
        $service->decryptSseS3(
            $encrypted['ciphertext'],
            base64_encode($rawKey . "\x00"),
            $encrypted['iv'],
            $encrypted['tag'],
        );
    }

    public function test_rejects_short_sse_c_key_on_direct_service_calls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSE-C key must be exactly 32 bytes.');
        (new EncryptionService())->encryptSseC('plaintext', random_bytes(31));
    }
}
