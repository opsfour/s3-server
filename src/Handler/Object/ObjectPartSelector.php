<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidRangeException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\ObjectPartLayoutCapability;

/** Parses and resolves the shared GET/HEAD logical-part selection contract. */
final readonly class ObjectPartSelector
{
    private function __construct(private ?int $ordinal) {}

    /**
     * @param array<string, string> $queryParams
     */
    public static function fromRequest(array $queryParams, ?string $rangeHeader): self
    {
        if (! array_key_exists('partNumber', $queryParams)) {
            return new self(null);
        }

        $rawOrdinal = $queryParams['partNumber'];
        if ($rawOrdinal === '' || ! ctype_digit($rawOrdinal)) {
            throw new InvalidArgumentException('Part number must be between 1 and 10000.');
        }
        $ordinal = (int) $rawOrdinal;
        if ($ordinal < 1 || $ordinal > 10_000) {
            throw new InvalidArgumentException('Part number must be between 1 and 10000.');
        }
        if ($rangeHeader !== null) {
            throw new InvalidRequestException('Range and partNumber cannot be used together.');
        }

        return new self($ordinal);
    }

    public function isRequested(): bool
    {
        return $this->ordinal !== null;
    }

    public function select(MetadataStore $metadata, ObjectInfo $object): ?ObjectPartSelection
    {
        if ($this->ordinal === null) {
            return null;
        }

        $layout = ObjectPartLayoutCapability::require($metadata)->getObjectPartLayout($object);
        if ($layout->objectSize !== $object->size) {
            throw new InternalErrorException('Object part layout size does not match object metadata.');
        }
        if ($layout->kind === ObjectPartLayout::UNKNOWN || $layout->kind === ObjectPartLayout::UNKNOWN_MULTIPART) {
            throw new NotImplementedException('Object part boundaries are unavailable for this object.');
        }

        $part = $layout->parts[$this->ordinal - 1] ?? null;
        if ($part === null || $part->ordinal !== $this->ordinal) {
            throw self::invalidRange($object->size);
        }
        if ($part->size === 0) {
            throw self::invalidRange(0);
        }

        return new ObjectPartSelection(
            offset: $part->offset,
            size: $part->size,
            objectSize: $object->size,
            multipartPartCount: $layout->kind === ObjectPartLayout::KNOWN_MULTIPART
                ? count($layout->parts)
                : null,
            checksumAlgorithm: $part->checksumAlgorithm,
            checksumValue: $part->checksumValue,
        );
    }

    private static function invalidRange(int $size): InvalidRangeException
    {
        return (new InvalidRangeException())->withExtraHeaders([
            'Content-Range' => 'bytes */' . $size,
        ]);
    }
}
