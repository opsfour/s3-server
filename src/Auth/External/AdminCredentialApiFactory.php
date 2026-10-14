<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\CredentialProvider;

final class AdminCredentialApiFactory
{
    private const int MAX_KEY_FILE_BYTES = 1_048_576;

    private const int MAX_CLOCK_SKEW_SECONDS = 3_600;

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config, CredentialProvider $credentialProvider): ?AdminCredentialApiHandler
    {
        if (! array_key_exists('enabled', $config)) {
            return null;
        }

        if (!self::bool($config['enabled'])) {
            return null;
        }

        if (!$credentialProvider instanceof AtomicCredentialCreator
            || !$credentialProvider instanceof AtomicCredentialDeleter
            || ($credentialProvider instanceof ChainCredentialProvider
                && !$credentialProvider->supportsAtomicCredentialLifecycle())) {
            throw new \InvalidArgumentException(
                'External IAM requires one credential store that supports atomic creation and revocation.',
            );
        }

        $adminToken = self::string($config['admin_token'] ?? null);
        if ($adminToken === null) {
            throw new \InvalidArgumentException('External IAM admin API requires "admin_token".');
        }

        $allowedIssuers = self::configuredStringList($config, 'allowed_issuers', 'issuer');
        if ($allowedIssuers === []) {
            throw new \InvalidArgumentException('External IAM admin API requires at least one expected JWT issuer.');
        }
        $allowedAudiences = self::configuredStringList($config, 'allowed_audiences', 'audience');
        if ($allowedAudiences === []) {
            throw new \InvalidArgumentException('External IAM admin API requires at least one expected JWT audience.');
        }

        $mapper = new OidcClaimMapper(
            ownerClaim: self::configuredString($config, 'owner_claim', 'sub'),
            displayNameClaim: self::configuredString($config, 'display_name_claim', 'preferred_username'),
            groupsClaim: self::configuredString($config, 'groups_claim', 'groups'),
            policyNamesClaim: self::configuredOptionalString($config, 'policy_names_claim'),
            allowedPrefixesClaim: self::configuredOptionalString($config, 'allowed_prefixes_claim'),
            ownerPrefix: self::configuredString($config, 'owner_prefix', '', allowEmpty: true),
        );

        $multipleIssuers = count($allowedIssuers) > 1;
        $keysByIssuer = $multipleIssuers
            ? self::issuerKeySets($config['keys_by_issuer'] ?? null, $allowedIssuers)
            : [];
        if ($multipleIssuers) {
            self::assertNoSingleIssuerKeySource($config);
        } elseif (array_key_exists('keys_by_issuer', $config) && $config['keys_by_issuer'] !== null) {
            throw new \InvalidArgumentException('Single-issuer JWT verification cannot use keys_by_issuer.');
        }

        $identityProvider = new JwtExternalIdentityProvider(
            mapper: $mapper,
            keys: $multipleIssuers ? [] : self::loadKeys($config),
            allowedIssuers: $allowedIssuers,
            allowedAudiences: $allowedAudiences,
            clockSkewSeconds: self::positiveOrZeroInt($config['clock_skew_seconds'] ?? 60),
            keysByIssuer: $keysByIssuer,
        );

        return new AdminCredentialApiHandler(
            identityProvider: $identityProvider,
            credentialIssuer: new ExternalCredentialIssuer(new CredentialManager($credentialProvider)),
            adminToken: $adminToken,
        );
    }

    /**
     * @param list<string> $allowedIssuers
     * @return array<string, array<string, string|array<string, mixed>>>
     */
    private static function issuerKeySets(mixed $value, array $allowedIssuers): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Multi-issuer JWT verification requires keys_by_issuer.');
        }
        $keySets = [];
        foreach ($allowedIssuers as $issuer) {
            $keys = $value[$issuer] ?? null;
            if (! is_array($keys) || $keys === []) {
                throw new \InvalidArgumentException("Multi-issuer JWT verification requires keys for issuer {$issuer}.");
            }
            foreach ($keys as $kid => $key) {
                if (! is_string($kid) || $kid === '' || (! is_string($key) && ! is_array($key))) {
                    throw new \InvalidArgumentException("Invalid JWT key set for issuer {$issuer}.");
                }
            }
            $keySets[$issuer] = $keys;
        }
        if (count($keySets) !== count($value)) {
            throw new \InvalidArgumentException('JWT key sets include an issuer that is not allowed.');
        }

        return $keySets;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string|array<string, mixed>>
     */
    private static function loadKeys(array $config): array
    {
        $sources = [];
        if (array_key_exists('keys', $config) && $config['keys'] !== null) {
            if (! is_array($config['keys']) || $config['keys'] === []) {
                throw new \InvalidArgumentException('External IAM keys must be a non-empty key map.');
            }
            /** @var array<string, string|array<string, mixed>> $keys */
            $keys = $config['keys'];
            $sources['keys'] = $keys;
        }

        $jwksPath = self::keySourceString($config, 'jwks_path');
        if ($jwksPath !== null) {
            $sources['jwks_path'] = self::loadJwksFile($jwksPath);
        }

        $publicKeyPath = self::keySourceString($config, 'public_key_path');
        if ($publicKeyPath !== null) {
            $sources['public_key_path'] = ['default' => self::readKeyFile($publicKeyPath, 'public key')];
        }

        $publicKey = self::keySourceString($config, 'public_key');
        if ($publicKey !== null) {
            $sources['public_key'] = ['default' => $publicKey];
        }

        if (count($sources) > 1) {
            throw new \InvalidArgumentException('External IAM must configure exactly one JWT verification key source.');
        }
        if ($sources !== []) {
            return array_values($sources)[0];
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
            if (array_key_exists($kid, $keys)) {
                throw new \InvalidArgumentException('External IAM JWKS key IDs must be unique.');
            }

            /** @var array<string, mixed> $key */
            $keys[$kid] = $key;
        }

        return $keys;
    }

    /** @param array<string, mixed> $config */
    private static function assertNoSingleIssuerKeySource(array $config): void
    {
        foreach (['keys', 'jwks_path', 'public_key_path', 'public_key'] as $name) {
            if (array_key_exists($name, $config) && $config[$name] !== null) {
                throw new \InvalidArgumentException(
                    'Multi-issuer JWT verification cannot configure a single-issuer key source.',
                );
            }
        }
    }

    /** @param array<string, mixed> $config */
    private static function keySourceString(array $config, string $name): ?string
    {
        if (! array_key_exists($name, $config) || $config[$name] === null) {
            return null;
        }
        if (! is_string($config[$name]) || trim($config[$name]) === '') {
            throw new \InvalidArgumentException("External IAM {$name} must be a non-empty string or null.");
        }

        return trim($config[$name]);
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
            return match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => throw new \InvalidArgumentException(
                    'External IAM enabled must be a boolean or the string "true" or "false".',
                ),
            };
        }

        throw new \InvalidArgumentException(
            'External IAM enabled must be a boolean or the string "true" or "false".',
        );
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
     * @param array<string, mixed> $config
     */
    private static function configuredString(
        array $config,
        string $name,
        string $default,
        bool $allowEmpty = false,
    ): string {
        if (! array_key_exists($name, $config)) {
            return $default;
        }

        if (! is_string($config[$name])) {
            throw new \InvalidArgumentException("External IAM {$name} must be a string.");
        }

        $value = trim($config[$name]);
        if ($value === '' && ! $allowEmpty) {
            throw new \InvalidArgumentException("External IAM {$name} must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configuredOptionalString(array $config, string $name): ?string
    {
        if (! array_key_exists($name, $config) || $config[$name] === null) {
            return null;
        }
        if (! is_string($config[$name])) {
            throw new \InvalidArgumentException("External IAM {$name} must be a string or null.");
        }

        $value = trim($config[$name]);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function configuredStringList(array $config, string $listName, string $singleName): array
    {
        if (array_key_exists($listName, $config)) {
            return self::stringList($config[$listName], $listName);
        }
        if (array_key_exists($singleName, $config)) {
            return self::stringList($config[$singleName], $singleName);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $name): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException("External IAM {$name} must be a string or a list of strings.");
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new \InvalidArgumentException(
                    "External IAM {$name} must contain only non-empty strings.",
                );
            }
            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    private static function positiveOrZeroInt(mixed $value): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new \InvalidArgumentException(
                'External IAM clock_skew_seconds must be an integer between 0 and '
                . self::MAX_CLOCK_SKEW_SECONDS . '.',
            );
        }

        $seconds = filter_var($value, FILTER_VALIDATE_INT);
        if ($seconds === false || $seconds < 0 || $seconds > self::MAX_CLOCK_SKEW_SECONDS) {
            throw new \InvalidArgumentException(
                'External IAM clock_skew_seconds must be an integer between 0 and '
                . self::MAX_CLOCK_SKEW_SECONDS . '.',
            );
        }

        return $seconds;
    }
}
