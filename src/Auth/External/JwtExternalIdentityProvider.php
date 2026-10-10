<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

/**
 * Validates RS256 JWT access tokens from OIDC providers such as Keycloak.
 *
 * Keys can be configured as PEM strings or RSA JWKs containing `n` and `e`.
 */
final readonly class JwtExternalIdentityProvider implements ExternalIdentityProvider
{
    /**
     * @param array<string, string|array<string, mixed>> $keys
     * @param list<string> $allowedIssuers
     * @param list<string> $allowedAudiences
     * @param array<string, array<string, string|array<string, mixed>>> $keysByIssuer
     */
    public function __construct(
        private OidcClaimMapper $mapper,
        private array $keys,
        private array $allowedIssuers = [],
        private array $allowedAudiences = [],
        private int $clockSkewSeconds = 60,
        private array $keysByIssuer = [],
    ) {
        if ($this->allowedIssuers === [] || $this->allowedAudiences === []) {
            throw new \InvalidArgumentException('JWT verification requires expected issuer and audience values.');
        }

        foreach (array_merge($this->allowedIssuers, $this->allowedAudiences) as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('JWT verification requires non-empty issuer and audience values.');
            }
        }

        if ($this->multipleIssuers()) {
            $this->mapper->assertIssuerScopedOwnerIdFits();
            if ($this->keys !== []) {
                throw new \InvalidArgumentException('Multi-issuer JWT verification cannot use a global key pool.');
            }
            foreach ($this->allowedIssuers as $issuer) {
                if (! isset($this->keysByIssuer[$issuer]) || $this->keysByIssuer[$issuer] === []) {
                    throw new \InvalidArgumentException("JWT verification keys are required for issuer {$issuer}.");
                }
            }
            foreach ($this->keysByIssuer as $issuer => $issuerKeys) {
                if (! in_array($issuer, $this->allowedIssuers, true) || ! is_array($issuerKeys) || $issuerKeys === []) {
                    throw new \InvalidArgumentException('JWT issuer key sets must match the allowed issuers.');
                }
            }
            $fingerprintOwners = [];
            foreach ($this->keysByIssuer as $issuer => $issuerKeys) {
                foreach ($this->keyFingerprints($issuerKeys, 'JWT issuer key material') as $fingerprint) {
                    if (isset($fingerprintOwners[$fingerprint]) && $fingerprintOwners[$fingerprint] !== $issuer) {
                        throw new \InvalidArgumentException('JWT verification key is shared by multiple issuers.');
                    }
                    $fingerprintOwners[$fingerprint] = $issuer;
                }
            }
        } elseif ($this->keys === []) {
            throw new \InvalidArgumentException('At least one JWT verification key is required.');
        } else {
            $this->keyFingerprints($this->keys, 'JWT verification key material');
        }

        if ($this->clockSkewSeconds < 0 || $this->clockSkewSeconds > 3_600) {
            throw new \InvalidArgumentException('Clock skew must be between 0 and 3600 seconds.');
        }
    }

    /**
     * @param array<string, string|array<string, mixed>> $keys
     * @return list<string>
     */
    private function keyFingerprints(array $keys, string $label): array
    {
        $fingerprints = [];
        foreach ($keys as $keyMaterial) {
            if (! is_string($keyMaterial) && ! is_array($keyMaterial)) {
                throw new \InvalidArgumentException("{$label} is invalid.");
            }
            if (is_array($keyMaterial)) {
                $this->assertJwkUsage($keyMaterial, $label);
            }

            try {
                $pem = $this->keyToPem($keyMaterial);
            } catch (ExternalIdentityAuthenticationException $exception) {
                throw new \InvalidArgumentException("{$label} is not a valid public key.", 0, $exception);
            }

            $publicKey = @openssl_pkey_get_public($pem);
            $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
            if (
                ! is_array($details)
                || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
                || ! is_int($details['bits'] ?? null)
                || $details['bits'] < 2_048
                || ! is_string($details['key'] ?? null)
            ) {
                throw new \InvalidArgumentException("{$label} must be a valid RSA public key with at least 2048 bits.");
            }
            $fingerprints[] = hash('sha256', $details['key']);
        }

        return $fingerprints;
    }

    /** @param array<string, mixed> $key */
    private function assertJwkUsage(array $key, string $label): void
    {
        if (array_key_exists('use', $key) && $key['use'] !== 'sig') {
            throw new \InvalidArgumentException("{$label} JWK use must be sig.");
        }
        if (array_key_exists('alg', $key) && $key['alg'] !== 'RS256') {
            throw new \InvalidArgumentException("{$label} JWK alg must be RS256.");
        }
        if (array_key_exists('key_ops', $key)) {
            if (! is_array($key['key_ops']) || ! array_is_list($key['key_ops'])) {
                throw new \InvalidArgumentException("{$label} JWK key_ops must be a list containing verify.");
            }
            foreach ($key['key_ops'] as $operation) {
                if (! is_string($operation)) {
                    throw new \InvalidArgumentException("{$label} JWK key_ops must be a list containing verify.");
                }
            }
            if (! in_array('verify', $key['key_ops'], true)) {
                throw new \InvalidArgumentException("{$label} JWK key_ops must contain verify.");
            }
        }
    }

    public function authenticate(string $bearerToken): ExternalIdentity
    {
        $token = $this->normalizeBearerToken($bearerToken);
        [$header, $claims, $signingInput, $signature] = $this->parseToken($token);

        $algorithm = $header['alg'] ?? null;
        if ($algorithm !== 'RS256') {
            throw new ExternalIdentityAuthenticationException('JWT algorithm is not supported.');
        }

        $key = $this->resolveKey($header, $claims['iss'] ?? null);
        $verified = openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new ExternalIdentityAuthenticationException('JWT signature is invalid.');
        }

        $this->validateClaims($claims);

        return $this->mapper->map($claims, scopeOwnerByIssuer: $this->multipleIssuers());
    }

    private function normalizeBearerToken(string $bearerToken): string
    {
        $token = trim($bearerToken);
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            throw new ExternalIdentityAuthenticationException('Bearer token must not be empty.');
        }

        return $token;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function parseToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ExternalIdentityAuthenticationException('JWT must contain header, payload, and signature.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->jsonDecode($this->base64UrlDecode($encodedHeader), 'JWT header');
        $claims = $this->jsonDecode($this->base64UrlDecode($encodedPayload), 'JWT payload');
        $signature = $this->base64UrlDecode($encodedSignature);

        return [$header, $claims, "{$encodedHeader}.{$encodedPayload}", $signature];
    }

    /**
     * @param array<string, mixed> $header
     */
    private function resolveKey(array $header, mixed $claimedIssuer): string
    {
        $keys = $this->keys;
        if ($this->multipleIssuers()) {
            if (! is_string($claimedIssuer) || ! in_array($claimedIssuer, $this->allowedIssuers, true)) {
                throw new ExternalIdentityAuthenticationException('JWT issuer is not allowed.');
            }
            $keys = $this->keysByIssuer[$claimedIssuer];
        }
        $kid = $header['kid'] ?? null;
        if (is_string($kid) && isset($keys[$kid])) {
            return $this->keyToPem($keys[$kid]);
        }

        if (count($keys) === 1) {
            return $this->keyToPem(reset($keys));
        }

        throw new ExternalIdentityAuthenticationException('JWT key ID is unknown.');
    }

    private function multipleIssuers(): bool
    {
        return count(array_unique($this->allowedIssuers)) > 1;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims): void
    {
        $now = time();

        $expiration = $claims['exp'] ?? null;
        if (! is_int($expiration)) {
            throw new ExternalIdentityAuthenticationException('JWT expiration is required.');
        }
        if ($expiration < $now - $this->clockSkewSeconds) {
            throw new ExternalIdentityAuthenticationException('JWT has expired.');
        }

        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + $this->clockSkewSeconds)) {
            throw new ExternalIdentityAuthenticationException('JWT is not valid yet.');
        }

        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + $this->clockSkewSeconds)) {
            throw new ExternalIdentityAuthenticationException('JWT issued-at timestamp is in the future.');
        }

        $issuer = $claims['iss'] ?? null;
        if (!is_string($issuer) || !in_array($issuer, $this->allowedIssuers, true)) {
            throw new ExternalIdentityAuthenticationException('JWT issuer is not allowed.');
        }

        if (!$this->audienceMatches($claims['aud'] ?? null)) {
            throw new ExternalIdentityAuthenticationException('JWT audience is not allowed.');
        }
    }

    private function audienceMatches(mixed $audience): bool
    {
        if (is_string($audience)) {
            return in_array($audience, $this->allowedAudiences, true);
        }

        if (!is_array($audience)) {
            return false;
        }

        foreach ($audience as $item) {
            if (is_string($item) && in_array($item, $this->allowedAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonDecode(string $json, string $label): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new ExternalIdentityAuthenticationException("{$label} must be a JSON object.");
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new ExternalIdentityAuthenticationException('JWT contains invalid base64url data.');
        }

        return $decoded;
    }

    /**
     * @param string|array<string, mixed> $key
     */
    private function keyToPem(string|array $key): string
    {
        if (is_string($key)) {
            return $key;
        }

        if (isset($key['pem']) && is_string($key['pem'])) {
            return $key['pem'];
        }

        if (isset($key['public_key']) && is_string($key['public_key'])) {
            return $key['public_key'];
        }

        if (($key['kty'] ?? null) === 'RSA' && isset($key['n'], $key['e']) && is_string($key['n']) && is_string($key['e'])) {
            return $this->rsaJwkToPem($key['n'], $key['e']);
        }

        throw new ExternalIdentityAuthenticationException('JWT verification key is invalid.');
    }

    private function rsaJwkToPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = $this->derSequence(
            $this->derInteger($this->base64UrlDecode($modulus))
            . $this->derInteger($this->base64UrlDecode($exponent)),
        );

        $subjectPublicKeyInfo = $this->derSequence(
            $this->derSequence(
                $this->derObjectIdentifier(hex2bin('2a864886f70d010101') ?: '')
                . $this->derNull(),
            )
            . $this->derBitString($rsaPublicKey),
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $value): string
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derObjectIdentifier(string $value): string
    {
        return "\x06" . $this->derLength(strlen($value)) . $value;
    }

    private function derNull(): string
    {
        return "\x05\x00";
    }

    private function derBitString(string $value): string
    {
        return "\x03" . $this->derLength(strlen($value) + 1) . "\x00" . $value;
    }

    private function derLength(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('DER length must not be negative.');
        }

        if ($length < 128) {
            return pack('C', $length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = pack('C', $length & 0xff) . $encoded;
            $length >>= 8;
        }

        return pack('C', 0x80 | strlen($encoded)) . $encoded;
    }
}
