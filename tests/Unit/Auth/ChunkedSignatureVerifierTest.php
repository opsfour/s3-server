<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Auth\ChunkedSignatureVerifier;
use OpsFour\S3Server\Checksum\Crc64NvmeDigest;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChunkedSignatureVerifierTest extends TestCase
{
    private const string EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_verifies_signed_checksum_trailer_and_returns_payload(): void
    {
        $key = random_bytes(32);
        $timestamp = '20260723T120000Z';
        $scope = '20260723/eu-central-1/s3/aws4_request';
        $seed = str_repeat('a', 64);
        $payload = 'signed streamed payload';
        [$body, $checksum] = $this->signedBody($payload, $key, $timestamp, $scope, $seed);
        $trailers = [];

        $stream = (new ChunkedSignatureVerifier())->createVerifiedStream(
            new ReadableBuffer($body),
            $key,
            $timestamp,
            $scope,
            $seed,
            ['x-amz-checksum-sha256'],
            static function (array $values) use (&$trailers): void {
                $trailers = $values;
            },
        );

        self::assertSame($payload, \Amp\ByteStream\buffer($stream));
        self::assertSame(['x-amz-checksum-sha256' => $checksum], $trailers);
    }

    public function test_rejects_signed_trailer_with_wrong_payload_checksum(): void
    {
        $key = random_bytes(32);
        $timestamp = '20260723T120000Z';
        $scope = '20260723/eu-central-1/s3/aws4_request';
        $seed = str_repeat('b', 64);
        [$body] = $this->signedBody(
            'payload',
            $key,
            $timestamp,
            $scope,
            $seed,
            base64_encode(hash('sha256', 'different', true)),
        );

        $stream = (new ChunkedSignatureVerifier())->createVerifiedStream(
            new ReadableBuffer($body),
            $key,
            $timestamp,
            $scope,
            $seed,
            ['x-amz-checksum-sha256'],
        );

        $this->expectException(BadDigestException::class);
        \Amp\ByteStream\buffer($stream);
    }

    public function test_rejects_bytes_after_signed_trailer_block(): void
    {
        $key = random_bytes(32);
        $timestamp = '20260723T120000Z';
        $scope = '20260723/eu-central-1/s3/aws4_request';
        $seed = str_repeat('c', 64);
        [$body] = $this->signedBody('payload', $key, $timestamp, $scope, $seed);
        $stream = (new ChunkedSignatureVerifier())->createVerifiedStream(
            new ReadableBuffer($body . 'extra'),
            $key,
            $timestamp,
            $scope,
            $seed,
            ['x-amz-checksum-sha256'],
        );

        $this->expectException(InvalidArgumentException::class);
        \Amp\ByteStream\buffer($stream);
    }

    public function test_verifies_signed_crc64nvme_trailer(): void
    {
        $key = random_bytes(32);
        $timestamp = '20260723T120000Z';
        $scope = '20260723/eu-central-1/s3/aws4_request';
        $seed = str_repeat('d', 64);
        $payload = 'signed CRC64NVME payload';
        $digest = new Crc64NvmeDigest();
        $digest->update($payload);
        [$body, $checksum] = $this->signedBody(
            $payload,
            $key,
            $timestamp,
            $scope,
            $seed,
            $digest->finalizeBase64(),
            'x-amz-checksum-crc64nvme',
        );
        $body = str_replace(
            "x-amz-checksum-crc64nvme:{$checksum}\r\n",
            "x-amz-checksum-crc64nvme: {$checksum}\t\r\n",
            $body,
        );
        $trailers = [];

        $stream = (new ChunkedSignatureVerifier())->createVerifiedStream(
            new ReadableBuffer($body),
            $key,
            $timestamp,
            $scope,
            $seed,
            ['x-amz-checksum-crc64nvme'],
            static function (array $values) use (&$trailers): void {
                $trailers = $values;
            },
        );

        self::assertSame($payload, \Amp\ByteStream\buffer($stream));
        self::assertSame(['x-amz-checksum-crc64nvme' => $checksum], $trailers);
    }

    public function test_rejects_signed_crc64nvme_trailer_with_embedded_whitespace(): void
    {
        $key = random_bytes(32);
        $timestamp = '20260723T120000Z';
        $scope = '20260723/eu-central-1/s3/aws4_request';
        $seed = str_repeat('e', 64);
        [$body] = $this->signedBody(
            'payload',
            $key,
            $timestamp,
            $scope,
            $seed,
            'AAAA AAAAAAA=',
            'x-amz-checksum-crc64nvme',
        );
        $stream = (new ChunkedSignatureVerifier())->createVerifiedStream(
            new ReadableBuffer($body),
            $key,
            $timestamp,
            $scope,
            $seed,
            ['x-amz-checksum-crc64nvme'],
        );

        $this->expectException(BadDigestException::class);
        \Amp\ByteStream\buffer($stream);
    }

    /**
     * @return array{string, string}
     */
    private function signedBody(
        string $payload,
        string $key,
        string $timestamp,
        string $scope,
        string $seed,
        ?string $checksum = null,
        string $checksumHeader = 'x-amz-checksum-sha256',
    ): array {
        $dataSignature = $this->chunkSignature($payload, $key, $timestamp, $scope, $seed);
        $finalSignature = $this->chunkSignature('', $key, $timestamp, $scope, $dataSignature);
        $checksum ??= base64_encode(hash('sha256', $payload, true));
        $canonical = "{$checksumHeader}:{$checksum}\n";
        $trailerStringToSign = implode("\n", [
            'AWS4-HMAC-SHA256-TRAILER',
            $timestamp,
            $scope,
            $finalSignature,
            hash('sha256', $canonical),
        ]);
        $trailerSignature = hash_hmac('sha256', $trailerStringToSign, $key);

        return [
            dechex(strlen($payload)) . ";chunk-signature={$dataSignature}\r\n"
            . $payload . "\r\n"
            . "0;chunk-signature={$finalSignature}\r\n"
            . "{$checksumHeader}:{$checksum}\r\n"
            . "x-amz-trailer-signature:{$trailerSignature}\r\n\r\n",
            $checksum,
        ];
    }

    private function chunkSignature(
        string $payload,
        string $key,
        string $timestamp,
        string $scope,
        string $previous,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            'AWS4-HMAC-SHA256-PAYLOAD',
            $timestamp,
            $scope,
            $previous,
            self::EMPTY_HASH,
            hash('sha256', $payload),
        ]), $key);
    }
}
