<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Byte-exact local tier-to-root mappings for an offline integrity audit. */
final class LocalStorageAuditRoots
{
    /** @var array<string, string> */
    private array $rootsByTier = [];

    /** @var list<string> */
    private array $roots = [];

    /** @param iterable<array{tier: string, root: string}> $mappings */
    public function __construct(iterable $mappings)
    {
        foreach ($mappings as $mapping) {
            $tier = $mapping['tier'];
            $configuredRoot = rtrim($mapping['root'], '/');
            $canonical = realpath($configuredRoot);
            if ($tier === '' || strlen($tier) > 64 || str_contains($tier, "\0")
                || ! str_starts_with($configuredRoot, '/') || $canonical === false
                || $canonical !== $configuredRoot || is_link($configuredRoot) || ! is_dir($configuredRoot)) {
                throw new \InvalidArgumentException('Local storage audit tier mappings require a valid tier and canonical absolute directory.');
            }
            $key = self::key($tier);
            if (isset($this->rootsByTier[$key])) {
                throw new \InvalidArgumentException('Local storage audit tier mappings must be unique.');
            }
            foreach ($this->roots as $otherRoot) {
                if ($canonical === $otherRoot
                    || str_starts_with($canonical . '/', $otherRoot . '/')
                    || str_starts_with($otherRoot . '/', $canonical . '/')) {
                    throw new \InvalidArgumentException('Local storage audit roots must not overlap.');
                }
            }
            $this->rootsByTier[$key] = $canonical;
            $this->roots[] = $canonical;
        }
        if ($this->rootsByTier === []) {
            throw new \InvalidArgumentException('At least one local storage tier mapping is required.');
        }
        sort($this->roots, SORT_STRING);
    }

    public function rootFor(?string $tier): ?string
    {
        return $tier === null ? null : ($this->rootsByTier[self::key($tier)] ?? null);
    }

    /** @return list<string> */
    public function roots(): array
    {
        return $this->roots;
    }

    private static function key(string $tier): string
    {
        return "tier\0{$tier}";
    }
}
