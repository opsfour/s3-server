<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Private staged bytes plus complete evidence, not yet visible to AWS. */
final class AwsS3ForegroundObjectStage
{
    private bool $released = false;

    public function __construct(
        private readonly object $authority,
        private readonly AwsS3ForegroundObjectReservation $reservation,
        private readonly LocalScratchStage $scratch,
        public readonly StorageWriteResult $result,
    ) {}

    public function reservation(object $authority): AwsS3ForegroundObjectReservation
    {
        $this->assertAuthority($authority);

        return $this->reservation;
    }

    public function scratch(object $authority): LocalScratchStage
    {
        $this->assertAuthority($authority);
        if ($this->released) {
            throw new \LogicException('AWS foreground stage was already released.');
        }

        return $this->scratch;
    }

    public function release(object $authority): ?LocalScratchStage
    {
        $this->assertAuthority($authority);
        if ($this->released) {
            return null;
        }
        $this->released = true;

        return $this->scratch;
    }

    private function assertAuthority(object $authority): void
    {
        if ($this->authority !== $authority) {
            throw new \InvalidArgumentException('AWS foreground stage belongs to another coordinator.');
        }
    }
}
