<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

/**
 * Streaming CRC64/NVME using two unsigned 32-bit limbs.
 *
 * Keeping the state split avoids signed overflow and host-endian behaviour in
 * PHP while still producing the exact big-endian eight-byte S3 digest.
 */
final class Crc64NvmeDigest implements IncrementalDigest
{
    private int $high = 0xFFFFFFFF;

    private int $low = 0xFFFFFFFF;

    private bool $finalized = false;

    /** @var array<int, array{int, int}>|null */
    private static ?array $table = null;

    public function __clone(): void
    {
        throw new \LogicException('CRC64NVME digest cloning is not supported.');
    }

    public function update(string $data): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot update a finalized CRC64NVME digest.');
        }

        $table = self::$table ??= self::createTable();
        $length = strlen($data);
        for ($offset = 0; $offset < $length; ++$offset) {
            [$tableHigh, $tableLow] = $table[($this->low ^ ord($data[$offset])) & 0xFF];
            $shiftedLow = (($this->low >> 8) | (($this->high & 0xFF) << 24)) & 0xFFFFFFFF;
            $this->high = (($this->high >> 8) ^ $tableHigh) & 0xFFFFFFFF;
            $this->low = ($shiftedLow ^ $tableLow) & 0xFFFFFFFF;
        }
    }

    public function finalize(): string
    {
        if ($this->finalized) {
            throw new \LogicException('CRC64NVME digest has already been finalized.');
        }
        $this->finalized = true;

        return pack('N2', $this->high ^ 0xFFFFFFFF, $this->low ^ 0xFFFFFFFF);
    }

    public function finalizeBase64(): string
    {
        return base64_encode($this->finalize());
    }

    /** Validate and return the canonical raw eight-byte wire digest. */
    public static function decodeBase64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) !== 8 || base64_encode($decoded) !== $value) {
            throw new \InvalidArgumentException('CRC64NVME must be strict padded Base64 encoding of eight bytes.');
        }

        return $decoded;
    }

    /** @return array<int, array{int, int}> */
    private static function createTable(): array
    {
        $table = [];
        for ($index = 0; $index < 256; ++$index) {
            $high = 0;
            $low = $index;
            for ($bit = 0; $bit < 8; ++$bit) {
                $set = ($low & 1) === 1;
                $low = (($low >> 1) | (($high & 1) << 31)) & 0xFFFFFFFF;
                $high = ($high >> 1) & 0xFFFFFFFF;
                if ($set) {
                    $high ^= 0x9A6C9329;
                    $low ^= 0xAC4BC9B5;
                }
            }
            $table[$index] = [$high & 0xFFFFFFFF, $low & 0xFFFFFFFF];
        }

        return $table;
    }
}
