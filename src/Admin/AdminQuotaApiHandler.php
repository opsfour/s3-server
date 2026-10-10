<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Admin;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Quota\AccountQuotaWriter;
use OpsFour\S3Server\Quota\QuotaConfig;

final readonly class AdminQuotaApiHandler implements RequestHandler
{
    private const int MAX_BODY_BYTES = 4096;

    private const int DEFAULT_PAGE_LIMIT = 100;

    private const int MAX_PAGE_LIMIT = 1000;

    private const int MAX_CURSOR_BYTES = 32768;

    private const array QUOTA_FIELDS = [
        'maxBucketsPerOwner', 'maxBuckets',
        'maxObjectsPerBucket',
        'maxBytesPerBucket',
        'maxBytesPerOwner', 'maxBytes',
        'maxMultipartUploadsPerBucket', 'maxMultipartUploadsPerOwner',
        'maxMultipartBytesPerBucket', 'maxMultipartBytesPerOwner',
    ];

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
        try {
            $ownerId = $this->ownerIdFromPath($request);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
        }
        if ($ownerId === null) {
            $rawQuery = $request->getUri()->getQuery();
            if (QueryStringParser::hasConflictingValues($rawQuery)) {
                return $this->json(['error' => 'invalid_request', 'message' => 'Conflicting query parameters.'], 400);
            }
            $params = QueryStringParser::parse($rawQuery);
            foreach (array_keys($params) as $name) {
                if ($name !== 'limit' && $name !== 'cursor') {
                    return $this->json(['error' => 'invalid_request', 'message' => 'Unknown query parameter.'], 400);
                }
            }

            $paged = array_key_exists('limit', $params) || array_key_exists('cursor', $params);
            $limit = self::DEFAULT_PAGE_LIMIT;
            if (array_key_exists('limit', $params)) {
                $rawLimit = $params['limit'];
                if (!preg_match('/^[1-9][0-9]{0,3}$/D', $rawLimit) || (int) $rawLimit > self::MAX_PAGE_LIMIT) {
                    return $this->json(['error' => 'invalid_request', 'message' => 'Limit must be an integer between 1 and 1000.'], 400);
                }
                $limit = (int) $rawLimit;
            }

            $after = null;
            if (array_key_exists('cursor', $params)) {
                try {
                    $after = self::decodeCursor($params['cursor']);
                } catch (\InvalidArgumentException $e) {
                    return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
                }
            }

            $rows = $this->metadata->listAccountQuotasPage($after, ($paged ? $limit : self::MAX_PAGE_LIMIT) + 1);
            if (!$paged && count($rows) > self::MAX_PAGE_LIMIT) {
                return $this->json(['error' => 'pagination_required', 'message' => 'Use limit and cursor to list more than 1000 quotas.'], 400);
            }
            $hasMore = count($rows) > $limit;
            $quotas = [];
            foreach (array_slice($rows, 0, $paged ? $limit : self::MAX_PAGE_LIMIT) as $row) {
                $quotas[] = ['ownerId' => $row['ownerId']] + self::quotaPayload($row['quota']);
            }

            if (!$paged) {
                return $this->json(['quotas' => $quotas]);
            }

            $last = end($quotas);
            $nextCursor = $hasMore && is_array($last) ? self::encodeCursor((string) $last['ownerId']) : null;

            return $this->json(['quotas' => $quotas, 'nextCursor' => $nextCursor]);
        }

        $quota = $this->metadata->getAccountQuota($ownerId);
        if ($quota === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['ownerId' => $ownerId] + self::quotaPayload($quota));
    }

    private function handlePut(Request $request): Response
    {
        try {
            $ownerId = $this->ownerIdFromPath($request);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
        }
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
                maxMultipartUploadsPerBucket: $this->quotaInt($body, 'maxMultipartUploadsPerBucket'),
                maxMultipartUploadsPerOwner: $this->quotaInt($body, 'maxMultipartUploadsPerOwner'),
                maxMultipartBytesPerBucket: $this->quotaInt($body, 'maxMultipartBytesPerBucket'),
                maxMultipartBytesPerOwner: $this->quotaInt($body, 'maxMultipartBytesPerOwner'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
        }

        AccountQuotaWriter::put($this->metadata, $ownerId, $quota);

        return $this->json(['ownerId' => $ownerId] + self::quotaPayload($quota));
    }

    private function handleDelete(Request $request): Response
    {
        try {
            $ownerId = $this->ownerIdFromPath($request);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_request', 'message' => $e->getMessage()], 400);
        }
        if ($ownerId === null || $ownerId === '') {
            return $this->json(['error' => 'invalid_request', 'message' => 'Owner ID is required.'], 400);
        }

        AccountQuotaWriter::delete($this->metadata, $ownerId);

        return new Response(status: 204, headers: ['cache-control' => 'no-store']);
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

        $encoded = substr($path, strlen($prefix));
        if ($encoded === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $encoded)) {
            throw new \InvalidArgumentException('Owner ID is invalid.');
        }
        $ownerId = rawurldecode($encoded);
        if (strlen($ownerId) > 255 || preg_match('//u', $ownerId) !== 1) {
            throw new \InvalidArgumentException('Owner ID is invalid.');
        }

        return $ownerId;
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
        if (! $decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        $body = get_object_vars($decoded);
        if ($body === []) {
            throw new \InvalidArgumentException('Request body must specify at least one quota field.');
        }
        foreach (array_keys($body) as $field) {
            if (! in_array($field, self::QUOTA_FIELDS, true)) {
                throw new \InvalidArgumentException("Unknown quota field '{$field}'.");
            }
        }
        foreach ([['maxBucketsPerOwner', 'maxBuckets'], ['maxBytesPerOwner', 'maxBytes']] as [$name, $alias]) {
            if (array_key_exists($name, $body) && array_key_exists($alias, $body)) {
                throw new \InvalidArgumentException("Fields '{$name}' and '{$alias}' cannot be combined.");
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function quotaInt(array $body, string $name, ?string $alias = null): int
    {
        if (!array_key_exists($name, $body) && ($alias === null || !array_key_exists($alias, $body))) {
            throw new \InvalidArgumentException("Field '{$name}' is required.");
        }
        $value = $body[$name] ?? ($alias !== null ? ($body[$alias] ?? null) : null);
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException("Field '{$name}' must be an integer >= 0.");
        }

        return $value;
    }

    private static function encodeCursor(string $ownerId): string
    {
        return rtrim(strtr(base64_encode($ownerId), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $cursor): string
    {
        if ($cursor === '' || strlen($cursor) > self::MAX_CURSOR_BYTES || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid quota cursor.');
        }
        $after = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($after === false || $after === '' || preg_match('//u', $after) !== 1 || self::encodeCursor($after) !== $cursor) {
            throw new \InvalidArgumentException('Invalid quota cursor.');
        }

        return $after;
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
            'maxMultipartUploadsPerBucket' => $quota->maxMultipartUploadsPerBucket,
            'maxMultipartUploadsPerOwner' => $quota->maxMultipartUploadsPerOwner,
            'maxMultipartBytesPerBucket' => $quota->maxMultipartBytesPerBucket,
            'maxMultipartBytesPerOwner' => $quota->maxMultipartBytesPerOwner,
        ];
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
