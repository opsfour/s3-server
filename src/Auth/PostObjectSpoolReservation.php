<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Storage\StorageTempReservation;

/** @internal */
final class PostObjectSpoolReservation
{
    private int $bytes = 0;

    public string $leasePath = '';

    private ?StorageTempReservation $storageReservation = null;

    private bool $open = true;

    private ?int $budgetBytes = null;

    public function __construct(public readonly string $tempPath) {}

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function addBytes(int $bytes): void
    {
        $this->bytes += $bytes;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function attach(StorageTempReservation $reservation): void
    {
        if ($this->storageReservation !== null || $reservation->tempPath !== $this->tempPath) {
            throw new \LogicException('Request temporary reservation cannot be rebound.');
        }
        $this->storageReservation = $reservation;
        $this->leasePath = $reservation->leasePath;
    }

    public function storageReservation(): ?StorageTempReservation
    {
        return $this->storageReservation;
    }

    public function bindBudget(int $budgetBytes): void
    {
        if ($this->budgetBytes !== null && $this->budgetBytes !== $budgetBytes) {
            throw new \LogicException('Request temporary reservation budget changed.');
        }
        $this->budgetBytes = $budgetBytes;
    }

    public function budgetBytes(): ?int
    {
        return $this->budgetBytes;
    }

    public function close(): void
    {
        $this->storageReservation?->close();
        $this->open = false;
    }
}
