<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

/**
 * Immutable result of a multi-algorithm checksum computation.
 *
 * Provides hex-encoded digests and convenience methods for the
 * Base64 and ETag formats used by the S3 API.
 */
final readonly class ChecksumResult
{
    /**
     * @param  string  $md5Hex  Hex-encoded MD5 digest (32 chars).
     * @param  string  $sha256Hex  Hex-encoded SHA-256 digest (64 chars).
     * @param  string  $crc32Hex  Hex-encoded CRC32 digest (big-endian, 8 chars).
     * @param  string  $crc32cHex  Hex-encoded CRC32C digest (big-endian, 8 chars).
     * @param  string  $sha1Hex  Hex-encoded SHA-1 digest (40 chars).
     * @param  int  $size  Total number of bytes processed.
     */
    public function __construct(
        public string $md5Hex,
        public string $sha256Hex,
        public string $crc32Hex,
        public string $crc32cHex,
        public string $sha1Hex,
        public int $size,
    ) {}

    /**
     * MD5 digest as Base64 (for Content-MD5 header).
     */
    public function md5Base64(): string
    {
        return base64_encode((string) hex2bin($this->md5Hex));
    }

    /**
     * SHA-256 digest as Base64 (for x-amz-checksum-sha256 header).
     */
    public function sha256Base64(): string
    {
        return base64_encode((string) hex2bin($this->sha256Hex));
    }

    /**
     * CRC32 digest as Base64 (for x-amz-checksum-crc32 header).
     */
    public function crc32Base64(): string
    {
        return base64_encode((string) hex2bin($this->crc32Hex));
    }

    /**
     * CRC32C digest as Base64 (for x-amz-checksum-crc32c header).
     */
    public function crc32cBase64(): string
    {
        return base64_encode((string) hex2bin($this->crc32cHex));
    }

    /**
     * SHA-1 digest as Base64 (for x-amz-checksum-sha1 header).
     */
    public function sha1Base64(): string
    {
        return base64_encode((string) hex2bin($this->sha1Hex));
    }

    /**
     * S3 ETag value: quoted hex MD5 for single-part uploads.
     *
     * For multipart uploads, the caller must construct the ETag
     * separately ({md5-of-concatenated-part-md5s}-{partCount}).
     */
    public function etag(): string
    {
        return '"'.$this->md5Hex.'"';
    }
}
