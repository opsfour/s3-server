<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

/**
 * Incrementally computes multiple checksums on streaming data.
 *
 * Tracks MD5, SHA-256, CRC32, CRC32C, and SHA-1 simultaneously so a
 * single pass through the data produces all digests needed by S3.
 *
 * Usage:
 *     $calc = new ChecksumCalculator();
 *     while ($chunk = $stream->read()) {
 *         $calc->update($chunk);
 *     }
 *     $result = $calc->finalize();
 */
final class ChecksumCalculator
{
    private \HashContext $md5Context;

    private \HashContext $sha256Context;

    private \HashContext $crc32Context;

    private \HashContext $sha1Context;

    private int $crc32cValue = 0;

    private int $size = 0;

    private bool $finalized = false;

    public function __construct()
    {
        $this->md5Context = hash_init('md5');
        $this->sha256Context = hash_init('sha256');
        $this->crc32Context = hash_init('crc32b');
        $this->sha1Context = hash_init('sha1');
    }

    /**
     * Feed a chunk of data into all hash contexts.
     *
     * @param  string  $data  Raw bytes to process.
     *
     * @throws \LogicException If called after finalize().
     */
    public function update(string $data): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot update a finalized ChecksumCalculator.');
        }

        hash_update($this->md5Context, $data);
        hash_update($this->sha256Context, $data);
        hash_update($this->crc32Context, $data);
        hash_update($this->sha1Context, $data);
        $this->crc32cValue = self::crc32cUpdate($this->crc32cValue, $data);
        $this->size += strlen($data);
    }

    /**
     * Finalize all hash contexts and return the aggregated result.
     *
     * This method can only be called once. After finalization,
     * further calls to update() will throw.
     *
     * @return ChecksumResult Completed checksums and byte count.
     *
     * @throws \LogicException If called more than once.
     */
    public function finalize(): ChecksumResult
    {
        if ($this->finalized) {
            throw new \LogicException('ChecksumCalculator has already been finalized.');
        }

        $this->finalized = true;

        $md5Hex = hash_final($this->md5Context);
        $sha256Hex = hash_final($this->sha256Context);
        $crc32Hex = hash_final($this->crc32Context);
        $sha1Hex = hash_final($this->sha1Context);

        // CRC32C: convert integer to 4-byte big-endian hex
        $crc32cHex = str_pad(dechex($this->crc32cValue & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT);

        return new ChecksumResult(
            md5Hex: $md5Hex,
            sha256Hex: $sha256Hex,
            crc32Hex: $crc32Hex,
            crc32cHex: $crc32cHex,
            sha1Hex: $sha1Hex,
            size: $this->size,
        );
    }

    /**
     * Current byte count processed so far.
     */
    public function currentSize(): int
    {
        return $this->size;
    }

    /**
     * Compute CRC32C incrementally using the Castagnoli polynomial (0x1EDC6F41).
     *
     * If the hash extension provides crc32c (PHP 7.4+ with libgmp or the
     * crc32c extension), we delegate to it. Otherwise we fall back to a
     * pure-PHP software table implementation.
     *
     * @param  int  $crc  Running CRC32C value.
     * @param  string  $data  Data chunk.
     * @return int Updated CRC32C value.
     */
    private static function crc32cUpdate(int $crc, string $data): int
    {
        // Prefer hardware-accelerated implementation if available
        if (self::hasCrc32cHash()) {
            return self::crc32cViaHashExtension($crc, $data);
        }

        return self::crc32cSoftware($crc, $data);
    }

    /**
     * Check if PHP's hash extension supports CRC32C.
     */
    private static function hasCrc32cHash(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $supported = in_array('crc32c', hash_algos(), true);
        }

        return $supported;
    }

    /**
     * Compute CRC32C using PHP's hash extension.
     *
     * The hash extension's crc32c always starts from 0, so we need to
     * process the data in one shot per chunk and combine with the running value.
     * For incremental computation with the hash extension, we keep a context.
     */
    private static function crc32cViaHashExtension(int $previousCrc, string $data): int
    {
        // For the first call (crc=0), simply compute from scratch
        // For subsequent calls, we need to combine CRCs
        if ($previousCrc === 0) {
            $hex = hash('crc32c', $data);

            return (int) hexdec($hex);
        }

        // Compute CRC of this chunk alone
        $chunkHex = hash('crc32c', $data);
        $chunkCrc = (int) hexdec($chunkHex);

        return self::crc32cCombine($previousCrc, $chunkCrc, strlen($data));
    }

    /**
     * Software CRC32C using the Castagnoli polynomial lookup table.
     *
     * @param  int  $crc  Running CRC (pass 0 for initial).
     * @param  string  $data  Data to process.
     * @return int Updated CRC32C value.
     */
    private static function crc32cSoftware(int $crc, string $data): int
    {
        static $table = null;

        if ($table === null) {
            $table = self::generateCrc32cTable();
        }

        $crc ^= 0xFFFFFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc = ($crc >> 8) ^ $table[($crc ^ ord($data[$i])) & 0xFF];
        }

        return ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
    }

    /**
     * Generate the CRC32C lookup table (Castagnoli polynomial 0x82F63B78).
     *
     * @return array<int, int> 256-entry lookup table.
     */
    private static function generateCrc32cTable(): array
    {
        $table = [];
        $poly = 0x82F63B78; // Reversed Castagnoli polynomial

        for ($i = 0; $i < 256; $i++) {
            $crc = $i;
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 1) {
                    $crc = ($crc >> 1) ^ $poly;
                } else {
                    $crc >>= 1;
                }
            }
            $table[$i] = $crc & 0xFFFFFFFF;
        }

        return $table;
    }

    /**
     * Combine two CRC32C values.
     *
     * Uses the GF(2) matrix-based approach to combine CRC of data1
     * with CRC of data2 when data2 has known length.
     *
     * @param  int  $crc1  CRC of first data block.
     * @param  int  $crc2  CRC of second data block.
     * @param  int  $len2  Length of second data block.
     * @return int Combined CRC32C.
     */
    private static function crc32cCombine(int $crc1, int $crc2, int $len2): int
    {
        if ($len2 === 0) {
            return $crc1;
        }

        $poly = 0x82F63B78;

        // Build "zeros operator" matrix: what happens when you append len2 zero bytes
        // Start with the "shift by one zero byte" operator
        $even = array_fill(0, 32, 0);
        $odd = array_fill(0, 32, 0);

        // Odd-power matrix: shift by one bit with polynomial reduction
        $odd[0] = $poly;
        for ($i = 1; $i < 32; $i++) {
            $odd[$i] = 1 << ($i - 1);
        }

        // Even-power matrix = odd squared
        self::gf2MatrixSquare($even, $odd);
        self::gf2MatrixSquare($odd, $even);

        // Apply len2 zero bytes to crc1
        $len2Remaining = $len2;
        while ($len2Remaining > 0) {
            // Square even into odd, then odd into even
            self::gf2MatrixSquare($even, $odd);
            if ($len2Remaining & 1) {
                $crc1 = self::gf2MatrixTimes($even, $crc1);
            }
            $len2Remaining >>= 1;

            if ($len2Remaining === 0) {
                break;
            }

            self::gf2MatrixSquare($odd, $even);
            if ($len2Remaining & 1) {
                $crc1 = self::gf2MatrixTimes($odd, $crc1);
            }
            $len2Remaining >>= 1;
        }

        return ($crc1 ^ $crc2) & 0xFFFFFFFF;
    }

    /**
     * Multiply a GF(2) vector by a matrix.
     *
     * @param  array<int, int>  $mat  32-element matrix.
     * @param  int  $vec  Vector (32-bit integer).
     * @return int Resulting vector.
     */
    private static function gf2MatrixTimes(array $mat, int $vec): int
    {
        $sum = 0;

        for ($i = 0; $vec !== 0; $i++) {
            if ($vec & 1) {
                $sum ^= $mat[$i];
            }
            $vec = ($vec >> 1) & 0x7FFFFFFF; // Unsigned right shift
        }

        return $sum;
    }

    /**
     * Square a GF(2) matrix in place.
     *
     * @param  array<int, int>  $square  Output matrix (modified in place).
     * @param  array<int, int>  $mat  Input matrix.
     */
    private static function gf2MatrixSquare(array &$square, array $mat): void
    {
        for ($i = 0; $i < 32; $i++) {
            $square[$i] = self::gf2MatrixTimes($mat, $mat[$i]);
        }
    }
}
