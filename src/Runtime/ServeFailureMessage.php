<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Redacts dependency diagnostics before writing serve-command output. */
final class ServeFailureMessage
{
    public static function format(\Throwable $error, string $phase): string
    {
        $message = $error->getMessage();

        if ($error instanceof AwsS3ProviderStartupException) {
            return $error->safeMessage();
        }

        // These exact package-owned diagnostics contain no dependency input.
        if ($message === 'PostgreSQL API servers require the pecl-pq PHP extension.'
            || $message === '--access-key and --secret-key require the memory credentials driver.') {
            return $message;
        }

        if ($error instanceof \InvalidArgumentException
            && preg_match('/^Port must be between 1 and 65535, got -?[0-9]+\.$/', $message) === 1) {
            return $message;
        }

        return sprintf('S3 server %s failed (%s).', $phase, $error::class);
    }
}
