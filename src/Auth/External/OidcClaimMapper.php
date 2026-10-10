<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

/**
 * Maps OIDC/Keycloak claims to the S3 server identity model.
 */
final readonly class OidcClaimMapper
{
    public function __construct(
        private string $ownerClaim = 'sub',
        private string $displayNameClaim = 'preferred_username',
        private string $groupsClaim = 'groups',
        private ?string $policyNamesClaim = null,
        private ?string $allowedPrefixesClaim = null,
        private string $ownerPrefix = '',
    ) {}

    /**
     * @param array<string, mixed> $claims
     */
    public function map(array $claims, bool $scopeOwnerByIssuer = false): ExternalIdentity
    {
        $subject = $this->stringClaim($claims, 'sub');
        $issuer = $this->stringClaim($claims, 'iss', required: false);
        $ownerRaw = $this->stringClaim($claims, $this->ownerClaim);
        $displayName = $this->stringClaim($claims, $this->displayNameClaim, required: false);

        return new ExternalIdentity(
            subject: $subject,
            issuer: $issuer,
            ownerId: $scopeOwnerByIssuer
                ? self::issuerScopedOwnerId($issuer, $ownerRaw, $this->ownerPrefix)
                : $this->ownerPrefix . $ownerRaw,
            displayName: $displayName !== '' ? $displayName : $ownerRaw,
            claims: $claims,
            groups: $this->stringListClaim($claims, $this->groupsClaim),
            policyNames: $this->policyNamesClaim !== null ? $this->stringListClaim($claims, $this->policyNamesClaim) : [],
            allowedPrefixes: $this->allowedPrefixesClaim !== null ? $this->requiredPrefixClaim($claims, $this->allowedPrefixesClaim) : [],
        );
    }

    /** Deterministic owner ID for the canonical (issuer, configured owner claim) pair. */
    public static function issuerScopedOwnerId(string $issuer, string $ownerClaim, string $ownerPrefix = ''): string
    {
        if ($issuer === '' || $ownerClaim === '') {
            throw new \InvalidArgumentException('Issuer-scoped owner IDs require a non-empty issuer and owner claim.');
        }

        $ownerId = $ownerPrefix . 'oidc:' . hash('sha256', pack('N', strlen($issuer)) . $issuer . $ownerClaim);
        if (strlen($ownerId) > 255) {
            throw new \InvalidArgumentException('Issuer-scoped owner ID exceeds the 255-byte credential and metadata limit.');
        }

        return $ownerId;
    }

    public function assertIssuerScopedOwnerIdFits(): void
    {
        if (strlen($this->ownerPrefix) > 186) {
            throw new \InvalidArgumentException('Issuer-scoped owner ID exceeds the 255-byte credential and metadata limit.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function stringClaim(array $claims, string $name, bool $required = true): string
    {
        $value = $claims[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (!$required) {
            return '';
        }

        throw new \InvalidArgumentException("OIDC claim '{$name}' must be a non-empty string.");
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private function stringListClaim(array $claims, string $name): array
    {
        $value = $claims[$name] ?? null;
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException("OIDC claim '{$name}' must be a string or string list.");
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \InvalidArgumentException("OIDC claim '{$name}' must contain only non-empty strings.");
            }

            $items[] = $item;
        }

        return array_values(array_unique($items));
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private function requiredPrefixClaim(array $claims, string $name): array
    {
        $prefixes = $this->stringListClaim($claims, $name);
        if ($prefixes === []) {
            throw new \InvalidArgumentException("OIDC claim '{$name}' must contain at least one prefix.");
        }

        return $prefixes;
    }
}
