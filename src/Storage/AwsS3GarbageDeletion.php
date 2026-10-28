<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Final provider-bound operation for one exact delete plus absence proof. */
final class AwsS3GarbageDeletion
{
    private bool $active = true;

    /**
     * @internal Instances are valid only with the backend's private authority.
     */
    public function __construct(
        private AwsS3StorageBackend $backend,
        private object $authority,
        private string $bucket,
        private string $tier,
        private string $path,
        private string $ownerId,
    ) {}

    public function matches(string $bucket, string $tier, string $path, string $ownerId): bool
    {
        return $this->bucket === $bucket
            && $this->tier === $tier
            && $this->path === $path
            && $this->ownerId === $ownerId;
    }

    public function execute(): void
    {
        if (! $this->active) {
            throw new \LogicException('AWS garbage deletion operation is no longer active.');
        }
        $this->active = false;
        $this->backend->executeGarbageDeletion(
            $this->authority,
            $this->bucket,
            $this->tier,
            $this->path,
            $this->ownerId,
        );
    }

    public function revoke(): void
    {
        $this->active = false;
    }
}
