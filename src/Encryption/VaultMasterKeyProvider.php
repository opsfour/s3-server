<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

/**
 * Reads master keys from HashiCorp Vault (KV v2 engine).
 *
 * Multi-key format: Vault secret contains a "keys" field with JSON map {keyId: base64Key}.
 * First key in the map is active.
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

        $client = $httpClient ?? HttpClientBuilder::buildDefault();

        $url = rtrim($addr, '/') . '/v1/' . ltrim($path, '/');
        $request = new Request($url, 'GET');
        $request->setHeader('X-Vault-Token', $token);

        $response = $client->request($request);
        $body = $response->getBody()->buffer();
        $statusCode = $response->getStatus();

        if ($statusCode !== 200) {
            throw new \RuntimeException("Vault returned HTTP {$statusCode} for path '{$path}': {$body}");
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $secretData = $data['data']['data'] ?? [];

        // Try multi-key format first: "keys" field with JSON map.
        $keysField = $secretData['keys'] ?? null;
        if (is_string($keysField)) {
            $keysField = json_decode($keysField, true, 4, JSON_THROW_ON_ERROR);
        }

        if (is_array($keysField) && $keysField !== []) {
            $keys = [];
            foreach ($keysField as $keyId => $b64) {
                $decoded = base64_decode($b64, true);
                if ($decoded === false || strlen($decoded) !== 32) {
                    throw new \RuntimeException("Master key '{$keyId}' from Vault must be exactly 32 bytes base64-encoded.");
                }
                $keys[(string) $keyId] = $decoded;
            }

            $this->keys = $keys;
            $this->activeKeyId = array_key_first($keys);

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
