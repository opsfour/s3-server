<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use GuzzleHttp\TransferStats;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/** Provider wire primitive for one already-armed AWS write attempt. */
final readonly class AwsS3CreateOnlyWriter
{
    private const int MIN_MULTIPART_THRESHOLD = 5 * 1024 * 1024;
    private const int MAX_COMPLETE_RESPONSE_BYTES = 64 * 1024;
    private const float MIN_LEASE_MARGIN_SECONDS = 5.0;
    private const string MARKER_KEY = 'opsfour-write-attempt';
    private const string ATTEMPT_KEY = 'opsfour-attempt-id';
    private const string MARKER_VALUE = 'v2';

    public function __construct(
        private S3Client $client,
        private AwsS3ProviderProfile $profile,
        private ObjectWriteIntentStore $intents,
        private int $multipartThreshold = 16 * 1024 * 1024,
        private float $leaseSeconds = 300.0,
    ) {
        if ($multipartThreshold < self::MIN_MULTIPART_THRESHOLD) {
            throw new \InvalidArgumentException('An AWS multipart threshold of at least 5 MiB is required.');
        }
        if (! is_finite($leaseSeconds)
            || $leaseSeconds > 86400.0
            || $leaseSeconds < $profile->requestTimeoutSeconds + self::MIN_LEASE_MARGIN_SECONDS) {
            throw new \InvalidArgumentException('AWS write-attempt lease must exceed the provider request timeout by at least 5 seconds.');
        }
    }

    /** @param resource|string|\Psr\Http\Message\StreamInterface $body */
    public function publish(ObjectWriteIntent $armed, string $logicalKey, mixed $body): AwsS3CreateOnlyWriteOutcome
    {
        AwsS3WriteAttemptPath::assertProfileIntentPath($this->profile, $armed, $logicalKey);
        if ($armed->state !== 'active'
            || $armed->leaseUntil <= 0.0
            || $armed->claimGeneration !== 0
            || $armed->dispatchId === null
            || preg_match('/^[a-f0-9]{32}$/D', $armed->dispatchId) !== 1
            || $armed->dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH
            || $armed->expectedSize === null
            || $armed->expectedSize < 0
            || $armed->expectedSha256 === null
            || preg_match('/^[0-9a-f]{64}$/D', $armed->expectedSha256) !== 1
            || ! $armed->sealReserved) {
            throw new \InvalidArgumentException('A durably armed exact AWS write attempt is required.');
        }
        $key = $armed->storagePath;
        $attemptId = $armed->attemptId;

        $singlePutWasIssued = false;
        $multipartCompleteWasIssued = false;
        /** @var array{bucket: string, key: string, etag: string}|null $multipartCompletionEvidence */
        $multipartCompletionEvidence = null;
        $providerCommandStarted = false;
        $owner = $this->profile->expectedOwnerId;
        $metadata = [
            self::MARKER_KEY => self::MARKER_VALUE,
            self::ATTEMPT_KEY => $attemptId,
            'opsfour-dispatch-id' => $armed->dispatchId,
            'opsfour-owner-digest' => hash('sha256', $armed->ownerId),
            'opsfour-content-size' => (string) $armed->expectedSize,
            'opsfour-content-sha256' => $armed->expectedSha256,
        ];

        // The caller's snapshot is not authority. Commit a DB-clock renewal
        // before creating the uploader and before every provider command.
        $current = $armed;
        $renewAuthoritative = function () use (&$current): void {
            try {
                $renewed = $this->intents->renewArmedObjectWriteIntent(
                    $current->attemptId,
                    $current->dispatchId ?? '',
                    $current->leaseUntil,
                    $this->leaseSeconds,
                );
            } catch (\Throwable) {
                throw new AwsS3WriteAttemptArmException();
            }
            if ($renewed === null
                || ! self::sameArmedIdentityExceptLease($current, $renewed)
                || $renewed->leaseUntil < $current->leaseUntil) {
                throw new AwsS3WriteAttemptArmException();
            }
            $current = $renewed;
        };
        try {
            if ($body instanceof AwsS3MultipartPsrStream) {
                $body->beginArmedDispatch(
                    $renewAuthoritative,
                    min(60.0, max(1.0, $this->leaseSeconds / 4.0)),
                );
            } else {
                $renewAuthoritative();
            }
            // S3 binds object metadata at PutObject or multipart initiation;
            // CompleteMultipartUpload does not accept metadata itself.
            $result = (new ObjectUploader($this->client, $this->profile->bucket, $key, $body, 'private', [
                'mup_threshold' => $this->multipartThreshold,
                'request_checksum_calculation' => 'when_required',
                'before_initiate' => static function (CommandInterface $command) use ($owner, $metadata, $renewAuthoritative, &$providerCommandStarted): void {
                    $renewAuthoritative();
                    $providerCommandStarted = true;
                    unset($command['ACL']);
                    $command['ExpectedBucketOwner'] = $owner;
                    $command['Metadata'] = $metadata;
                    $command['@retries'] = 0;
                },
                'before_upload' => static function (CommandInterface $command) use (&$singlePutWasIssued, $owner, $metadata, $renewAuthoritative, &$providerCommandStarted): void {
                    $renewAuthoritative();
                    $providerCommandStarted = true;
                    $command['ExpectedBucketOwner'] = $owner;
                    $command['@retries'] = 0;
                    $context = $command['@context'] ?: [];
                    $context['request_checksum_calculation'] = 'when_required';
                    $command['@context'] = $context;
                    if ($command->getName() === 'PutObject') {
                        $singlePutWasIssued = true;
                        unset($command['ACL']);
                        $command['Metadata'] = $metadata;
                        $command['IfNoneMatch'] = '*';
                        $commandBody = $command['Body'];
                        if ($commandBody instanceof AwsS3MultipartPsrStream) {
                            $command['ContentSHA256'] = $commandBody->sha256Hex();
                        }
                    }
                },
                'before_complete' => function (CommandInterface $command) use (&$multipartCompleteWasIssued, &$multipartCompletionEvidence, $owner, $renewAuthoritative, &$providerCommandStarted): void {
                    $renewAuthoritative();
                    $providerCommandStarted = true;
                    $multipartCompleteWasIssued = true;
                    $command['ExpectedBucketOwner'] = $owner;
                    $command['IfNoneMatch'] = '*';
                    $command['@retries'] = 0;

                    $httpOptions = $command['@http'] ?: [];
                    $previousOnStats = $httpOptions['on_stats'] ?? null;
                    $httpOptions['on_stats'] = function (TransferStats $stats) use (&$multipartCompletionEvidence, $previousOnStats): void {
                        $multipartCompletionEvidence = self::completeResponseEvidence($stats);
                        if (is_callable($previousOnStats)) {
                            $previousOnStats($stats);
                        }
                    };
                    $command['@http'] = $httpOptions;
                },
            ]))->upload();
        } catch (\Throwable $error) {
            $armFailure = self::armFailure($error);
            if ($armFailure !== null) {
                if (! $providerCommandStarted) {
                    throw $armFailure;
                }

                return AwsS3CreateOnlyWriteOutcome::ambiguous();
            }

            return self::classifyFailure($error);
        }

        $etag = $result instanceof ResultInterface && is_string($result['ETag'] ?? null)
            ? $result['ETag'] : null;
        if ($etag === null
            || ! self::isExactEtag($etag)
            || ($singlePutWasIssued === $multipartCompleteWasIssued)
            || ($multipartCompleteWasIssued && ($multipartCompletionEvidence === null
                || $multipartCompletionEvidence['bucket'] !== $this->profile->bucket
                || $multipartCompletionEvidence['key'] !== $key
                || $multipartCompletionEvidence['etag'] !== $etag
                || ($result['Bucket'] ?? null) !== $this->profile->bucket
                || ($result['Key'] ?? null) !== $key))
        ) {
            return AwsS3CreateOnlyWriteOutcome::ambiguous();
        }

        return AwsS3CreateOnlyWriteOutcome::confirmedSuccess($etag);
    }

    public function isBoundTo(AwsS3ProviderProfile $profile, ObjectWriteIntentStore $intents, float $leaseSeconds): bool
    {
        return $this->intents === $intents
            && $this->leaseSeconds === $leaseSeconds
            && $this->profile->bucket === $profile->bucket
            && $this->profile->region === $profile->region
            && $this->profile->expectedOwnerId === $profile->expectedOwnerId
            && $this->profile->dataPrefix === $profile->dataPrefix
            && $this->profile->reservedPrefix === $profile->reservedPrefix
            && $this->profile->connectTimeoutSeconds === $profile->connectTimeoutSeconds
            && $this->profile->readTimeoutSeconds === $profile->readTimeoutSeconds
            && $this->profile->requestTimeoutSeconds === $profile->requestTimeoutSeconds;
    }

    private static function sameArmedIdentityExceptLease(ObjectWriteIntent $expected, ObjectWriteIntent $actual): bool
    {
        return hash_equals($expected->attemptId, $actual->attemptId)
            && $expected->bucket === $actual->bucket
            && hash_equals($expected->bucketGeneration, $actual->bucketGeneration)
            && $expected->ownerId === $actual->ownerId
            && $expected->storageTier === $actual->storageTier
            && hash_equals($expected->storagePath, $actual->storagePath)
            && $expected->purpose === $actual->purpose
            && $expected->state === $actual->state
            && $expected->claimGeneration === $actual->claimGeneration
            && $expected->expectedSize === $actual->expectedSize
            && $expected->expectedSha256 === $actual->expectedSha256
            && $expected->sealReserved === $actual->sealReserved
            && $expected->dispatchId !== null
            && $actual->dispatchId !== null
            && hash_equals($expected->dispatchId, $actual->dispatchId);
    }

    private static function classifyFailure(\Throwable $error): AwsS3CreateOnlyWriteOutcome
    {
        $aws = self::awsFailure($error);
        if ($aws === null) {
            return AwsS3CreateOnlyWriteOutcome::ambiguous();
        }

        $status = $aws->getStatusCode();
        $code = $aws->getAwsErrorCode();
        if (! $aws->isConnectionError()
            && (($status === 412 && $code === 'PreconditionFailed')
                || ($status === 409 && $code === 'ConditionalRequestConflict'))) {
            return AwsS3CreateOnlyWriteOutcome::preconditionConflict();
        }

        if (! $aws->isConnectionError()
            && (($status === 401 || $status === 403 || $status === 404)
                || ($status === 400 && in_array($code, [
                    'AccessDenied', 'AuthorizationHeaderMalformed', 'EntityTooLarge',
                    'InvalidAccessKeyId', 'InvalidArgument', 'InvalidBucketName',
                    'InvalidRequest', 'MethodNotAllowed', 'NoSuchBucket',
                    'NotImplemented', 'SignatureDoesNotMatch',
                ], true)))) {
            return AwsS3CreateOnlyWriteOutcome::confirmedRejection();
        }

        return AwsS3CreateOnlyWriteOutcome::ambiguous();
    }

    private static function armFailure(\Throwable $error): ?AwsS3WriteAttemptArmException
    {
        do {
            if ($error instanceof AwsS3WriteAttemptArmException) {
                return $error;
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return null;
    }

    private static function awsFailure(\Throwable $error): ?AwsException
    {
        do {
            if ($error instanceof AwsException) {
                return $error;
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return null;
    }

    private static function isExactEtag(string $etag): bool
    {
        return $etag !== '"*"' && preg_match('/^"[^"\s,]+"$/D', $etag) === 1;
    }

    /** @return array{bucket: string, key: string, etag: string}|null */
    private static function completeResponseEvidence(TransferStats $stats): ?array
    {
        $response = $stats->getResponse();
        if ($response === null || $response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody();
        if (! $body->isSeekable()) {
            return null;
        }

        $position = $body->tell();
        try {
            $body->rewind();
            $xml = '';
            while (! $body->eof() && strlen($xml) <= self::MAX_COMPLETE_RESPONSE_BYTES) {
                $chunk = $body->read(self::MAX_COMPLETE_RESPONSE_BYTES + 1 - strlen($xml));
                if ($chunk === '') {
                    break;
                }
                $xml .= $chunk;
            }
            if (! $body->eof() || strlen($xml) > self::MAX_COMPLETE_RESPONSE_BYTES) {
                return null;
            }
        } finally {
            $body->seek($position);
        }

        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        if ($root === false
            || $root->getName() !== 'CompleteMultipartUploadResult'
            || ($root->getDocNamespaces()[''] ?? null) !== $namespace
            || $root->xpath('//*[local-name()="Error"]') !== []) {
            return null;
        }

        $root->registerXPathNamespace('s3', $namespace);
        $bucket = $root->xpath('./s3:Bucket');
        $key = $root->xpath('./s3:Key');
        $etag = $root->xpath('./s3:ETag');
        $localBuckets = $root->xpath('./*[local-name()="Bucket"]');
        $localKeys = $root->xpath('./*[local-name()="Key"]');
        $localEtags = $root->xpath('./*[local-name()="ETag"]');
        if (! is_array($bucket) || ! is_array($key) || ! is_array($etag)
            || ! is_array($localBuckets) || ! is_array($localKeys) || ! is_array($localEtags)
            || count($bucket) !== 1 || count($key) !== 1 || count($etag) !== 1
            || count($localBuckets) !== 1 || count($localKeys) !== 1 || count($localEtags) !== 1) {
            return null;
        }

        return [
            'bucket' => (string) $bucket[0],
            'key' => (string) $key[0],
            'etag' => (string) $etag[0],
        ];
    }
}
