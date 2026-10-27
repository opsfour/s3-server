<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Validated immutable logical layout for one concrete object incarnation. */
final readonly class ObjectPartLayout
{
    public const string KNOWN_SINGLE = 'known_single';
    public const string KNOWN_MULTIPART = 'known_multipart';
    public const string UNKNOWN_MULTIPART = 'unknown_multipart';
    public const string UNKNOWN = 'unknown';

    public const array KINDS = [
        self::KNOWN_SINGLE,
        self::KNOWN_MULTIPART,
        self::UNKNOWN_MULTIPART,
        self::UNKNOWN,
    ];

    /** @param list<ObjectPart> $parts */
    public function __construct(
        public string $kind,
        public int $objectSize,
        public array $parts = [],
        public ?int $compatibilityPartCount = null,
    ) {
        if (! in_array($kind, self::KINDS, true) || $objectSize < 0) {
            throw new \InvalidArgumentException('Object part layout is invalid.');
        }

        if ($kind === self::KNOWN_SINGLE || $kind === self::KNOWN_MULTIPART) {
            if ($parts === [] || count($parts) > 10_000 || $compatibilityPartCount !== null) {
                throw new \InvalidArgumentException('Known layouts require parts and no compatibility count.');
            }
            $expectedOffset = 0;
            $uploadPartNumbers = [];
            foreach ($parts as $index => $part) {
                if (! $part instanceof ObjectPart || $part->ordinal !== $index + 1 || $part->offset !== $expectedOffset) {
                    throw new \InvalidArgumentException('Known object parts must have contiguous ordinals and offsets.');
                }
                $expectedOffset += $part->size;
                if ($kind === self::KNOWN_MULTIPART) {
                    $uploadPartNumber = $part->uploadPartNumber;
                    if ($uploadPartNumber === null) {
                        throw new \InvalidArgumentException('Known multipart layouts retain original upload part numbers.');
                    }
                    if (isset($uploadPartNumbers[$uploadPartNumber])) {
                        throw new \InvalidArgumentException('Known multipart layouts require unique upload part numbers.');
                    }
                    $uploadPartNumbers[$uploadPartNumber] = true;
                }
            }
            if ($expectedOffset !== $objectSize) {
                throw new \InvalidArgumentException('Known object parts must exactly cover the object size.');
            }
            if ($kind === self::KNOWN_SINGLE && (count($parts) !== 1 || $parts[0]->uploadPartNumber !== null)) {
                throw new \InvalidArgumentException('Known single layouts contain one non-upload part.');
            }
            if ($kind === self::KNOWN_MULTIPART && count($parts) < 1) {
                throw new \InvalidArgumentException('Known multipart layouts require at least one part.');
            }
        } elseif ($parts !== []) {
            throw new \InvalidArgumentException('Unknown layouts cannot contain inferred boundaries.');
        }

        if ($kind === self::UNKNOWN_MULTIPART) {
            if ($compatibilityPartCount === null || $compatibilityPartCount < 1 || $compatibilityPartCount > 10_000) {
                throw new \InvalidArgumentException('Unknown multipart layouts require a compatibility count.');
            }
        } elseif ($compatibilityPartCount !== null) {
            throw new \InvalidArgumentException('Only unknown multipart layouts carry a compatibility count.');
        }
    }

    public static function knownSingle(int $size): self
    {
        return new self(self::KNOWN_SINGLE, $size, [new ObjectPart(1, null, 0, $size)]);
    }

    /**
     * @param list<array{partNumber: int, size: int, checksumAlgorithm?: ?string, checksumValue?: ?string}> $selectedParts
     */
    public static function completedMultipart(int $objectSize, array $selectedParts): self
    {
        $offset = 0;
        $parts = [];
        foreach ($selectedParts as $index => $part) {
            $parts[] = new ObjectPart(
                ordinal: $index + 1,
                uploadPartNumber: $part['partNumber'],
                offset: $offset,
                size: $part['size'],
                checksumAlgorithm: $part['checksumAlgorithm'] ?? null,
                checksumValue: $part['checksumValue'] ?? null,
            );
            $offset += $part['size'];
        }

        return new self(self::KNOWN_MULTIPART, $objectSize, $parts);
    }

    public static function legacy(string $etag, int $size): self
    {
        $unquoted = $etag;
        if (strlen($unquoted) >= 2 && $unquoted[0] === '"' && $unquoted[-1] === '"') {
            $unquoted = substr($unquoted, 1, -1);
        }
        if (preg_match('/\A[0-9a-f]{32}\z/iD', $unquoted) === 1) {
            return self::knownSingle($size);
        }
        if (preg_match('/\A[0-9a-f]{32}-([1-9][0-9]{0,4})\z/iD', $unquoted, $matches) === 1) {
            $count = (int) $matches[1];
            if ($count <= 10_000) {
                return new self(self::UNKNOWN_MULTIPART, $size, compatibilityPartCount: $count);
            }
        }

        return new self(self::UNKNOWN, $size);
    }

    public function summary(int|string $objectIdentity): ObjectPartLayoutSummary
    {
        return new ObjectPartLayoutSummary($this->kind, count($this->parts), $this->compatibilityPartCount, $this->digestForObject($objectIdentity));
    }

    private function logicalDigest(): string
    {
        return hash('sha256', json_encode([
            'kind' => $this->kind,
            'objectSize' => $this->objectSize,
            'compatibilityPartCount' => $this->compatibilityPartCount,
            'parts' => array_map(static fn(ObjectPart $part): array => $part->canonical(), $this->parts),
        ], JSON_THROW_ON_ERROR));
    }

    public function digestForObject(int|string $objectIdentity): string
    {
        return hash('sha256', (string) $objectIdentity . "\0" . $this->logicalDigest());
    }
}
