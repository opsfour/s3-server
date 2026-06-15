<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Admin;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;

final readonly class AdminQuotaApiHandler implements RequestHandler
{
    private const int MAX_BODY_BYTES = 4096;

    public function __construct(
        private MetadataStore $metadata,
        private string $adminToken,
    ) {
        if ($adminToken === '') {
            throw new \InvalidArgumentException('Quota admin API requires a non-empty admin token.');
        }
    }

    public function handleRequest(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        return match ($request->getMethod()) {
            'GET' => $this->handleGet($request),
            'PUT' => $this->handlePut($request),
            'DELETE' => $this->handleDelete($request),
            default => $this->json(['error' => 'method_not_allowed'], 405, ['Allow' => 'GET, PUT, DELETE']),
        };
    }

    private function handleGet(Request $request): Response
    {
        $ownerId = $this->ownerIdFromPath($request);
        if ($ownerId === null) {
            $quotas = [];
            foreach ($this->metadata->listAccountQuotas() as $ownerId => $quota) {
                $quotas[] = ['ownerId' => $ownerId] + self::quotaPayload($quota);
            }

            return $this->json(['quotas' => $quotas]);
        }

        $quota = $this->metadata->getAccountQuota($ownerId);
        if ($quota === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['ownerId' => $ownerId] + self::quotaPayload($quota));
    }

    private function handlePut(Request $request): Response
    {
        $ownerId = $this->ownerIdFromPath($request);
        if ($ownerId === null || $ownerId === '') {
            return $this->json(['error' => 'invalid_request', 'message' => 'Owner ID is required.'], 400);
        }

        try {
            $body = $this->decodeJsonBody($request);
            $quota = new QuotaConfig(
                maxBucketsPerOwner: $this->quotaInt($body, 'maxBucketsPerOwner', 'maxBuckets'),
                maxObjectsPerBucket: $this->quotaInt($body, 'maxObjectsPerBucket'),
                maxBytesPerBucket: $this->quotaInt($body, 'maxBytesPerBucket'),
                maxBytesPerOwner: $this->quotaInt($body, 'maxBytesPerOwner', 'maxBytes'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
        }

        $this->metadata->putAccountQuota($ownerId, $quota);

        return $this->json(['ownerId' => $ownerId] + self::quotaPayload($quota));
    }

    private function handleDelete(Request $request): Response
    {
        $ownerId = $this->ownerIdFromPath($request);
        if ($ownerId === null || $ownerId === '') {
            return $this->json(['error' => 'invalid_request', 'message' => 'Owner ID is required.'], 400);
        }

        $this->metadata->deleteAccountQuota($ownerId);

        return new Response(status: 204);
    }

    private function ownerIdFromPath(Request $request): ?string
    {
        $path = $request->getUri()->getPath();
        $prefix = '/.admin/quotas/';
        if ($path === '/.admin/quotas') {
            return null;
        }
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        return rawurldecode(substr($path, strlen($prefix)));
    }

    private function isAuthorized(Request $request): bool
    {
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
    private function quotaInt(array $body, string $name, ?string $alias = null): int
    {
        $value = $body[$name] ?? ($alias !== null ? ($body[$alias] ?? null) : null);
        if ($value === null) {
            return 0;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException("Field '{$name}' must be an integer >= 0.");
        }

        return $value;
    }

    /**
     * @return array<string, int>
     */
    private static function quotaPayload(QuotaConfig $quota): array
    {
        return [
            'maxBucketsPerOwner' => $quota->maxBucketsPerOwner,
            'maxObjectsPerBucket' => $quota->maxObjectsPerBucket,
            'maxBytesPerBucket' => $quota->maxBytesPerBucket,
            'maxBytesPerOwner' => $quota->maxBytesPerOwner,
        ];
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
