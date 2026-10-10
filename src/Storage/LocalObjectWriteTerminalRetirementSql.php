<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** SQL capability for retiring a terminal one-shot worker that never linked. */
interface LocalObjectWriteTerminalRetirementSql
{
    public function retireTerminalPreLink(string $attemptId, string $dispatchId): bool;
}
