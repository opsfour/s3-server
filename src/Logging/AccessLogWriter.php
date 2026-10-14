<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Logging;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Storage\DurableStorageDelete;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteOutcome;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Storage\RecoverableObjectWriteBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes S3 access log entries to the configured target bucket.
 *
 * Collects access log entries and periodically flushes them
 * as S3 objects. Fields follow the AWS access-log order; fields unavailable
 * to this package are marked with '-'. Full parity is tracked in the audit.
 */
final class AccessLogWriter
{
    private const string LOG_DELIVERY_PRINCIPAL = 'logging.s3.amazonaws.com';

    /** @var int Maximum number of entries to keep in the buffer. */
    private const int MAX_BUFFER_SIZE = 10_000;

    /** Keep large request URIs from turning the entry-count cap into unbounded memory use. */
    private const int MAX_BUFFER_BYTES = 16_777_216;

    private const int MAX_LOG_FIELD_BYTES = 8_192;

    /** @var list<array{bucket: string, owner: string, generation: string, targetBucket: string, targetGeneration: string, targetPrefix: string, line: string}> */
    private array $buffer = [];

    private int $bufferBytes = 0;

    private ?NotificationDispatcher $notifications;

    private ?NotificationDispatcher $qualifiedNotifications = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        LoggerInterface $logger = new NullLogger(),
        private readonly string $storageTier = 'STANDARD',
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
        ?NotificationDispatcher $notifications = null,
        private readonly ?LocalFencedObjectWriter $localFencedWriter = null,
        private readonly ?LocalObjectWriteLeaseGuardFactory $localLeaseFactory = null,
        private readonly int $maxBufferBytes = self::MAX_BUFFER_BYTES,
        private readonly ?EfsHostEvidenceReader $efsEvidence = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        if ($maxBufferBytes < 1 || $maxBufferBytes > self::MAX_BUFFER_BYTES) {
            throw new \InvalidArgumentException('Access log buffer byte limit is outside the supported range.');
        }
        $this->notifications = $notifications;
        if (($localFencedWriter === null) !== ($localLeaseFactory === null)) {
            throw new \InvalidArgumentException('Access log local writer and lease factory must be configured together.');
        }
        if ($localFencedWriter !== null) {
            if (! $metadata instanceof ObjectWriteIntentStore || $storageTier !== $localFencedWriter->storageTierName()) {
                throw new \InvalidArgumentException('Access log local writer requires matching durable intent metadata and storage tier.');
            }
            $backend = $storage;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof FilesystemBackend
                || ($backend->hostEvidenceReader() !== null
                    ? $backend->getBasePath()
                    : realpath($backend->getBasePath())) !== $localFencedWriter->storageRoot()) {
                throw new \InvalidArgumentException('Access log local writer requires the matching filesystem root.');
            }
        }
        if ($efsEvidence !== null) {
            if ($localFencedWriter === null || $localLeaseFactory === null) {
                throw new \InvalidArgumentException('EFS access logging requires a fenced writer and lease factory.');
            }
            $backend = $storage;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof FilesystemBackend) {
                throw new \InvalidArgumentException('EFS access logging requires the qualified filesystem backend.');
            }
            $localFencedWriter->assertBoundTo($backend, $storageTier, $efsEvidence);
        }
    }

    /** Bind the candidate log delivery path to its exact graph before serving. */
    public function assertBoundTo(
        MetadataStore $metadata,
        StorageBackend $storage,
        string $tier,
        LocalFencedObjectWriter $writer,
        LocalObjectWriteLeaseGuardFactory $leaseFactory,
        EfsHostEvidenceReader $evidence,
        NotificationDispatcher $notifications,
        ?QuotaManager $quotas,
        ?EncryptionServiceInterface $encryption,
    ): void {
        if ($this->metadata !== $metadata || $this->storage !== $storage
            || $this->storageTier !== $tier || $this->localFencedWriter !== $writer
            || $this->localLeaseFactory !== $leaseFactory || $this->efsEvidence !== $evidence
            || $this->notifications !== $notifications || $this->quotas !== $quotas
            || $this->encryption !== $encryption) {
            throw new \RuntimeException('Access log delivery differs from the qualified EFS resources.');
        }
        $evidence->assertFresh();
        $this->qualifiedNotifications = $notifications;
    }

    public function setNotificationDispatcher(NotificationDispatcher $notifications): void
    {
        if ($this->qualifiedNotifications !== null && $this->qualifiedNotifications !== $notifications) {
            throw new \LogicException('Qualified EFS access log notifications cannot be changed.');
        }
        $this->notifications = $notifications;
    }

    /**
     * Add an access log entry.
     */
    public function log(
        string $bucket,
        string $key,
        string $operation,
        int $httpStatus,
        int $bytesTransferred,
        string $remoteIp = '',
        string $requesterId = '',
        string $bucketOwnerId = '',
        string $requestId = '',
        string $requestUri = '',
        string $errorCode = '',
        ?\DateTimeImmutable $receivedAt = null,
        ?int $objectSize = null,
        string $bucketGeneration = '',
        string $hostId = '',
        string $userAgent = '',
        string $hostHeader = '',
        string $versionId = '',
        string $signatureVersion = '',
        string $cipherSuite = '',
        string $authenticationType = '',
        string $tlsVersion = '',
        string $referer = '',
    ): void {
        // Bind the entry before buffering it. Looking up the owner only at
        // flush time could send an old owner's request details to an account
        // that has since created a bucket with the same name.
        try {
            $this->efsEvidence?->assertFresh();
            $sourceInfo = $this->metadata->getBucket($bucket);
            if ($sourceInfo === null || ($bucketOwnerId !== '' && $sourceInfo->ownerId !== $bucketOwnerId)
                || ($bucketGeneration !== '' && $sourceInfo->generation !== $bucketGeneration)) {
                return;
            }
            $sourceOwner = $sourceInfo->ownerId;
            $sourceGeneration = $sourceInfo->generation;
            $this->efsEvidence?->assertFresh();
            $loggingConfig = $this->metadata->getBucketLogging($bucket);
            $targetAtRecord = null;
            if ($loggingConfig !== null) {
                $this->efsEvidence?->assertFresh();
                $targetAtRecord = $this->metadata->getBucket($loggingConfig['targetBucket']);
            }
        } catch (\Throwable $e) {
            // Access logging is best effort; a metadata outage must not turn
            // an otherwise successful S3 response into an error.
            $this->logger->warning('Could not record S3 access log entry.', ['exception' => $e::class]);
            return;
        }
        if ($sourceOwner === '') {
            return;
        }
        if ($loggingConfig === null || $targetAtRecord === null) {
            return;
        }
        $targetGeneration = $targetAtRecord->generation;

        $timestamp = ($receivedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('[d/M/Y:H:i:s +0000]');

        // Preserve field boundaries even when a key or request URI contains
        // whitespace, quotes, or control characters supplied by a client.
        $token = static fn(string $value): string => $value === ''
            ? '-'
            : (preg_replace_callback('/[\x00-\x20\x7F"\\\\]/', static fn(array $match): string => sprintf('%%%02X', ord($match[0])), self::boundedLogField($value)) ?? '-');
        $requestUri = self::boundedLogField($requestUri);
        $userAgent = self::boundedLogField($userAgent);
        $referer = self::boundedLogField($referer);
        $uri = $requestUri === '' ? '-' : '"' . (preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $match): string => sprintf('\\x%02X', ord($match[0])),
            str_replace(["\\", '"'], ['\\\\', '\\"'], $requestUri),
        ) ?? '-') . '"';
        $quotedUserAgent = $userAgent === '' ? '"-"' : '"' . (preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $match): string => sprintf('\\x%02X', ord($match[0])),
            str_replace(["\\", '"'], ['\\\\', '\\"'], $userAgent),
        ) ?? '-') . '"';
        $quotedReferer = $referer === '' ? '"-"' : '"' . (preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $match): string => sprintf('\\x%02X', ord($match[0])),
            str_replace(["\\", '"'], ['\\\\', '\\"'], $referer),
        ) ?? '-') . '"';

        $line = implode(' ', [
            $token($sourceOwner), // Bucket Owner
            $token($bucket),
            $timestamp,
            $token($remoteIp),
            $token($requesterId),
            $token($requestId),
            $token($operation),
            $token($key),
            $uri,
            (string) $httpStatus,
            $token($errorCode),
            $bytesTransferred > 0 ? (string) $bytesTransferred : '-',
            $objectSize !== null && $objectSize >= 0 ? (string) $objectSize : '-', // Object Size
            '-', // Total Time (streamed response duration is not available)
            '-', // Turn-Around Time
            $quotedReferer,
            $quotedUserAgent,
            $token($versionId),
            $token($hostId),
            $token($signatureVersion),
            $token($cipherSuite),
            $token($authenticationType),
            $token($hostHeader),
            $token($tlsVersion),
            '-', // Access Point ARN
            '-', // aclRequired
            '-', // Source region
        ]);

        $this->appendToBuffer([
            'bucket' => $bucket,
            'owner' => $sourceOwner,
            'generation' => $sourceGeneration,
            'targetBucket' => $loggingConfig['targetBucket'],
            'targetGeneration' => $targetGeneration,
            'targetPrefix' => $loggingConfig['targetPrefix'],
            'line' => $line,
        ]);
    }

    /** @param array{bucket: string, owner: string, generation: string, targetBucket: string, targetGeneration: string, targetPrefix: string, line: string} $entry */
    private function appendToBuffer(array $entry): void
    {
        $this->buffer[] = $entry;
        $this->bufferBytes += strlen($entry['line']);
        $dropped = false;
        while (count($this->buffer) > self::MAX_BUFFER_SIZE || $this->bufferBytes > $this->maxBufferBytes) {
            $oldest = array_shift($this->buffer);
            if ($oldest === null) {
                break;
            }
            $this->bufferBytes -= strlen($oldest['line']);
            $dropped = true;
        }
        if ($dropped) {
            $this->logger->warning('Access log buffer overflow, dropping oldest entry.');
        }
    }

    private static function boundedLogField(string $value): string
    {
        if (strlen($value) <= self::MAX_LOG_FIELD_BYTES) {
            return $value;
        }

        return substr($value, 0, self::MAX_LOG_FIELD_BYTES - 14) . '...[truncated]';
    }

    /**
     * Flush buffered log entries to the target bucket.
     *
     * Groups entries by the source and logging target captured for each entry,
     * and writes a log object to the target bucket with concatenated entries.
     */
    public function flush(): void
    {
        $this->efsEvidence?->assertFresh();
        if ($this->buffer === []) {
            return;
        }

        $entries = $this->buffer;
        $this->buffer = [];
        $this->bufferBytes = 0;

        // The serialized tuple is used only as an array key. Its length-framed
        // fields prevent numeric IDs or separator characters from colliding.
        /** @var array<string, array{bucket: string, owner: string, generation: string, targetBucket: string, targetGeneration: string, targetPrefix: string, lines: list<string>, entryIndexes: list<int>}> $grouped */
        $grouped = [];
        foreach ($entries as $entryIndex => $entry) {
            $groupKey = 'source:' . serialize([$entry['bucket'], $entry['owner'], $entry['generation'], $entry['targetBucket'], $entry['targetGeneration'], $entry['targetPrefix']]);
            $grouped[$groupKey]['bucket'] = $entry['bucket'];
            $grouped[$groupKey]['owner'] = $entry['owner'];
            $grouped[$groupKey]['generation'] = $entry['generation'];
            $grouped[$groupKey]['targetBucket'] = $entry['targetBucket'];
            $grouped[$groupKey]['targetGeneration'] = $entry['targetGeneration'];
            $grouped[$groupKey]['targetPrefix'] = $entry['targetPrefix'];
            $grouped[$groupKey]['lines'][] = $entry['line'];
            $grouped[$groupKey]['entryIndexes'][] = $entryIndex;
        }

        $totalFlushed = 0;
        /** @var array<int, true> $retryEntryIndexes */
        $retryEntryIndexes = [];

        foreach ($grouped as $group) {
            $sourceBucket = $group['bucket'];
            $sourceOwner = $group['owner'];
            $sourceGeneration = $group['generation'];
            $targetBucket = $group['targetBucket'];
            $targetGeneration = $group['targetGeneration'];
            $targetPrefix = $group['targetPrefix'];
            $lines = $group['lines'];
            $logKey = '';
            $attempt = new class {
                public ?string $targetBucket = null;

                public ?StorageWriteResult $writeResult = null;

                public ?S3Event $event = null;

                public bool $committed = false;

                public bool $writeResultValidated = false;

                public ?string $plannedPath = null;

                public ?string $publishedVersionId = null;

                public ?LocalObjectWriteLeaseGuard $lease = null;
            };
            try {
                $this->efsEvidence?->assertFresh();
                $sourceAtWrite = $this->metadata->getBucket($sourceBucket);
                if ($sourceAtWrite === null || $sourceAtWrite->ownerId !== $sourceOwner
                    || $sourceAtWrite->generation !== $sourceGeneration) {
                    continue;
                }
                $this->efsEvidence?->assertFresh();
                $targetAtWrite = $this->metadata->getBucket($targetBucket);
                if ($targetAtWrite !== null && $targetGeneration !== ''
                    && $targetAtWrite->generation !== $targetGeneration) {
                    continue;
                }
                if ($targetAtWrite === null || $targetAtWrite->ownerId !== $sourceOwner
                    || $targetAtWrite->region !== $sourceAtWrite->region) {
                    throw new \RuntimeException('Access log target is no longer owned by the source bucket owner in the same region.');
                }
                $this->efsEvidence?->assertFresh();
                if ($this->metadata->getObjectLockConfig($targetBucket) !== null) {
                    throw new \RuntimeException('Access log target has Object Lock enabled.');
                }

                // Remote storage writes may take seconds. Stage the uniquely
                // named object before taking the owner lock, then publish its
                // metadata only if the bucket generations still match.
                $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d-H-i-s');
                $logKey = $targetPrefix . $timestamp . '-' . bin2hex(random_bytes(8));
                $logContent = implode("\n", $lines) . "\n";
                $this->assertTargetPolicyDoesNotDeny($targetBucket, $logKey);
                $this->efsEvidence?->assertFresh();
                if ($this->metadata->getObjectMetadata($targetBucket, $logKey) !== null) {
                    throw new \RuntimeException('Access log target key already exists.');
                }
                $this->efsEvidence?->assertFresh();
                $versioningAtWrite = $this->metadata->getBucketVersioning($targetBucket);
                $this->efsEvidence?->assertFresh();
                $this->quotas?->assertCanWriteObject(
                    $sourceOwner,
                    $targetBucket,
                    null,
                    strlen($logContent),
                    $versioningAtWrite === 'Enabled',
                );
                $this->efsEvidence?->assertFresh();
                $bucketEncryption = $this->metadata->getBucketEncryption($targetBucket);
                \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
                $payload = $logContent;
                $userMetadata = [];
                if ($bucketEncryption !== null) {
                    if ($bucketEncryption['sseAlgorithm'] !== EncryptionRequestResolver::SSE_S3) {
                        throw new \RuntimeException('Unsupported access log target encryption algorithm.');
                    }
                    EncryptionRequestResolver::requireEncryptionService($this->encryption);
                    if (strlen($logContent) > $this->maxEncryptedObjectSize) {
                        throw new \RuntimeException('Access log object exceeds the configured encryption size limit.');
                    }
                    \assert($this->encryption !== null);
                    $bufferedWorkLock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
                    try {
                        $encrypted = $this->encryption->encryptSseS3($logContent);
                    } finally {
                        $bufferedWorkLock->release();
                    }
                    $payload = $encrypted['ciphertext'];
                    $userMetadata = [
                        '__sse-algorithm' => 'AES256',
                        '__sse-key' => $encrypted['encryptedDataKey'],
                        '__sse-iv' => $encrypted['iv'],
                        '__sse-tag' => $encrypted['tag'],
                    ];
                }
                $attempt->targetBucket = $targetBucket;
                if ($this->localFencedWriter !== null && $this->localLeaseFactory !== null) {
                    $attemptId = bin2hex(random_bytes(16));
                    $planned = new ObjectWriteIntent(
                        attemptId: $attemptId,
                        bucket: $targetBucket,
                        bucketGeneration: $targetGeneration,
                        ownerId: $sourceOwner,
                        storageTier: $this->storageTier,
                        storagePath: StoragePath::forPlannedObject(
                            $this->localFencedWriter->storageRoot(),
                            $targetBucket,
                            $logKey,
                            $targetGeneration,
                            $attemptId,
                        ),
                        purpose: 'access_log',
                    );
                    $attempt->plannedPath = $planned->storagePath;
                    $this->efsEvidence?->assertFresh();
                    $attempt->lease = $this->localLeaseFactory->reserve($planned);
                    $outcome = $this->localFencedWriter->publishWithLease(
                        $attempt->lease->reservedIntent(),
                        $logKey,
                        new ReadableBuffer($payload),
                        $attempt->lease,
                    );
                    if ($outcome->state !== LocalObjectWriteOutcome::COMPLETED || $outcome->result === null) {
                        throw $outcome->error ?? new \RuntimeException('Access log local write did not return a definite result.');
                    }
                    $attempt->writeResult = $outcome->result;
                } elseif ($this->hasPlannedObjectWriteCapability() && $targetGeneration !== '') {
                    \assert($this->storage instanceof RecoverableObjectWriteBackend);
                    $plannedPath = $this->storage->planObjectWritePath(
                        $targetBucket,
                        $logKey,
                        $targetGeneration,
                        bin2hex(random_bytes(16)),
                    );
                    if ($plannedPath === '') {
                        throw new \RuntimeException('Access log storage returned an empty planned path.');
                    }
                    $attempt->plannedPath = $plannedPath;
                    $attempt->writeResult = $this->storage->putObjectAtPath(
                        $targetBucket,
                        $logKey,
                        new ReadableBuffer($payload),
                        $attempt->plannedPath,
                    );
                } else {
                    $attempt->writeResult = $this->storage->putObject(
                        $targetBucket,
                        $logKey,
                        new ReadableBuffer($payload),
                    );
                }
                if (
                    $attempt->writeResult->path === ''
                    || ($attempt->plannedPath !== null && $attempt->writeResult->path !== $attempt->plannedPath)
                    || $attempt->writeResult->size !== strlen($payload)
                    || ! hash_equals(md5($payload), strtolower($attempt->writeResult->md5Hex))
                ) {
                    // A failed backend may report somebody else's path. Only
                    // the independently planned path is safe to clean up.
                    throw new \RuntimeException('Access log storage write result does not match the payload.');
                }
                $attempt->writeResultValidated = true;

                $this->efsEvidence?->assertFresh();
                $dispatchToken = $attempt->lease?->stopForCommit();

                $this->efsEvidence?->assertFresh();
                $this->metadata->transaction(function () use ($sourceBucket, $sourceOwner, $sourceGeneration, $targetBucket, $targetGeneration, $logKey, $logContent, $bucketEncryption, $userMetadata, $attempt, $dispatchToken): void {
                    $this->efsEvidence?->assertFresh();
                    // External metadata stores share this owner lock with bucket
                    // deletion. SQLite keeps the read and write in one transaction.
                    // Do not publish a log object to a target with a reused name.
                    $this->metadata->lockOwnerForUpdate($sourceOwner);
                    $this->efsEvidence?->assertFresh();
                    $sourceInfo = $this->metadata->getBucket($sourceBucket);
                    if ($sourceInfo === null || $sourceInfo->ownerId !== $sourceOwner || $sourceInfo->generation !== $sourceGeneration) {
                        return;
                    }
                    $this->efsEvidence?->assertFresh();
                    $targetInfo = $this->metadata->getBucket($targetBucket);
                    if ($targetInfo !== null && $targetGeneration !== '' && $targetInfo->generation !== $targetGeneration) {
                        return;
                    }
                    if ($targetInfo === null || $targetInfo->ownerId !== $sourceOwner || $targetInfo->region !== $sourceInfo->region) {
                        throw new \RuntimeException('Access log target is no longer owned by the source bucket owner in the same region.');
                    }
                    $this->efsEvidence?->assertFresh();
                    if ($this->metadata->getObjectLockConfig($targetBucket) !== null) {
                        throw new \RuntimeException('Access log target has Object Lock enabled.');
                    }
                    $this->efsEvidence?->assertFresh();
                    if ($this->metadata->getBucketEncryption($targetBucket) !== $bucketEncryption) {
                        throw new \RuntimeException('Access log target encryption changed during delivery.');
                    }
                    $this->assertTargetPolicyDoesNotDeny($targetBucket, $logKey);
                    $this->efsEvidence?->assertFresh();
                    if ($this->metadata->getObjectMetadata($targetBucket, $logKey) !== null) {
                        throw new \RuntimeException('Access log target key already exists.');
                    }
                    $this->efsEvidence?->assertFresh();
                    $versioning = $this->metadata->getBucketVersioning($targetBucket);
                    $this->efsEvidence?->assertFresh();
                    $this->quotas?->assertCanWriteObject(
                        $sourceOwner,
                        $targetBucket,
                        null,
                        strlen($logContent),
                        $versioning === 'Enabled',
                    );
                    $this->efsEvidence?->assertFresh();
                    if ($dispatchToken !== null && (! $this->metadata instanceof ObjectWriteIntentStore
                        || ! $this->metadata->consumeArmedObjectWriteIntent($dispatchToken->attemptId, $dispatchToken->dispatchId))) {
                        throw new \RuntimeException('Access log local write lease was lost before metadata publication.');
                    }
                    $etag = '"' . md5($logContent) . '"';
                    $this->efsEvidence?->assertFresh();
                    $versionId = $versioning === 'Enabled'
                        ? $this->metadata->putObjectVersioned(
                            bucket: $targetBucket,
                            key: $logKey,
                            ownerId: $sourceOwner,
                            size: strlen($logContent),
                            etag: $etag,
                            contentType: 'text/plain',
                            storagePath: $attempt->writeResult->path,
                            userMetadata: $userMetadata,
                        )
                        : null;
                    $attempt->publishedVersionId = $versionId;
                    if ($versionId === null) {
                        $this->efsEvidence?->assertFresh();
                        $this->metadata->putObjectMetadata(
                            bucket: $targetBucket,
                            key: $logKey,
                            ownerId: $sourceOwner,
                            size: strlen($logContent),
                            etag: $etag,
                            contentType: 'text/plain',
                            storagePath: $attempt->writeResult->path,
                            userMetadata: $userMetadata,
                        );
                    }
                    $this->efsEvidence?->assertFresh();
                    $this->metadata->updateObjectPlacement(
                        $targetBucket,
                        $logKey,
                        $versionId,
                        'STANDARD',
                        $this->storageTier,
                        $attempt->writeResult->path,
                    );
                    $this->efsEvidence?->assertFresh();
                    $this->metadata->putAcl('object', $targetBucket . '/' . $logKey, $sourceOwner, []);
                    $this->efsEvidence?->assertFresh();
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($targetBucket, $logKey, $versionId),
                        $sourceOwner,
                        AclGrantResolver::privateAcl($sourceOwner),
                    );

                    if ($this->notifications !== null) {
                        $attempt->event = $this->notifications->createEvent(
                            's3:ObjectCreated:Put',
                            $targetBucket,
                            $logKey,
                            strlen($logContent),
                            $etag,
                            $sourceOwner,
                            bucketGeneration: $targetGeneration,
                        );
                        if ($versionId !== null) {
                            $attempt->event = $attempt->event->withVersionId($versionId);
                        }
                        $this->notifications->enqueueWebhooks($attempt->event);
                    }

                    $attempt->committed = true;

                });
                if (!$attempt->committed && $this->localFencedWriter === null) {
                    DurableStorageDelete::run(
                        $this->metadata,
                        $this->storage,
                        $targetBucket,
                        $this->storageTier,
                        $attempt->plannedPath ?? $attempt->writeResult->path,
                    );
                    continue;
                }
                $totalFlushed += count($lines);
            } catch (\Throwable $e) {
                $cleanupPath = $attempt->plannedPath
                    ?? ($attempt->writeResultValidated ? $attempt->writeResult?->path : null);
                if ($attempt->committed) {
                    // The transaction callback finished, but a failed COMMIT
                    // acknowledgement cannot prove whether metadata persisted.
                    // Never enqueue a delete for a path that may be published.
                    $publicationConfirmed = false;
                    try {
                        $this->efsEvidence?->assertFresh();
                        $published = $attempt->publishedVersionId !== null
                            ? $this->metadata->getObjectMetadataByVersion($targetBucket, $logKey, $attempt->publishedVersionId)
                            : $this->metadata->getObjectMetadata($targetBucket, $logKey);
                        $publicationConfirmed = $cleanupPath !== null
                            && ($published?->systemMetadata['storagePath'] ?? null) === $cleanupPath;
                    } catch (\Throwable $probeError) {
                        $this->logger->warning('Could not verify access log publication after transaction failure.', [
                            'bucket' => $targetBucket,
                            'exception' => $probeError::class,
                        ]);
                    }
                    if ($publicationConfirmed) {
                        $totalFlushed += count($lines);
                        if ($attempt->event !== null) {
                            try {
                                $this->notifications?->dispatchInternalEvent($attempt->event);
                            } catch (\Throwable $notificationError) {
                                $this->logger->warning('Could not dispatch access log notification after confirmed publication.', [
                                    'bucket' => $targetBucket,
                                    'exception' => $notificationError::class,
                                ]);
                            }
                        }

                        continue;
                    }
                    $cleanupPath = null;
                }
                if ($this->localFencedWriter === null && $attempt->targetBucket !== null && $cleanupPath !== null) {
                    try {
                        DurableStorageDelete::run(
                            $this->metadata,
                            $this->storage,
                            $attempt->targetBucket,
                            $this->storageTier,
                            $cleanupPath,
                        );
                    } catch (\Throwable $cleanupError) {
                        $this->logger->critical(
                            'Access log object cleanup failed and could not be queued.',
                            [
                                'bucket' => $attempt->targetBucket,
                                'storage_path_hash' => hash('sha256', $cleanupPath),
                                'exception' => $cleanupError::class,
                            ],
                        );
                    }
                }
                foreach ($group['entryIndexes'] as $entryIndex) {
                    $retryEntryIndexes[$entryIndex] = true;
                }
                $this->logger->error('Failed to flush access logs for bucket.', [
                    'bucket' => $sourceBucket,
                    'exception' => $e::class,
                ]);
                continue;
            } finally {
                $attempt->lease?->close();
            }

            if ($attempt->event !== null) {
                $this->notifications?->dispatchInternalEvent($attempt->event);
            }
        }

        if ($retryEntryIndexes !== []) {
            // Storage work can yield while requests continue to append entries.
            // Restore older failed entries before those newer entries so the
            // bounded buffer still drops the chronologically oldest records.
            $bufferedDuringFlush = $this->buffer;
            $this->buffer = [];
            $this->bufferBytes = 0;
            foreach ($entries as $entryIndex => $entry) {
                if (isset($retryEntryIndexes[$entryIndex])) {
                    $this->appendToBuffer($entry);
                }
            }
            foreach ($bufferedDuringFlush as $entry) {
                $this->appendToBuffer($entry);
            }
        }

        if ($totalFlushed > 0) {
            $this->logger->debug('Flushed ' . $totalFlushed . ' access log entries.');
        }
    }

    private function hasPlannedObjectWriteCapability(): bool
    {
        $backend = $this->storage;
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        return $backend instanceof RecoverableObjectWriteBackend
            && $this->storage instanceof RecoverableObjectWriteBackend;
    }

    private function assertTargetPolicyDoesNotDeny(string $bucket, string $key): void
    {
        $this->efsEvidence?->assertFresh();
        $policy = $this->metadata->getBucketPolicy($bucket);
        if ($policy === null) {
            return;
        }

        $result = PolicyEvaluator::evaluate(
            $policy,
            's3:PutObject',
            "arn:aws:s3:::{$bucket}/{$key}",
            self::LOG_DELIVERY_PRINCIPAL,
            [
                'aws:CurrentTime' => gmdate(\DateTimeInterface::ATOM),
                'aws:SecureTransport' => 'true',
            ],
        );
        if ($result === 'Deny') {
            throw new \RuntimeException('Access log target bucket policy denies log delivery.');
        }
    }

    /**
     * Get the number of buffered entries.
     */
    public function getBufferCount(): int
    {
        return count($this->buffer);
    }
}
