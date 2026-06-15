<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final readonly class AdminCredentialApiHandler implements RequestHandler
{
    private const int MAX_BODY_BYTES = 16_384;

    public function __construct(
        private ExternalIdentityProvider $identityProvider,
        private ExternalCredentialIssuer $credentialIssuer,
        private ?string $adminToken = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['error' => 'unauthorized'], 401);
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

            $identity = $this->identityProvider->authenticate($token);
            $issued = $this->credentialIssuer->issue($identity, $ttlSeconds);
        } catch (ExternalIdentityAuthenticationException|\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
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
        if ($accessKeyId === '') {
            return $this->json(['error' => 'invalid_request', 'message' => 'Access key ID is required.'], 400);
        }

        if (!$this->credentialIssuer->revoke($accessKeyId)) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return new Response(status: 204);
    }

    private function isAuthorized(Request $request): bool
    {
        if ($this->adminToken === null || $this->adminToken === '') {
            return true;
        }

        $authorization = $request->getHeader('authorization') ?? '';
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
        $raw = $request->getBody()->buffer(limit: self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('Request body is too large.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        return $decoded;
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
     * @param array<string, string> $headers
     */
    private function json(array $payload, int $status = 200, array $headers = []): Response
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'] + $headers,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
