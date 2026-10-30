<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Aws\S3\S3Client;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use Psr\Http\Message\StreamInterface;

/**
 * Acquires and streams one exact AWS payload from inside a worker process.
 *
 * @implements Task<null, string, string|null>
 */
final class AwsS3PayloadReadTask implements Task
{
    public const string COMMITTED = 'committed';
    public const string MULTIPART_PART = 'multipart_part';

    /** @var array<string, S3Client> */
    private static array $clients = [];

    public function __construct(
        private readonly AwsS3ProviderProfile $profile,
        private readonly string $mode,
        private readonly string $bucket,
        private readonly string $bucketGeneration,
        private readonly string $logicalScope,
        private readonly string $path,
        private readonly string $storageTier,
        private readonly string $expectedOwnerId,
        private readonly int $expectedSize,
        private readonly int $maximumSize,
        private readonly ?int $offset = null,
        private readonly ?int $length = null,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        return $this->runSafely(
            $cancellation,
            fn(): null => $this->runWithClient(self::client($this->profile), $channel, $cancellation),
        );
    }

    /** @param \Closure(): null $operation */
    private function runSafely(Cancellation $cancellation, \Closure $operation): null
    {
        try {
            $cancellation->throwIfRequested();

            return $operation();
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // Provider messages and response details must never cross IPC.
            throw new \RuntimeException('AWS payload read worker failed.');
        }
    }

    /**
     * @param Channel<string, string|null> $channel
     * @internal Public for protocol tests; production workers call run().
     */
    public function runWithClient(S3Client $client, Channel $channel, Cancellation $cancellation): null
    {
        $body = null;
        try {
            $attemptId = self::attemptId($this->path);
            AwsS3WriteAttemptPath::assertExactPath(
                $this->path,
                $this->bucket,
                $this->logicalScope,
                $this->bucketGeneration,
                $this->storageTier,
                $attemptId,
                $this->profile->reservedPrefix,
            );

            $request = [
                'Bucket' => $this->profile->bucket,
                'Key' => $this->path,
                'ExpectedBucketOwner' => $this->profile->expectedOwnerId,
                '@retries' => 0,
                '@http' => ['stream' => true],
            ];
            $expectedResponseLength = $this->expectedSize;
            $expectedContentRange = null;
            $expectedStatus = 200;
            if ($this->mode === self::COMMITTED && ($this->offset !== null || $this->length !== null)) {
                if ($this->offset === null || $this->length === null || $this->offset < 0 || $this->length < 1
                    || $this->offset > PHP_INT_MAX - $this->length || $this->offset >= $this->expectedSize
                    || $this->offset + $this->length > $this->expectedSize) {
                    throw new \InvalidArgumentException('Invalid AWS committed-object range.');
                }
                $end = $this->offset + $this->length - 1;
                $request['Range'] = "bytes={$this->offset}-{$end}";
                $expectedResponseLength = $this->length;
                $expectedContentRange = "bytes {$this->offset}-{$end}/{$this->expectedSize}";
                $expectedStatus = 206;
            }

            $result = $client->getObject($request);
            $body = $result->get('Body');
            $responseLength = $result->get('ContentLength');
            $contentRange = $result->get('ContentRange');
            $markers = $result->get('Metadata');
            $metadata = $result->get('@metadata');
            $status = is_array($metadata) ? ($metadata['statusCode'] ?? null) : null;

            if ($this->mode === self::MULTIPART_PART) {
                if (! is_int($responseLength) || $responseLength < $this->expectedSize || $responseLength > $this->maximumSize) {
                    throw new \RuntimeException('AWS multipart part length evidence is invalid.');
                }
                $expectedResponseLength = $responseLength;
            } elseif ($this->mode !== self::COMMITTED) {
                throw new \RuntimeException('AWS payload read mode is invalid.');
            }

            if (! $body instanceof StreamInterface || ! is_int($responseLength)
                || $responseLength !== $expectedResponseLength || $status !== $expectedStatus
                || ($expectedContentRange === null ? $contentRange !== null : $contentRange !== $expectedContentRange)
                || ! is_array($markers)
                || ($markers['opsfour-write-attempt'] ?? null) !== 'v2'
                || ($markers['opsfour-attempt-id'] ?? null) !== $attemptId
                || ($markers['opsfour-owner-digest'] ?? null) !== hash('sha256', $this->expectedOwnerId)
                || ($markers['opsfour-content-size'] ?? null) !== (string) ($this->mode === self::COMMITTED ? $this->expectedSize : $responseLength)) {
                throw new \RuntimeException('AWS payload response evidence is invalid.');
            }

            self::stream($body, $expectedResponseLength, $channel, $cancellation);

            return null;
        } finally {
            try {
                $body?->close();
            } catch (\Throwable) {
            }
        }
    }

    /** @param Channel<string, string|null> $channel */
    private static function stream(
        StreamInterface $body,
        int $expectedLength,
        Channel $channel,
        Cancellation $cancellation,
    ): void {
        $bytes = 0;
        while (true) {
            $cancellation->throwIfRequested();
            $remaining = $expectedLength - $bytes;
            $chunk = $body->read($remaining > 0 ? min(65_536, $remaining) : 1);
            $cancellation->throwIfRequested();
            if ($chunk === '') {
                if (! $body->eof()) {
                    throw new \RuntimeException('AWS provider returned an empty non-EOF chunk.');
                }
                if ($bytes !== $expectedLength) {
                    throw new \RuntimeException('AWS provider body ended before its declared length.');
                }
                $channel->send(null);

                return;
            }
            if ($remaining <= 0 || strlen($chunk) > $remaining) {
                throw new \RuntimeException('AWS provider body exceeded its declared length.');
            }
            $bytes += strlen($chunk);
            $channel->send($chunk);
            if ($channel->receive($cancellation) !== 'next') {
                return;
            }
        }
    }

    private static function client(AwsS3ProviderProfile $profile): S3Client
    {
        $key = hash('sha256', serialize([
            $profile->bucket,
            $profile->region,
            $profile->expectedOwnerId,
            $profile->dataPrefix,
            $profile->reservedPrefix,
            $profile->connectTimeoutSeconds,
            $profile->readTimeoutSeconds,
            $profile->requestTimeoutSeconds,
        ]));

        return self::$clients[$key] ??= (new AwsS3ProviderValidator())->createClient($profile);
    }

    private static function attemptId(string $path): string
    {
        $separator = strrpos($path, '/');

        return substr($path, $separator === false ? 0 : $separator + 1);
    }
}
