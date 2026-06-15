<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * Registry of physical storage tiers used by tier transition and restore jobs.
 */
final class StorageTierRegistry
{
    /** @var array<string, StorageTier> */
    private array $tiers = [];

    private string $defaultTierName;

    /**
     * @param iterable<StorageTier> $tiers
     */
    public function __construct(iterable $tiers)
    {
        foreach ($tiers as $tier) {
            $this->add($tier);
        }

        if ($this->tiers === []) {
            throw new \InvalidArgumentException('At least one storage tier is required.');
        }

        $defaults = array_values(array_filter(
            $this->tiers,
            static fn(StorageTier $tier): bool => $tier->defaultWriteTier,
        ));

        if (count($defaults) > 1) {
            throw new \InvalidArgumentException('Only one storage tier can be marked as the default write tier.');
        }

        $this->defaultTierName = $defaults[0]->name ?? array_key_first($this->tiers);
    }

    public static function single(StorageBackend $backend, string $name = 'STANDARD'): self
    {
        return new self([
            new StorageTier($name, $backend, defaultWriteTier: true),
        ]);
    }

    public function defaultTier(): StorageTier
    {
        return $this->tier($this->defaultTierName);
    }

    public function defaultBackend(): StorageBackend
    {
        return $this->defaultTier()->backend;
    }

    public function tier(string $name): StorageTier
    {
        return $this->tiers[$name] ?? throw new \InvalidArgumentException("Unknown storage tier: {$name}");
    }

    public function has(string $name): bool
    {
        return isset($this->tiers[$name]);
    }

    /**
     * @return array<string, StorageTier>
     */
    public function all(): array
    {
        return $this->tiers;
    }

    private function add(StorageTier $tier): void
    {
        if (isset($this->tiers[$tier->name])) {
            throw new \InvalidArgumentException("Duplicate storage tier: {$tier->name}");
        }

        $this->tiers[$tier->name] = $tier;
    }
}
