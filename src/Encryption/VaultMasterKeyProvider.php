<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

/**
 * Reads master keys from HashiCorp Vault (KV v2 engine).
 *
 * Multi-key format: Vault secret contains a "keys" field with JSON map
 * {keyId: base64Key} and an "activeKeyId" field.
 *
 * Legacy single-key format: Vault secret contains a single base64 key in the configured field.
 *
 * Environment variables:
 * - S3_VAULT_ADDR: Vault server address (e.g., 'https://vault:8200')
 * - S3_VAULT_TOKEN: Vault authentication token
 * - S3_VAULT_PATH: Secret path (default: 'secret/data/s3-server/master-key')
 * - S3_VAULT_KEY_FIELD: Field name within the secret data (default: 'key')
 */
final class VaultMasterKeyProvider implements MasterKeyProvider
{
    /** @var array<string, string> Key ID → raw 32-byte key */
    private readonly array $keys;

    private readonly string $activeKeyId;

    public function __construct(
        ?string $vaultAddr = null,
        ?string $vaultToken = null,
        ?string $vaultPath = null,
        ?string $keyField = null,
        ?HttpClient $httpClient = null,
    ) {
        $addr = $vaultAddr ?? (getenv('S3_VAULT_ADDR') ?: false);
        if ($addr === false || $addr === '') {
            throw new \RuntimeException('S3_VAULT_ADDR environment variable or vaultAddr parameter is required.');
        }

        if (!str_starts_with($addr, 'https://')) {
            throw new \RuntimeException('Vault address must use HTTPS to protect the master key in transit.');
        }

        $token = $vaultToken ?? (getenv('S3_VAULT_TOKEN') ?: false);
        if ($token === false || $token === '') {
            throw new \RuntimeException('S3_VAULT_TOKEN environment variable or vaultToken parameter is required.');
        }

        $path = $vaultPath ?? (getenv('S3_VAULT_PATH') ?: 'secret/data/s3-server/master-key');
        $field = $keyField ?? (getenv('S3_VAULT_KEY_FIELD') ?: 'key');

        // Never follow redirects with the Vault token attached. In particular,
        // HTTPS must not redirect the secret header to plaintext HTTP.
        $client = $httpClient ?? (new HttpClientBuilder())
            ->followRedirects(0)
            ->build();

        $url = rtrim($addr, '/') . '/v1/' . ltrim($path, '/');
        $request = new Request($url, 'GET');
        $request->setHeader('X-Vault-Token', $token);

        try {
            $response = $client->request($request);
            $body = $response->getBody()->buffer(limit: 1_048_576);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to read master keys from Vault path '{$path}'.", 0, $e);
        }
        $statusCode = $response->getStatus();

        if ($statusCode !== 200) {
            throw new \RuntimeException("Vault returned HTTP {$statusCode} for path '{$path}'.");
        }

        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Vault returned invalid JSON for path '{$path}'.", 0, $e);
        }
        $secretData = is_array($data) ? ($data['data']['data'] ?? null) : null;
        if (! is_array($secretData)) {
            throw new \RuntimeException("Vault response for path '{$path}' does not contain KV v2 secret data.");
        }

        // Try multi-key format first: "keys" field with JSON map.
        $keysField = $secretData['keys'] ?? null;
        if (is_string($keysField)) {
            $keysField = json_decode($keysField, true, 4, JSON_THROW_ON_ERROR);
        }

        if (is_array($keysField) && $keysField !== []) {
            $keys = [];
            foreach ($keysField as $keyId => $b64) {
                if (! is_string($keyId) || $keyId === '' || strlen($keyId) > 255 || ! is_string($b64)) {
                    throw new \RuntimeException('Vault master key IDs must contain between 1 and 255 bytes and map to strings.');
                }
                $decoded = base64_decode($b64, true);
                if ($decoded === false || strlen($decoded) !== 32) {
                    throw new \RuntimeException("Master key '{$keyId}' from Vault must be exactly 32 bytes base64-encoded.");
                }
                $keys[(string) $keyId] = $decoded;
            }

            $activeKeyId = $secretData['activeKeyId'] ?? null;
            if (! is_string($activeKeyId) || $activeKeyId === '') {
                throw new \RuntimeException("Active master key ID field 'activeKeyId' is missing from Vault secret at '{$path}'.");
            }
            if (! isset($keys[$activeKeyId])) {
                throw new \RuntimeException("Active Vault master key ID '{$activeKeyId}' does not exist in the keys map.");
            }

            $this->keys = $keys;
            $this->activeKeyId = $activeKeyId;

            return;
        }

        // Fallback to legacy single-key format.
        $base64Key = $secretData[$field] ?? null;

        if ($base64Key === null || !is_string($base64Key)) {
            throw new \RuntimeException("Master key field '{$field}' not found in Vault secret at '{$path}'.");
        }

        $decoded = base64_decode($base64Key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('Master key from Vault must be exactly 32 bytes (256 bits) base64-encoded.');
        }

        $this->keys = ['default' => $decoded];
        $this->activeKeyId = 'default';
    }

    public function getMasterKey(): string
    {
        return $this->keys[$this->activeKeyId];
    }

    public function getKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function getMasterKeyById(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw new \RuntimeException("Unknown key ID: {$keyId}");
    }
}
