<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\ResultInterface;
use Aws\S3\S3Client;

/**
 * Replaces an abandoned AWS object with a permanent, identifiable seal.
 *
 * The caller owns the durable write intent and exclusive collector claim. The
 * backing bucket must disallow ordinary overwrites, deletes, and lifecycle
 * expiry of these physical keys. No handler or worker uses this class yet.
 */
final readonly class AwsS3SameKeySealWriter
{
    private const string MARKER_KEY = 'opsfour-write-seal';
    private const string ATTEMPT_KEY = 'opsfour-attempt-id';
    private const string MARKER_VALUE = 'v1';

    public function __construct(private S3Client $client, private string $bucket)
    {
        if ($bucket === '') {
            throw new \InvalidArgumentException('An AWS bucket is required.');
        }
    }

    /** Seal a key only while the abandoned object still has this exact ETag. */
    public function sealMatching(string $key, string $attemptId, string $expectedEtag): void
    {
        self::validate($key, $attemptId);
        if (! self::isExactEtag($expectedEtag)) {
            throw new \InvalidArgumentException('An exact, quoted AWS object ETag is required.');
        }

        $this->writeSeal($key, $attemptId, ['IfMatch' => $expectedEtag]);
    }

    /** Seal a key only while there is no current object at that key. */
    public function sealMissing(string $key, string $attemptId): void
    {
        self::validate($key, $attemptId);
        $this->writeSeal($key, $attemptId, ['IfNoneMatch' => '*']);
    }

    /** @param array{IfMatch?: string, IfNoneMatch?: string} $condition */
    private function writeSeal(string $key, string $attemptId, array $condition): void
    {
        $result = $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => "opsfour-s3-write-seal:v1:{$attemptId}\n",
            'ContentType' => 'application/octet-stream',
            'Metadata' => [self::MARKER_KEY => self::MARKER_VALUE, self::ATTEMPT_KEY => $attemptId],
            '@retries' => 0,
            ...$condition,
        ]);

        if (! $result instanceof ResultInterface
            || ! is_string($result['ETag'] ?? null)
            || ! self::isExactEtag($result['ETag'])
        ) {
            throw new \RuntimeException('AWS S3 seal PUT returned no verifiable ETag.');
        }

        // A successful PUT is insufficient if the response is malformed or the
        // current key is no longer our seal. HEAD verifies the reserved marker.
        $head = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            '@retries' => 0,
        ]);
        $metadata = $head['Metadata'] ?? null;
        if (! $head instanceof ResultInterface
            || ! is_array($metadata)
            || ($metadata[self::MARKER_KEY] ?? null) !== self::MARKER_VALUE
            || ($metadata[self::ATTEMPT_KEY] ?? null) !== $attemptId
            || ($head['ETag'] ?? null) !== $result['ETag']
        ) {
            throw new \RuntimeException('AWS S3 seal could not be verified by HEAD.');
        }
    }

    private static function validate(string $key, string $attemptId): void
    {
        if ($key === '' || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $attemptId)) {
            throw new \InvalidArgumentException('A physical key and an ASCII attempt ID are required.');
        }
    }

    private static function isExactEtag(string $etag): bool
    {
        return $etag !== '"*"' && preg_match('/^"[^"\s,]+"$/D', $etag) === 1;
    }
}
