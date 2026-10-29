<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\CredentialDeletionResult;

final readonly class AdminCredentialApiHandler implements RequestHandler
{
    private const int MAX_BODY_BYTES = 16_384;

    public function __construct(
        private ExternalIdentityProvider $identityProvider,
        private ExternalCredentialIssuer $credentialIssuer,
        private string $adminToken,
    ) {
        if (trim($adminToken) === '') {
            throw new \InvalidArgumentException('External IAM admin API requires a non-empty admin token.');
        }
    }

    public function clusterAuthenticationIdentity(): string
    {
        if (! $this->identityProvider instanceof \OpsFour\S3Server\Auth\ClusterAuthenticationIdentityProvider) {
            throw new \RuntimeException('Cluster mode requires external IAM with an explicit owner-mapping identity.');
        }

        return $this->identityProvider->clusterAuthenticationIdentity();
    }

    public function handleRequest(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return match ($request->getMethod()) {
            'POST' => $this->issueCredential($request),
            'DELETE' => $this->revokeCredential($request),
            default => $this->json(['error' => 'method_not_allowed'], 405, ['Allow' => 'POST, DELETE']),
        };
    }

    private function issueCredential(Request $request): Response
    {
        try {
            $body = $this->decodeJsonBody($request);
            $token = $this->stringField($body, 'token');
            $ttlSeconds = $this->optionalPositiveIntField($body, 'ttlSeconds');
            if ($ttlSeconds !== null && ($ttlSeconds < ExternalCredentialIssuer::DEFAULT_TTL_SECONDS
                || $ttlSeconds > ExternalCredentialIssuer::MAX_TTL_SECONDS)) {
                throw new \InvalidArgumentException('Credential TTL must be between 3600 and 43200 seconds.');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
        }

        try {
            $identity = $this->identityProvider->authenticate($token);
        } catch (ExternalIdentityAuthenticationException|\InvalidArgumentException) {
            return $this->json([
                'error' => 'invalid_request',
                'message' => 'External identity authentication failed.',
            ], 400);
        }

        try {
            $issued = $this->credentialIssuer->issue($identity, $ttlSeconds);
        } catch (\InvalidArgumentException) {
            return $this->json([
                'error' => 'invalid_request',
                'message' => 'External identity is invalid.',
            ], 400);
        }

        return $this->json([
            'accessKeyId' => $issued->credential->accessKeyId,
            'secretAccessKey' => $issued->credential->secretAccessKey,
            'sessionToken' => $issued->credential->sessionToken,
            'ownerId' => $issued->credential->ownerId,
            'displayName' => $issued->credential->displayName,
            'expiresAt' => $issued->expiresAt?->format(\DateTimeInterface::ATOM),
            'policyNames' => $issued->credential->policyNames,
            'allowedPrefixes' => $issued->credential->allowedPrefixes,
        ], 201);
    }

    private function revokeCredential(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $prefix = '/.admin/credentials/';
        if (!str_starts_with($path, $prefix)) {
            return $this->json(['error' => 'not_found'], 404);
        }

        $accessKeyId = rawurldecode(substr($path, strlen($prefix)));
        if ($accessKeyId === ''
            || strlen($accessKeyId) > 255
            || preg_match('/^[A-Za-z0-9_-]+$/D', $accessKeyId) !== 1) {
            return $this->json(['error' => 'invalid_request', 'message' => 'Access key ID is invalid.'], 400);
        }

        return match ($this->credentialIssuer->revokeResult($accessKeyId)) {
            CredentialDeletionResult::Deleted => new Response(status: 204, headers: ['cache-control' => 'no-store']),
            CredentialDeletionResult::NotFound => $this->json(['error' => 'not_found'], 404),
            CredentialDeletionResult::Changed => $this->json(['error' => 'conflict'], 409),
        };
    }

    private function isAuthorized(Request $request): bool
    {
        $headers = $request->getHeaderArray('authorization');
        if (count($headers) !== 1) {
            return false;
        }
        $authorization = $headers[0];
        if (!str_starts_with(strtolower($authorization), 'bearer ')) {
            return false;
        }

        return hash_equals($this->adminToken, trim(substr($authorization, 7)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Request $request): array
    {
        try {
            $raw = $request->getBody()->buffer(limit: self::MAX_BODY_BYTES);
        } catch (BufferException) {
            throw new \InvalidArgumentException('Request body is too large.');
        }

        $decoded = json_decode($raw);
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        $body = get_object_vars($decoded);
        foreach (array_keys($body) as $field) {
            if ($field !== 'token' && $field !== 'ttlSeconds') {
                throw new \InvalidArgumentException("Unknown field '{$field}'.");
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $name): string
    {
        $value = $body[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Field '{$name}' must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalPositiveIntField(array $body, string $name): ?int
    {
        if (!array_key_exists($name, $body) || $body[$name] === null) {
            return null;
        }

        $value = $body[$name];
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException("Field '{$name}' must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<non-empty-string, string> $headers
     */
    private function json(array $payload, int $status = 200, array $headers = []): Response
    {
        return new Response(
            status: $status,
            headers: ['content-type' => 'application/json', 'cache-control' => 'no-store'] + $headers,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
