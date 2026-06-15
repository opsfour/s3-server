<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Exception\AccessDeniedException;

final class SessionCredentialValidator
{
    public static function validate(Credential $credential, ?string $sessionToken): void
    {
        if ($credential->expiresAt !== null && $credential->expiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new AccessDeniedException('The provided token has expired.');
        }

        if ($credential->sessionToken === null) {
            return;
        }

        if ($sessionToken === null || $sessionToken === '') {
            throw new AccessDeniedException('Temporary credentials require x-amz-security-token.');
        }

        if (!hash_equals($credential->sessionToken, $sessionToken)) {
            throw new AccessDeniedException('The provided token is invalid.');
        }
    }
}
