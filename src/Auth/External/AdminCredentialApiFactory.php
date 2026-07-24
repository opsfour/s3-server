<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Contracts\CredentialProvider;

final class AdminCredentialApiFactory
{
    private const int MAX_KEY_FILE_BYTES = 1_048_576;

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config, CredentialProvider $credentialProvider): ?AdminCredentialApiHandler
    {
        if (!self::bool($config['enabled'] ?? false)) {
            return null;
        }

        $adminToken = self::string($config['admin_token'] ?? null);
        if ($adminToken === null) {
            throw new \InvalidArgumentException('External IAM admin API requires "admin_token".');
        }

        $allowedIssuers = self::stringList($config['allowed_issuers'] ?? $config['issuer'] ?? []);
        if ($allowedIssuers === []) {
            throw new \InvalidArgumentException('External IAM admin API requires at least one expected JWT issuer.');
        }
        $allowedAudiences = self::stringList($config['allowed_audiences'] ?? $config['audience'] ?? []);
        if ($allowedAudiences === []) {
            throw new \InvalidArgumentException('External IAM admin API requires at least one expected JWT audience.');
        }

        $mapper = new OidcClaimMapper(
            ownerClaim: self::string($config['owner_claim'] ?? null) ?? 'sub',
            displayNameClaim: self::string($config['display_name_claim'] ?? null) ?? 'preferred_username',
            groupsClaim: self::string($config['groups_claim'] ?? null) ?? 'groups',
            policyNamesClaim: self::string($config['policy_names_claim'] ?? null),
            allowedPrefixesClaim: self::string($config['allowed_prefixes_claim'] ?? null),
            ownerPrefix: self::string($config['owner_prefix'] ?? null) ?? '',
        );

        $identityProvider = new JwtExternalIdentityProvider(
            mapper: $mapper,
            keys: self::loadKeys($config),
            allowedIssuers: $allowedIssuers,
            allowedAudiences: $allowedAudiences,
            clockSkewSeconds: self::positiveOrZeroInt($config['clock_skew_seconds'] ?? 60),
        );

        return new AdminCredentialApiHandler(
            identityProvider: $identityProvider,
            credentialIssuer: new ExternalCredentialIssuer(new CredentialManager($credentialProvider)),
            adminToken: $adminToken,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string|array<string, mixed>>
     */
    private static function loadKeys(array $config): array
    {
        if (isset($config['keys']) && is_array($config['keys']) && $config['keys'] !== []) {
            /** @var array<string, string|array<string, mixed>> $keys */
            $keys = $config['keys'];
            return $keys;
        }

        $jwksPath = self::string($config['jwks_path'] ?? null);
        if ($jwksPath !== null) {
            return self::loadJwksFile($jwksPath);
        }

        $publicKeyPath = self::string($config['public_key_path'] ?? null);
        if ($publicKeyPath !== null) {
            return ['default' => self::readKeyFile($publicKeyPath, 'public key')];
        }

        $publicKey = self::string($config['public_key'] ?? null);
        if ($publicKey !== null) {
            return ['default' => $publicKey];
        }

        throw new \InvalidArgumentException('External IAM admin API requires JWT verification keys.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadJwksFile(string $path): array
    {
        $raw = self::readKeyFile($path, 'JWKS');

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new \InvalidArgumentException('External IAM JWKS file must contain a "keys" array.');
        }

        $keys = [];
        foreach ($decoded['keys'] as $index => $key) {
            if (!is_array($key)) {
                throw new \InvalidArgumentException('External IAM JWKS keys must be JSON objects.');
            }

            $kid = $key['kid'] ?? (string) $index;
            if (!is_string($kid) || $kid === '') {
                throw new \InvalidArgumentException('External IAM JWKS key "kid" must be a non-empty string.');
            }

            /** @var array<string, mixed> $key */
            $keys[$kid] = $key;
        }

        return $keys;
    }

    private static function readKeyFile(string $path, string $description): string
    {
        $contents = @file_get_contents($path, false, null, 0, self::MAX_KEY_FILE_BYTES + 1);
        if ($contents === false || $contents === '') {
            throw new \InvalidArgumentException("External IAM {$description} file is not readable: {$path}");
        }
        if (strlen($contents) > self::MAX_KEY_FILE_BYTES) {
            throw new \InvalidArgumentException(
                "External IAM {$description} file exceeds " . self::MAX_KEY_FILE_BYTES . " bytes: {$path}",
            );
        }

        return $contents;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private static function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    private static function positiveOrZeroInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 60;
    }
}
