<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Parent-row projection used by metadata reads that do not need child boundaries. */
final readonly class ObjectPartLayoutSummary
{
    public function __construct(
        public string $kind,
        public int $partCount,
        public ?int $compatibilityPartCount,
        public string $digest,
    ) {
        if (! in_array($kind, ObjectPartLayout::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown object part layout kind.');
        }
        if ($partCount < 0 || $partCount > 10_000) {
            throw new \InvalidArgumentException('Object part layout count is invalid.');
        }
        if ($compatibilityPartCount !== null && ($compatibilityPartCount < 1 || $compatibilityPartCount > 10_000)) {
            throw new \InvalidArgumentException('Compatibility part count is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1) {
            throw new \InvalidArgumentException('Object part layout digest is invalid.');
        }
        if ($kind === ObjectPartLayout::KNOWN_SINGLE && ($partCount !== 1 || $compatibilityPartCount !== null)) {
            throw new \InvalidArgumentException('Known single layout summary is inconsistent.');
        }
        if ($kind === ObjectPartLayout::KNOWN_MULTIPART && ($partCount < 1 || $compatibilityPartCount !== null)) {
            throw new \InvalidArgumentException('Known multipart layout summary is inconsistent.');
        }
        if ($kind === ObjectPartLayout::UNKNOWN_MULTIPART && ($partCount !== 0 || $compatibilityPartCount === null)) {
            throw new \InvalidArgumentException('Unknown multipart layout summary is inconsistent.');
        }
        if ($kind === ObjectPartLayout::UNKNOWN && ($partCount !== 0 || $compatibilityPartCount !== null)) {
            throw new \InvalidArgumentException('Unknown layout summary is inconsistent.');
        }
    }

    public function isKnownMultipart(): bool
    {
        return $this->kind === ObjectPartLayout::KNOWN_MULTIPART;
    }

    public function responsePartCount(): ?int
    {
        return match ($this->kind) {
            ObjectPartLayout::KNOWN_MULTIPART => $this->partCount,
            ObjectPartLayout::UNKNOWN_MULTIPART => $this->compatibilityPartCount,
            default => null,
        };
    }
}
