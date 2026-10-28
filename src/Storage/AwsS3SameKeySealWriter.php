<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use Psr\Http\Message\StreamInterface;

/** Inspect, install or adopt one deterministic permanent same-key AWS seal. */
final readonly class AwsS3SameKeySealWriter
{
    private const string SEAL_MARKER_KEY = 'opsfour-write-seal';
    private const string ATTEMPT_MARKER_KEY = 'opsfour-write-attempt';
    private const string ATTEMPT_KEY = 'opsfour-attempt-id';
    private const string SEAL_MARKER_VALUE = 'v1';
    private const string ATTEMPT_MARKER_VALUE = 'v2';

    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $expectedOwnerId,
    ) {
        if ($bucket === '' || preg_match('/^[0-9]{12}$/D', $expectedOwnerId) !== 1) {
            throw new \InvalidArgumentException('An AWS bucket and expected owner are required.');
        }
    }

    public function isBoundTo(AwsS3ProviderValidatedBinding $binding): bool
    {
        return $binding->client === $this->client
            && $binding->profile !== null
            && hash_equals($binding->profile->bucket, $this->bucket)
            && hash_equals($binding->profile->expectedOwnerId, $this->expectedOwnerId);
    }

    /** Reconcile only exact v2 attempt evidence, a verified seal, or a conclusive NoSuchKey. */
    public function reconcile(ObjectWriteIntent $intent, ?\Closure $heartbeat = null): void
    {
        self::validateIntent($intent);
        $heartbeat?->__invoke();
        try {
            $head = $this->client->headObject($this->request($intent->storagePath));
        } catch (AwsException $error) {
            if (! $error->isConnectionError()
                && $error->getStatusCode() === 404
                && $error->getAwsErrorCode() === 'NoSuchKey') {
                $this->sealMissing($intent->storagePath, $intent->attemptId, $heartbeat);
                return;
            }
            if (! $error->isConnectionError()
                && $error->getStatusCode() === 404
                && $error->getAwsErrorCode() === 'NotFound') {
                $this->confirmMissingWithGet($intent->storagePath, $heartbeat);
                $this->sealMissing($intent->storagePath, $intent->attemptId, $heartbeat);
                return;
            }
            throw $error;
        }

        $metadata = $head['Metadata'] ?? null;
        $etag = $head['ETag'] ?? null;
        if (! $head instanceof ResultInterface || ! is_array($metadata) || ! is_string($etag) || ! self::isExactEtag($etag)) {
            throw new AwsS3SealIntegrityException('AWS object evidence is malformed.');
        }
        if (array_key_exists(self::SEAL_MARKER_KEY, $metadata)) {
            if ($metadata[self::SEAL_MARKER_KEY] === self::SEAL_MARKER_VALUE
                && ($metadata[self::ATTEMPT_KEY] ?? null) === $intent->attemptId) {
                $this->verifySeal($intent->storagePath, $intent->attemptId, $etag, $heartbeat);
                return;
            }
            throw new AwsS3SealIntegrityException('AWS seal evidence conflicts with durable metadata.');
        }
        $expected = [
            self::ATTEMPT_MARKER_KEY => self::ATTEMPT_MARKER_VALUE,
            self::ATTEMPT_KEY => $intent->attemptId,
            'opsfour-dispatch-id' => $intent->dispatchId,
            'opsfour-owner-digest' => hash('sha256', $intent->ownerId),
            'opsfour-content-size' => (string) $intent->expectedSize,
            'opsfour-content-sha256' => $intent->expectedSha256,
        ];
        foreach ($expected as $key => $value) {
            if (! is_string($value) || ($metadata[$key] ?? null) !== $value) {
                throw new AwsS3SealIntegrityException('AWS attempt evidence conflicts with durable metadata.');
            }
        }
        if (($head['ContentLength'] ?? null) !== $intent->expectedSize) {
            throw new AwsS3SealIntegrityException('AWS attempt size conflicts with durable metadata.');
        }
        $this->sealMatching($intent->storagePath, $intent->attemptId, $etag, $heartbeat);
    }

    public function sealMatching(string $key, string $attemptId, string $expectedEtag, ?\Closure $heartbeat = null): void
    {
        self::validateKey($key, $attemptId);
        if (! self::isExactEtag($expectedEtag)) {
            throw new \InvalidArgumentException('An exact, quoted AWS object ETag is required.');
        }
        $this->writeSeal($key, $attemptId, ['IfMatch' => $expectedEtag], $heartbeat);
    }

    public function sealMissing(string $key, string $attemptId, ?\Closure $heartbeat = null): void
    {
        self::validateKey($key, $attemptId);
        $this->writeSeal($key, $attemptId, ['IfNoneMatch' => '*'], $heartbeat);
    }

    /** @param array{IfMatch?: string, IfNoneMatch?: string} $condition */
    private function writeSeal(string $key, string $attemptId, array $condition, ?\Closure $heartbeat): void
    {
        $heartbeat?->__invoke();
        $result = $this->client->putObject([
            ...$this->request($key),
            'Body' => self::body($attemptId),
            'ContentType' => 'application/octet-stream',
            'Metadata' => [self::SEAL_MARKER_KEY => self::SEAL_MARKER_VALUE, self::ATTEMPT_KEY => $attemptId],
            ...$condition,
        ]);
        $etag = $result['ETag'] ?? null;
        if (! $result instanceof ResultInterface || ! is_string($etag) || ! self::isExactEtag($etag)) {
            throw new \RuntimeException('AWS S3 seal PUT returned no verifiable ETag.');
        }
        $this->verifySeal($key, $attemptId, $etag, $heartbeat);
    }

    private function verifySeal(string $key, string $attemptId, string $etag, ?\Closure $heartbeat): void
    {
        $body = null;
        try {
            $heartbeat?->__invoke();
            $expected = self::body($attemptId);
            $expectedLength = strlen($expected);
            $result = $this->client->getObject([
                ...$this->request($key),
                'IfMatch' => $etag,
                'Range' => 'bytes=0-' . $expectedLength,
            ]);
            $body = $result['Body'] ?? null;
            $metadata = $result['Metadata'] ?? null;
            if (! $result instanceof ResultInterface
                || ! $body instanceof StreamInterface
                || ($result['ETag'] ?? null) !== $etag
                || ($result['ContentLength'] ?? null) !== $expectedLength
                || ($result['ContentRange'] ?? null) !== 'bytes 0-' . ($expectedLength - 1) . '/' . $expectedLength
                || ! is_array($metadata)
                || ($metadata[self::SEAL_MARKER_KEY] ?? null) !== self::SEAL_MARKER_VALUE
                || ($metadata[self::ATTEMPT_KEY] ?? null) !== $attemptId) {
                throw new AwsS3SealIntegrityException('AWS S3 seal response is not exact.');
            }
            $actual = '';
            while (! $body->eof() && strlen($actual) <= $expectedLength) {
                $chunk = $body->read($expectedLength + 1 - strlen($actual));
                if ($chunk === '') {
                    throw new AwsS3SealIntegrityException('AWS S3 seal body made no progress.');
                }
                $actual .= $chunk;
            }
            if ($actual !== $expected || ! $body->eof()) {
                throw new AwsS3SealIntegrityException('AWS S3 seal body is not exact.');
            }
        } finally {
            try {
                $body?->close();
            } catch (\Throwable) {
            }
        }
    }

    private function confirmMissingWithGet(string $key, ?\Closure $heartbeat): void
    {
        $heartbeat?->__invoke();
        try {
            $result = $this->client->getObject([...$this->request($key), 'Range' => 'bytes=0-0']);
            $body = $result['Body'] ?? null;
            if ($body instanceof StreamInterface) {
                $body->close();
            }
        } catch (AwsException $error) {
            if (! $error->isConnectionError()
                && $error->getStatusCode() === 404
                && $error->getAwsErrorCode() === 'NoSuchKey') {
                return;
            }
            throw $error;
        }
        throw new AwsS3SealIntegrityException('AWS object absence could not be proven.');
    }

    /** @return array<string, mixed> */
    private function request(string $key): array
    {
        return [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ExpectedBucketOwner' => $this->expectedOwnerId,
            '@retries' => 0,
        ];
    }

    private static function validateIntent(ObjectWriteIntent $intent): void
    {
        self::validateKey($intent->storagePath, $intent->attemptId);
        if (! $intent->sealReserved
            || $intent->dispatchId === null
            || preg_match('/^[0-9a-f]{32}$/D', $intent->dispatchId) !== 1
            || $intent->dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH
            || $intent->expectedSize === null || $intent->expectedSize < 0
            || $intent->expectedSha256 === null
            || preg_match('/^[0-9a-f]{64}$/D', $intent->expectedSha256) !== 1) {
            throw new \InvalidArgumentException('Complete immutable AWS attempt evidence is required.');
        }
    }

    private static function validateKey(string $key, string $attemptId): void
    {
        if ($key === '' || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $attemptId) !== 1) {
            throw new \InvalidArgumentException('A physical key and an ASCII attempt ID are required.');
        }
    }

    private static function body(string $attemptId): string
    {
        return "opsfour-s3-write-seal:v1:{$attemptId}\n";
    }

    private static function isExactEtag(string $etag): bool
    {
        return $etag !== '"*"' && preg_match('/^"[^"\s,]+"$/D', $etag) === 1;
    }
}
