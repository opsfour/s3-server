<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;

/** Verifies logical multipart bytes against their authoritative part layout. */
final class MultipartObjectIntegrityVerifier
{
    private int $partIndex = 0;

    private int $partBytes = 0;

    private int $totalBytes = 0;

    private string $partMd5s = '';

    private \HashContext $partMd5;

    private ?ChecksumCalculator $partChecksum = null;

    private bool $finished = false;

    private readonly string $expectedEtag;

    public function __construct(
        private readonly ObjectPartLayout $layout,
        string $expectedEtag,
    ) {
        if ($layout->kind !== ObjectPartLayout::KNOWN_MULTIPART) {
            throw new \InvalidArgumentException('Multipart integrity verification requires a known multipart layout.');
        }

        $normalizedEtag = $expectedEtag;
        if (strlen($normalizedEtag) >= 2 && $normalizedEtag[0] === '"' && $normalizedEtag[-1] === '"') {
            $normalizedEtag = substr($normalizedEtag, 1, -1);
        }
        if (preg_match('/\A([0-9a-fA-F]{32})-([1-9][0-9]{0,4})\z/D', $normalizedEtag, $matches) !== 1
            || (int) $matches[2] !== count($layout->parts)) {
            $this->fail();
        }
        $this->expectedEtag = strtolower($normalizedEtag);

        $this->startPart();
    }

    public function update(string $bytes): void
    {
        if ($this->finished) {
            throw new \LogicException('Multipart integrity verification is already complete.');
        }

        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $this->finishEmptyParts();
            $part = $this->currentPart();
            if ($part === null) {
                $this->fail();
            }

            $remaining = $part->size - $this->partBytes;
            $take = min($remaining, $length - $offset);
            $chunk = substr($bytes, $offset, $take);
            hash_update($this->partMd5, $chunk);
            $this->partChecksum?->update($chunk);
            $this->partBytes += $take;
            $this->totalBytes += $take;
            $offset += $take;

            if ($this->partBytes === $part->size) {
                $this->finishPart($part);
            }
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            throw new \LogicException('Multipart integrity verification is already complete.');
        }

        $this->finishEmptyParts();
        if ($this->partIndex !== count($this->layout->parts)
            || $this->totalBytes !== $this->layout->objectSize) {
            $this->fail();
        }

        $actual = md5($this->partMd5s) . '-' . count($this->layout->parts);
        if (! hash_equals($this->expectedEtag, $actual)) {
            $this->fail();
        }

        $this->finished = true;
    }

    private function finishEmptyParts(): void
    {
        while (($part = $this->currentPart()) !== null && $part->size === 0) {
            $this->finishPart($part);
        }
    }

    private function currentPart(): ?ObjectPart
    {
        return $this->layout->parts[$this->partIndex] ?? null;
    }

    private function startPart(): void
    {
        $this->partMd5 = hash_init('md5');
        $part = $this->currentPart();
        $this->partChecksum = $part?->checksumAlgorithm === null ? null : new ChecksumCalculator();
    }

    private function finishPart(ObjectPart $part): void
    {
        $this->partMd5s .= hash_final($this->partMd5, binary: true);
        if ($part->checksumAlgorithm !== null) {
            $checksums = $this->partChecksum?->finalize()
                ?? throw new \LogicException('Multipart part checksum calculator is missing.');
            $actual = match (strtolower($part->checksumAlgorithm)) {
                'crc32' => $checksums->crc32Base64(),
                'crc32c' => $checksums->crc32cBase64(),
                'sha1' => $checksums->sha1Base64(),
                'sha256' => $checksums->sha256Base64(),
                default => throw new InternalErrorException(
                    'Stored multipart part checksum algorithm is unsupported.',
                ),
            };
            if (! hash_equals((string) $part->checksumValue, $actual)) {
                $this->fail();
            }
        }

        ++$this->partIndex;
        $this->partBytes = 0;
        if ($this->partIndex < count($this->layout->parts)) {
            $this->startPart();
        }
    }

    private function fail(): never
    {
        throw new InternalErrorException('Copy source multipart integrity does not match object metadata.');
    }
}
