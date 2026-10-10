<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use PHPUnit\Framework\TestCase;

final class EncryptedMultipartPartCodecTest extends TestCase
{
    public function test_sse_s3_part_is_authenticated_and_bound_to_upload_and_part(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $stored = EncryptedMultipartPartCodec::encode(
            $encryption,
            EncryptionRequestResolver::SSE_S3,
            'secret payload',
            null,
            'generation',
            'bucket',
            'upload-a',
            1,
        );
        self::assertStringStartsWith("S3MPU2\0", $stored);
        self::assertStringNotContainsString('secret payload', $stored);
        self::assertSame('secret payload', EncryptedMultipartPartCodec::decode(
            $encryption,
            EncryptionRequestResolver::SSE_S3,
            $stored,
            null,
            'generation',
            'bucket',
            'upload-a',
            1,
        ));

        foreach ([['generation', 'bucket', 'upload-a', 2], ['generation', 'bucket', 'upload-b', 1], ['other', 'bucket', 'upload-a', 1]] as $context) {
            $rejected = false;
            try {
                EncryptedMultipartPartCodec::decode(
                    $encryption,
                    EncryptionRequestResolver::SSE_S3,
                    $stored,
                    null,
                    ...$context,
                );
            } catch (InternalErrorException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'A part was accepted in a different upload context.');
        }
    }

    public function test_sse_c_part_rejects_wrong_key_and_tampering(): void
    {
        $encryption = new EncryptionService();
        $key = random_bytes(32);
        $stored = EncryptedMultipartPartCodec::encode(
            $encryption,
            EncryptionRequestResolver::SSE_C,
            'secret payload',
            $key,
            'generation',
            'bucket',
            'upload-a',
            1,
        );
        self::assertSame('secret payload', EncryptedMultipartPartCodec::decode(
            $encryption,
            EncryptionRequestResolver::SSE_C,
            $stored,
            $key,
            'generation',
            'bucket',
            'upload-a',
            1,
        ));
        try {
            EncryptedMultipartPartCodec::decode(
                $encryption,
                EncryptionRequestResolver::SSE_C,
                $stored,
                random_bytes(32),
                'generation',
                'bucket',
                'upload-a',
                1,
            );
            self::fail('Wrong customer key was accepted.');
        } catch (\RuntimeException) {
            // AES-GCM authentication must fail.
        }
        $stored[strlen($stored) - 1] = $stored[strlen($stored) - 1] ^ "\x01";

        $this->expectException(\Throwable::class);
        EncryptedMultipartPartCodec::decode(
            $encryption,
            EncryptionRequestResolver::SSE_C,
            $stored,
            $key,
            'generation',
            'bucket',
            'upload-a',
            1,
        );
    }

    public function test_header_rejects_oversized_wrapped_key_length(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $stored = EncryptedMultipartPartCodec::encode(
            $encryption,
            EncryptionRequestResolver::SSE_S3,
            'secret',
            null,
            'generation',
            'bucket',
            'upload-a',
            1,
        );
        $stored[8] = "\xff";
        $stored[9] = "\xff";

        $this->expectException(InternalErrorException::class);
        EncryptedMultipartPartCodec::decode(
            $encryption,
            EncryptionRequestResolver::SSE_S3,
            $stored,
            null,
            'generation',
            'bucket',
            'upload-a',
            1,
        );
    }
}
