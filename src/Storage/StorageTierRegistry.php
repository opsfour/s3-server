<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

/**
 * Registry of physical storage tiers used by tier transition and restore jobs.
 */
final class StorageTierRegistry
{
    /** @var array<string, StorageTier> Tier name prefixed to prevent PHP from casting numeric string keys. */
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

        $this->defaultTierName = $defaults[0]->name ?? array_values($this->tiers)[0]->name;
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
        return $this->tiers[self::key($name)] ?? throw new \InvalidArgumentException("Unknown storage tier: {$name}");
    }

    public function has(string $name): bool
    {
        return isset($this->tiers[self::key($name)]);
    }

    /**
     * @return list<StorageTier>
     */
    public function all(): array
    {
        return array_values($this->tiers);
    }

    public function withObservability(MetricsCollector $metrics): self
    {
        $multipleTiers = count($this->tiers) > 1;
        $tiers = [];
        foreach ($this->tiers as $tier) {
            $backend = $tier->backend;
            if (! $backend instanceof ObservedStorageBackend) {
                $driver = self::metricsDriver($backend);
                if ($multipleTiers) {
                    $driver .= ':' . $tier->name;
                }
                $backend = new ObservedStorageBackend($backend, $metrics, $driver);
            }

            $tiers[] = new StorageTier(
                name: $tier->name,
                backend: $backend,
                restoreRequired: $tier->restoreRequired,
                defaultWriteTier: $tier->defaultWriteTier,
            );
        }

        return new self($tiers);
    }

    private static function metricsDriver(StorageBackend $backend): string
    {
        return match (true) {
            $backend instanceof FilesystemBackend => 'filesystem',
            $backend instanceof ParallelFlysystemBackend,
            $backend instanceof FlysystemBackend => 'flysystem',
            $backend instanceof InMemoryBackend => 'memory',
            default => strtolower((new \ReflectionClass($backend))->getShortName()),
        };
    }

    private function add(StorageTier $tier): void
    {
        if (strlen($tier->name) > 64) {
            throw new \InvalidArgumentException('Storage tier names must be at most 64 bytes.');
        }
        $key = self::key($tier->name);
        if (isset($this->tiers[$key])) {
            throw new \InvalidArgumentException("Duplicate storage tier: {$tier->name}");
        }

        $this->tiers[$key] = $tier;
    }

    private static function key(string $name): string
    {
        return "tier\0{$name}";
    }
}
