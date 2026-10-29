<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Storage\MultipartObjectIntegrityVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartObjectIntegrityVerifierTest extends TestCase
{
    public function test_accepts_chunks_crossing_exact_part_boundaries_and_empty_final_part(): void
    {
        $parts = ['abc', 'defgh', ''];
        $verifier = new MultipartObjectIntegrityVerifier(
            $this->layout($parts),
            $this->etag($parts),
        );

        $verifier->update('a');
        $verifier->update('bcde');
        $verifier->update('fgh');
        $verifier->finish();

        self::addToAssertionCount(1);
    }

    /** @param callable(string): string $checksum */
    #[DataProvider('partChecksumProvider')]
    public function test_validates_each_supported_stored_part_checksum(
        string $algorithm,
        callable $checksum,
    ): void {
        $payload = 'part payload';
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, strlen($payload), [
            new ObjectPart(1, 7, 0, strlen($payload), $algorithm, $checksum($payload)),
        ]);
        $verifier = new MultipartObjectIntegrityVerifier($layout, $this->etag([$payload]));

        $verifier->update('part ');
        $verifier->update('payload');
        $verifier->finish();

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, callable(string): string}> */
    public static function partChecksumProvider(): iterable
    {
        yield 'CRC32' => ['crc32', static fn(string $value): string => self::checksum($value, 'crc32')];
        yield 'CRC32C' => ['crc32c', static fn(string $value): string => self::checksum($value, 'crc32c')];
        yield 'SHA1' => ['sha1', static fn(string $value): string => self::checksum($value, 'sha1')];
        yield 'SHA256' => ['sha256', static fn(string $value): string => self::checksum($value, 'sha256')];
    }

    public function test_rejects_wrong_stored_part_checksum(): void
    {
        $payload = 'payload';
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, strlen($payload), [
            new ObjectPart(1, 1, 0, strlen($payload), 'sha256', base64_encode(hash('sha256', 'wrong', true))),
        ]);
        $verifier = new MultipartObjectIntegrityVerifier($layout, $this->etag([$payload]));

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Copy source multipart integrity does not match object metadata.');

        $verifier->update($payload);
    }

    public function test_rejects_wrong_composite_etag(): void
    {
        $verifier = new MultipartObjectIntegrityVerifier(
            $this->layout(['one', 'two']),
            '"' . str_repeat('0', 32) . '-2"',
        );
        $verifier->update('onetwo');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Copy source multipart integrity does not match object metadata.');

        $verifier->finish();
    }

    public function test_rejects_bytes_beyond_the_layout(): void
    {
        $verifier = new MultipartObjectIntegrityVerifier(
            $this->layout(['one', 'two']),
            $this->etag(['one', 'two']),
        );

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Copy source multipart integrity does not match object metadata.');

        $verifier->update('onetwo!');
    }

    public function test_rejects_truncated_layout_at_finish(): void
    {
        $verifier = new MultipartObjectIntegrityVerifier(
            $this->layout(['one', 'two']),
            $this->etag(['one', 'two']),
        );
        $verifier->update('onetw');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Copy source multipart integrity does not match object metadata.');

        $verifier->finish();
    }

    public function test_rejects_non_multipart_layout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multipart integrity verification requires a known multipart layout.');

        new MultipartObjectIntegrityVerifier(ObjectPartLayout::knownSingle(3), '"etag"');
    }

    #[DataProvider('invalidEtagProvider')]
    public function test_rejects_impossible_etag_before_bytes_are_processed(string $etag): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Copy source multipart integrity does not match object metadata.');

        new MultipartObjectIntegrityVerifier($this->layout(['one', 'two']), $etag);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEtagProvider(): iterable
    {
        yield 'not multipart syntax' => ['"etag"'];
        yield 'part count differs from layout' => ['"' . str_repeat('0', 32) . '-3"'];
        yield 'mismatched quotes' => ['"' . str_repeat('0', 32) . '-2'];
    }

    /** @param list<string> $parts */
    private function layout(array $parts): ObjectPartLayout
    {
        $offset = 0;
        $layoutParts = [];
        foreach ($parts as $index => $part) {
            $layoutParts[] = new ObjectPart($index + 1, $index + 1, $offset, strlen($part));
            $offset += strlen($part);
        }

        return new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, $offset, $layoutParts);
    }

    /** @param list<string> $parts */
    private function etag(array $parts): string
    {
        $partMd5s = '';
        foreach ($parts as $part) {
            $partMd5s .= md5($part, binary: true);
        }

        return '"' . md5($partMd5s) . '-' . count($parts) . '"';
    }

    private static function checksum(string $payload, string $algorithm): string
    {
        $calculator = new ChecksumCalculator();
        $calculator->update($payload);
        $checksums = $calculator->finalize();

        return match ($algorithm) {
            'crc32' => $checksums->crc32Base64(),
            'crc32c' => $checksums->crc32cBase64(),
            'sha1' => $checksums->sha1Base64(),
            'sha256' => $checksums->sha256Base64(),
            default => throw new \InvalidArgumentException('Unsupported test checksum algorithm.'),
        };
    }
}
