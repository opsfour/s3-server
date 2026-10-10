<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** Keep optional observability failures outside runtime control flow. */
final class FailureIsolatingLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner) {}

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
        } catch (\Throwable) {
            // Logging must never strand workers or interrupt dependency drain.
        }
    }
}
