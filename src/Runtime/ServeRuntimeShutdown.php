<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Psr\Log\LoggerInterface;

/** Convert a failed runtime shutdown into a safe serve-command diagnostic. */
final class ServeRuntimeShutdown
{
    public static function stop(S3ServerRuntime $runtime, LoggerInterface $logger): ?string
    {
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $runtime->stop();

                return null;
            } catch (\Throwable $error) {
                if ($attempt < 2) {
                    continue;
                }

                try {
                    $logger->error('S3 server shutdown failed.', ['exception' => $error::class]);
                } catch (\Throwable) {
                    // A logger failure cannot replace the controlled CLI result.
                }

                return ServeFailureMessage::format($error, 'shutdown');
            }
        }

        return null;
    }
}
