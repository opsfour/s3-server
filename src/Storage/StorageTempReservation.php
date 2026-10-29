<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** @internal */
final class StorageTempReservation
{
    /** @var array{device:int,inode:int,owner:int}|null */
    private ?array $identity;

    /**
     * @param resource $handle
     * @param array{device:int,inode:int,owner:int}|null $identity
     */
    public function __construct(
        public readonly string $leasePath,
        public string $tempPath,
        public string $purpose,
        public readonly string $nonce,
        private $handle,
        ?array $identity = null,
    ) {
        $this->identity = $identity;
    }

    /** @internal The coordinator atomically moved this live reservation. */
    /** @param array{device:int,inode:int,owner:int} $identity */
    public function retarget(string $tempPath, string $purpose, array $identity): void
    {
        if (! $this->isOpen()) {
            throw new \RuntimeException('Storage temporary reservation is closed.');
        }
        $this->tempPath = $tempPath;
        $this->purpose = $purpose;
        $this->identity = $identity;
    }

    /** @return array{device:int,inode:int,owner:int} */
    public function identity(): array
    {
        return $this->identity
            ?? throw new \LogicException('Storage temporary reservation has no inode identity authority.');
    }

    public function isOpen(): bool
    {
        return is_resource($this->handle);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
