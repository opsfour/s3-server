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
     */
    public function __construct(
        private OidcClaimMapper $mapper,
        private array $keys,
        private array $allowedIssuers = [],
        private array $allowedAudiences = [],
        private int $clockSkewSeconds = 60,
    ) {
        if ($this->keys === []) {
            throw new \InvalidArgumentException('At least one JWT verification key is required.');
        }

        if ($this->clockSkewSeconds < 0) {
            throw new \InvalidArgumentException('Clock skew must not be negative.');
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

        $key = $this->resolveKey($header);
        $verified = openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new ExternalIdentityAuthenticationException('JWT signature is invalid.');
        }

        $this->validateClaims($claims);

        return $this->mapper->map($claims);
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
    private function resolveKey(array $header): string
    {
        $kid = $header['kid'] ?? null;
        if (is_string($kid) && isset($this->keys[$kid])) {
            return $this->keyToPem($this->keys[$kid]);
        }

        if (count($this->keys) === 1) {
            return $this->keyToPem(reset($this->keys));
        }

        throw new ExternalIdentityAuthenticationException('JWT key ID is unknown.');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims): void
    {
        $now = time();

        if (isset($claims['exp']) && (!is_int($claims['exp']) || $claims['exp'] < $now - $this->clockSkewSeconds)) {
            throw new ExternalIdentityAuthenticationException('JWT has expired.');
        }

        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + $this->clockSkewSeconds)) {
            throw new ExternalIdentityAuthenticationException('JWT is not valid yet.');
        }

        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + $this->clockSkewSeconds)) {
            throw new ExternalIdentityAuthenticationException('JWT issued-at timestamp is in the future.');
        }

        if ($this->allowedIssuers !== []) {
            $issuer = $claims['iss'] ?? null;
            if (!is_string($issuer) || !in_array($issuer, $this->allowedIssuers, true)) {
                throw new ExternalIdentityAuthenticationException('JWT issuer is not allowed.');
            }
        }

        if ($this->allowedAudiences !== [] && !$this->audienceMatches($claims['aud'] ?? null)) {
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
            $this->derInteger($this->base64UrlDecode($modulus)) .
            $this->derInteger($this->base64UrlDecode($exponent)),
        );

        $subjectPublicKeyInfo = $this->derSequence(
            $this->derSequence(
                $this->derObjectIdentifier(hex2bin('2a864886f70d010101') ?: '') .
                $this->derNull(),
            ) .
            $this->derBitString($rsaPublicKey),
        );

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
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
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}
