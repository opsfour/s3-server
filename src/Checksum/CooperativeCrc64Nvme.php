<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

/** Bounded CRC64 updates for request-handler fibers; worker callers stay synchronous. */
final class CooperativeCrc64Nvme
{
    private const int BLOCK_BYTES = 65_536;

    public static function update(Crc64NvmeDigest $digest, string $data): void
    {
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += self::BLOCK_BYTES) {
            $digest->update(substr($data, $offset, self::BLOCK_BYTES));
            if ($offset + self::BLOCK_BYTES < $length && \Fiber::getCurrent() !== null) {
                \Amp\delay(0);
            }
        }
    }
}
