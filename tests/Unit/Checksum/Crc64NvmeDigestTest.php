<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Checksum;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Checksum\Crc64NvmeCapturingStream;
use OpsFour\S3Server\Checksum\Crc64NvmeDigest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Crc64NvmeDigestTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function vectors(): iterable
    {
        yield 'empty' => ['', '0000000000000000', 'AAAAAAAAAAA='];
        yield '123456789' => ['123456789', 'ae8b14860a799888', 'rosUhgp5mIg='];
        yield '1234567890' => ['1234567890', '6025a582347b79f3', 'YCWlgjR7efM='];
        yield 'zeroes' => [str_repeat("\0", 4096), '6482d367eb22b64e', 'ZILTZ+sitk4='];
        yield 'ones' => [str_repeat("\xFF", 4096), 'c0ddba7302eca3ac', 'wN26cwLso6w='];
        yield 'ascending' => [str_repeat(implode('', array_map(chr(...), range(0, 255))), 16), '3e729f5f6750449c', 'PnKfX2dQRJw='];
        yield 'descending' => [str_repeat(implode('', array_map(chr(...), range(255, 0))), 16), '9a2df64b8e9e517e', 'mi32S46eUX4='];
    }

    #[DataProvider('vectors')]
    public function test_vectors_and_arbitrary_chunking(string $input, string $hex, string $base64): void
    {
        foreach ([1, 3, 17, 1023] as $chunkSize) {
            $digest = new Crc64NvmeDigest();
            foreach (str_split($input, $chunkSize) as $chunk) {
                $digest->update($chunk);
                $digest->update('');
            }
            $raw = $digest->finalize();
            self::assertSame($hex, bin2hex($raw));
            self::assertSame($base64, base64_encode($raw));
        }
    }

    public function test_lifecycle_is_one_shot(): void
    {
        $digest = new Crc64NvmeDigest();
        $digest->finalize();
        $this->expectException(\LogicException::class);
        $digest->finalize();
    }

    public function test_update_after_finalize_is_rejected(): void
    {
        $digest = new Crc64NvmeDigest();
        $digest->finalize();
        $this->expectException(\LogicException::class);
        $digest->update('x');
    }

    public function test_clone_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $digest = new Crc64NvmeDigest();
        $copy = clone $digest;
        self::assertNotSame($digest, $copy);
    }

    public function test_wire_decoder_requires_canonical_padded_eight_bytes(): void
    {
        self::assertSame('ae8b14860a799888', bin2hex(Crc64NvmeDigest::decodeBase64('rosUhgp5mIg=')));
        foreach (['rosUhgp5mIg', ' rosUhgp5mIg=', 'AAAA', base64_encode(str_repeat("\0", 9))] as $invalid) {
            try {
                Crc64NvmeDigest::decodeBase64($invalid);
                self::fail('Invalid CRC64NVME wire value was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function test_capture_requires_proven_eof(): void
    {
        $capture = new Crc64NvmeCapturingStream(new ReadableBuffer('123456789'));
        try {
            $capture->finalizeBase64();
            self::fail('CRC64NVME capture finalized before EOF.');
        } catch (\LogicException) {
        }

        self::assertSame('123456789', \Amp\ByteStream\buffer($capture));
        self::assertSame('rosUhgp5mIg=', $capture->finalizeBase64());
    }

    public function test_checksum_calculator_captures_crc64_only_when_requested(): void
    {
        $default = new ChecksumCalculator();
        $default->update('123456789');
        self::assertNull($default->finalize()->crc64NvmeBase64());

        $selected = new ChecksumCalculator(captureCrc64Nvme: true);
        $selected->update('1234');
        $selected->update('56789');
        self::assertSame('rosUhgp5mIg=', $selected->finalize()->crc64NvmeBase64());
    }
}
