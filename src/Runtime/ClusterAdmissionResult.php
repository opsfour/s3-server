<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

final readonly class ClusterAdmissionResult
{
    private const array CATEGORIES = [
        'cluster',
        'protocol',
        'database',
        'authentication',
        'storage',
        'tiers',
        'encryption',
        'compatibility',
        'roles',
    ];

    private function __construct(
        public bool $matched,
        public ?string $category,
        public string $databaseIdentity,
    ) {}

    public static function matched(string $databaseIdentity): self
    {
        return new self(true, null, $databaseIdentity);
    }

    public static function mismatch(string $category, string $databaseIdentity): self
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Unknown cluster admission mismatch category.');
        }

        return new self(false, $category, $databaseIdentity);
    }
}
