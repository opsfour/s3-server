<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/**
 * Represents an S3 credential pair with owner identity.
 *
 * Uses PHP 8.4 asymmetric visibility: properties are publicly readable
 * but can only be set within the constructor (private(set)).
 */
final class Credential
{
    /**
     * @param  string  $accessKeyId  The access key identifier (e.g., "AKIAIOSFODNN7EXAMPLE").
     * @param  string  $secretAccessKey  The secret key for signing (e.g., "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY").
     * @param  string  $ownerId  The tenant/owner identifier scoping all operations.
     * @param  string  $displayName  Human-readable display name for the credential owner.
     * @param  bool  $isActive  Whether this credential is active and can be used for authentication.
     * @param  string|null  $sessionToken  Optional STS-style session token for temporary credentials.
     * @param  \DateTimeImmutable|null  $expiresAt  Optional UTC expiration timestamp for temporary credentials.
     * @param  list<string>  $policyNames  Optional external IAM policy names attached to this credential.
     * @param  list<string>  $allowedPrefixes  Optional key prefixes allowed for this credential.
     */
    public function __construct(
        public private(set) string $accessKeyId,
        public private(set) string $secretAccessKey,
        public private(set) string $ownerId,
        public private(set) string $displayName = '',
        public private(set) bool $isActive = true,
        public private(set) ?string $sessionToken = null,
        public private(set) ?\DateTimeImmutable $expiresAt = null,
        public private(set) array $policyNames = [],
        public private(set) array $allowedPrefixes = [],
    ) {
        // AuthMiddleware uses an all-empty credential as its anonymous sentinel.
        if ($this->accessKeyId === '' && $this->secretAccessKey === '' && $this->ownerId === '') {
            return;
        }

        if ($this->accessKeyId === ''
            || strlen($this->accessKeyId) > 255
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $this->accessKeyId)) {
            throw new \InvalidArgumentException(
                'Credential access key ID must contain 1 to 255 ASCII letters, digits, underscores, or hyphens.',
            );
        }

        self::validateRequiredString($this->secretAccessKey, 4096, 'secret access key');
        self::validateRequiredString($this->ownerId, 255, 'owner ID');
        self::validateOptionalString($this->displayName, 255, 'display name');

        if ($this->sessionToken !== null) {
            self::validateRequiredString($this->sessionToken, 8192, 'session token');
        }

        self::validateStringList($this->policyNames, 128, 255, 'policy names');
        self::validateStringList($this->allowedPrefixes, 128, 1024, 'allowed prefixes');
    }

    private static function validateRequiredString(string $value, int $maxBytes, string $label): void
    {
        if ($value === '' || strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException(
                sprintf('Credential %s must contain 1 to %d bytes without control characters.', $label, $maxBytes),
            );
        }
    }

    private static function validateOptionalString(string $value, int $maxBytes, string $label): void
    {
        if (strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException(
                sprintf('Credential %s must contain at most %d bytes without control characters.', $label, $maxBytes),
            );
        }
    }

    /**
     * @param list<string> $values
     */
    private static function validateStringList(array $values, int $maxItems, int $maxItemBytes, string $label): void
    {
        if (!array_is_list($values) || count($values) > $maxItems) {
            throw new \InvalidArgumentException(
                sprintf('Credential %s must be a list with at most %d items.', $label, $maxItems),
            );
        }

        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > $maxItemBytes) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Credential %s must contain only strings between 1 and %d bytes.',
                        $label,
                        $maxItemBytes,
                    ),
                );
            }
        }
    }
}
