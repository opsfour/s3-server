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
    public function map(array $claims): ExternalIdentity
    {
        $subject = $this->stringClaim($claims, 'sub');
        $issuer = $this->stringClaim($claims, 'iss', required: false);
        $ownerRaw = $this->stringClaim($claims, $this->ownerClaim);
        $displayName = $this->stringClaim($claims, $this->displayNameClaim, required: false);

        return new ExternalIdentity(
            subject: $subject,
            issuer: $issuer,
            ownerId: $this->ownerPrefix . $ownerRaw,
            displayName: $displayName !== '' ? $displayName : $ownerRaw,
            claims: $claims,
            groups: $this->stringListClaim($claims, $this->groupsClaim),
            policyNames: $this->policyNamesClaim !== null ? $this->stringListClaim($claims, $this->policyNamesClaim) : [],
            allowedPrefixes: $this->allowedPrefixesClaim !== null ? $this->stringListClaim($claims, $this->allowedPrefixesClaim) : [],
        );
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
}
