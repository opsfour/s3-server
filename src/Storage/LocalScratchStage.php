<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumResult;

/** A completed node-local stage whose byte reservation remains held. */
final readonly class LocalScratchStage
{
    public function __construct(
        public string $path,
        public StorageTempReservation $reservation,
        public int $size,
        /** Raw SHA-256 bytes for worker input verification. */
        public string $sha256,
        /** Complete physical checksums measured during the only body read. */
        public ?ChecksumResult $checksums = null,
    ) {}
}
