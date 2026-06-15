<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event;

/**
 * Normalized internal event emitted after S3 metadata commits.
 */
final readonly class S3Event
{
    public function __construct(
        public string $name,
        public string $bucket,
        public string $key,
        public int $size = 0,
        public string $etag = '',
        public string $ownerId = '',
        public string $region = 'us-east-1',
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        /** @var array<string, mixed> */
        public array $attributes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'bucket' => $this->bucket,
            'key' => $this->key,
            'size' => $this->size,
            'etag' => $this->etag,
            'ownerId' => $this->ownerId,
            'region' => $this->region,
            'occurredAt' => $this->occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'attributes' => $this->attributes,
        ];
    }
}
