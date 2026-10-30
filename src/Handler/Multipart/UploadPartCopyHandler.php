<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Checksum\Crc64NvmeCapturingStream;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\ConfigWorkerKeyringCompatible;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\CopySourceConditionEvaluator;
use OpsFour\S3Server\Http\CopySourceParser;
use OpsFour\S3Server\Http\MissingObjectListVisibility;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\ObjectLock\ObjectLockConfigurationSnapshot;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Security\ObjectReadAuthorizer;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\KnownSizeStorageCapabilities;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageTempReservation;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\LocalObjectWriteDispatchToken;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class UploadPartCopyHandler implements RequestHandler, \OpsFour\S3Server\Storage\AwsS3ForegroundWriterConsumer
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        ?StorageTierRegistry $storageTiers = null,
        private readonly ?QuotaManager $quotas = null,
        private readonly bool $requireLocalFencedParts = false,
        private readonly ?LocalFencedMultipartPartWriter $localPartWriter = null,
        private readonly ?AwsS3ForegroundObjectWriter $awsObjectWriter = null,
        private readonly ?StorageTempBudgetCoordinator $tempBudget = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
        if ($localPartWriter !== null && $awsObjectWriter !== null) {
            throw new \InvalidArgumentException('Local and AWS multipart part coordinators are mutually exclusive.');
        }
        if ($awsObjectWriter !== null) {
            $default = $this->storageTiers->defaultTier();
            $backend = $default->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $metadata instanceof ObjectWriteIntentStore
                || ! $backend instanceof AwsS3StorageBackend
                || $storage !== $default->backend
                || $awsObjectWriter->storageTierName() !== $default->name
                || ($profile = $backend->admittedProfile()) === null
                || ! $awsObjectWriter->isBoundTo($backend, $profile, $metadata)) {
                throw new \InvalidArgumentException('AWS multipart part-copy writer must match the intent-capable default AWS tier.');
            }
        }
    }

    public function usesAwsS3ForegroundObjectWriter(AwsS3ForegroundObjectWriter $writer): bool
    {
        return $this->awsObjectWriter === $writer;
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $queryParams['uploadId'] ?? '';
        $rawPartNumber = $queryParams['partNumber'] ?? '';
        $partNumber = ctype_digit($rawPartNumber) ? (int) $rawPartNumber : 0;

        if ($partNumber < 1 || $partNumber > 10000) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Part number must be between 1 and 10000.',
            );
        }

        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if ($upload['owner_id'] !== $ownerId) {
            throw new NoSuchUploadException();
        }
        if (($upload['bucket_generation'] ?? '') !== $bucketInfo->generation) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload belongs to another bucket generation.');
        }
        $checksumContract = MultipartChecksumContract::fromStored($upload['user_metadata']);
        $initiatedChecksumAlgorithm = $checksumContract?->algorithm;
        if ($initiatedChecksumAlgorithm !== null && (
            ! $this->metadata instanceof \OpsFour\S3Server\Metadata\MultipartPartChecksumStore
            || ! $this->metadata->supportsMultipartPartChecksums()
        )) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException('Multipart part checksum persistence is unavailable.');
        }
        $objectLockConfig = $this->assertObjectLockConfiguration($bucket, $upload['user_metadata']);
        ObjectLockConfigurationSnapshot::capture($request, $objectLockConfig);
        $uploadTier = MultipartUploadTierResolver::resolve($upload, $this->storageTiers);
        $uploadStorage = $uploadTier->backend;
        $storageTier = $uploadTier->name;
        $activeAwsObjectWriter = $this->awsObjectWriter !== null
            && $this->awsObjectWriter->storageTierName() === $storageTier
                ? $this->awsObjectWriter
                : null;
        $uploadSseAlgorithm = $upload['user_metadata']['__sse-algorithm'] ?? null;
        $this->assertPlainUploadAllowed($bucket, $uploadSseAlgorithm);
        $destinationCustomerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $uploadSseAlgorithm === 'SSE-C',
            $upload['user_metadata']['__sse-customer-key-md5'] ?? null,
        );
        if ($uploadSseAlgorithm !== 'SSE-C' && $destinationCustomerKey !== null) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'SSE-C headers are not valid for this multipart upload.',
            );
        }
        $partFormat = $upload['user_metadata']['__mpu-part-format'] ?? null;
        $encryptedV2 = $partFormat === EncryptedMultipartPartCodec::FORMAT;
        if ($partFormat !== null && $partFormat !== EncryptedMultipartPartCodec::PLAINTEXT_FORMAT && ! $encryptedV2) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Unknown multipart part format.');
        }
        if ($uploadSseAlgorithm !== null && ! $encryptedV2) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Legacy encrypted multipart upload must be aborted.');
        }
        $partIntentStore = MultipartPartWriteCapabilities::metadata($this->metadata);
        $plannedPartStorage = MultipartPartWriteCapabilities::storage($uploadStorage);
        $knownPlannedPartStorage = $plannedPartStorage === null
            ? null
            : KnownSizeStorageCapabilities::recoverable($plannedPartStorage);
        $knownUploadStorage = KnownSizeStorageCapabilities::storage($uploadStorage);
        $localFencedPart = MultipartPartWriteCapabilities::localFilesystem($uploadStorage);
        $activeLocalPartWriter = $this->localPartWriter !== null
            && $this->localPartWriter->isBoundTo($this->metadata, $uploadStorage, $storageTier)
                ? $this->localPartWriter
                : null;
        if (($this->requireLocalFencedParts && ($activeLocalPartWriter === null || ! $localFencedPart))
            || ($localFencedPart && (! $partIntentStore instanceof FencedMultipartPartWriteIntentStore || $plannedPartStorage === null))) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Local multipart parts require a fenced filesystem and metadata store.');
        }
        if ($localFencedPart && $this->localPartWriter !== null && $activeLocalPartWriter === null) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Local multipart part writer is not bound to this upload tier.');
        }
        $dispatchId = null;
        if ($encryptedV2) {
            EncryptionRequestResolver::requireEncryptionService($this->encryption, $uploadSseAlgorithm);
            if ($uploadSseAlgorithm === null || $this->encryption === null) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted multipart upload has no encryption mode.');
            }
            if ($this->requireLocalFencedParts && $uploadSseAlgorithm === EncryptionRequestResolver::SSE_S3
                && (! $this->encryption instanceof ConfigWorkerKeyringCompatible
                    || $this->encryption->configWorkerKeyringFingerprint() === null)) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException(
                    'Guarded SSE-S3 multipart parts require the injected worker-compatible Config keyring.',
                );
            }
            if ($activeAwsObjectWriter === null && ($partIntentStore === null || $plannedPartStorage === null)) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted multipart parts require durable planned-path backends.');
            }
        }

        // Parse copy source.
        $copySource = RequestHeaderValues::singleton($request, 'x-amz-copy-source') ?? '';
        [$srcBucket, $srcKey, $srcVersionId] = CopySourceParser::parse($copySource);

        $srcBucketInfo = $this->metadata->getBucket($srcBucket);
        if ($srcBucketInfo === null) {
            throw new NoSuchKeyException();
        }
        $srcVersioning = $this->metadata->getBucketVersioning($srcBucket);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $srcBucketInfo);
        $request->setAttribute('s3.copySourceBucket', $srcBucket);
        $request->setAttribute('s3.copySourceKey', $srcKey);
        $request->setAttribute('s3.copySourceVersionId', $srcVersionId);
        $request->setAttribute('s3.copySourceBucketOwner', $srcBucketInfo->ownerId);
        $request->setAttribute('s3.copySourceBucketGeneration', $srcBucketInfo->generation);
        $expectedSourceOwner = RequestHeaderValues::singleton($request, 'x-amz-source-expected-bucket-owner');
        if ($expectedSourceOwner !== null && $expectedSourceOwner !== $srcBucketInfo->ownerId) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw new \OpsFour\S3Server\Exception\AccessDeniedException('Expected source bucket owner does not match.');
        }

        $srcObject = ($srcVersionId !== null)
            ? $this->metadata->getObjectMetadataByVersion($srcBucket, $srcKey, $srcVersionId)
            : $this->metadata->getObjectMetadata($srcBucket, $srcKey);
        if ($srcVersionId !== null && $srcObject?->isDeleteMarker === true) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw new InvalidRequestException('The specified copy source is a delete marker.');
        }
        if ($srcObject === null || $srcObject->isDeleteMarker) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            if ($srcVersionId === null
                && $srcObject === null
                && $this->metadata->getCurrentObjectVersion($srcBucket, $srcKey) === null
                && ! MissingObjectListVisibility::canList($this->metadata, $request, $srcBucket, copySource: true)) {
                throw new \OpsFour\S3Server\Exception\AccessDeniedException();
            }
            throw new NoSuchKeyException();
        }
        $request->setAttribute('s3.copySourceVersionId', $srcObject->versionId);
        $request->setAttribute('s3.copySourceObjectSize', $srcObject->size);
        try {
            (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $srcObject, $srcVersionId !== null);
            CopySourceConditionEvaluator::assertSatisfied($request, $srcObject);
        } catch (\Throwable $e) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw $e;
        }

        // Parse optional range.
        $rangeHeader = RequestHeaderValues::singleton($request, 'x-amz-copy-source-range');
        $offset = null;
        $length = null;

        if ($rangeHeader !== null) {
            if (preg_match('/\Abytes=([0-9]+)-([0-9]+)\z/', $rangeHeader, $matches) !== 1) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                    'Copy source range must use bytes=first-last.',
                );
            }

            $maxInteger = (string) PHP_INT_MAX;
            foreach ([$matches[1], $matches[2]] as $value) {
                $digits = ltrim($value, '0');
                if ($digits !== '' && (
                    strlen($digits) > strlen($maxInteger)
                    || (strlen($digits) === strlen($maxInteger) && strcmp($digits, $maxInteger) > 0)
                )) {
                    throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                        'Range specified is not valid for source object size.',
                    );
                }
            }

            $start = (int) $matches[1];
            $end = (int) $matches[2];
            if ($start > $end || $start >= $srcObject->size || $end >= $srcObject->size) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                    'Range specified is not valid for source object size.',
                );
            }
            if ($srcObject->size <= 5 * 1024 * 1024) {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                    'Ranged copy requires a source object larger than 5 MB.',
                );
            }
            $offset = $start;
            $length = $end - $start + 1;
        }

        MultipartPartSize::assertStoredSize($length ?? $srcObject->size);
        // Read source data — decrypt if the source object is encrypted.
        $srcPath = $srcObject->systemMetadata['storagePath'] ?? null;
        if ($srcPath === null || $srcPath === '') {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Source object storage path missing.');
        }
        $sourceTier = $this->storageTiers->tier($srcObject->storageTier);
        $sourceStorage = $sourceTier->backend;
        if ($sourceTier->restoreRequired) {
            if (
                $srcObject->restoreStatus !== 'restored'
                || $srcObject->restoredStoragePath === null
                || $srcObject->restoreExpiresAt === null
                || $srcObject->restoreExpiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                throw new InvalidObjectStateException('The source object must be restored before it can be copied.');
            }
            $sourceStorage = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($srcObject, $this->storageTiers)->backend;
            $srcPath = $srcObject->restoredStoragePath;
        }
        $srcSseAlgo = $srcObject->userMetadata['__sse-algorithm'] ?? null;
        if ($srcSseAlgo !== null) {
            EncryptionRequestResolver::requireEncryptionService($this->encryption, $srcSseAlgo);
        }
        $sourceCustomerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $srcSseAlgo === 'SSE-C',
            $srcObject->userMetadata['__sse-customer-key-md5'] ?? null,
            copySource: true,
        );
        if ($srcSseAlgo !== 'SSE-C' && $sourceCustomerKey !== null) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Copy-source SSE-C headers are not valid for this object.',
            );
        }

        $bufferedWorkLock = null;
        $awsReservation = null;
        $awsDispatchToken = null;
        $awsStage = null;
        $stream = null;
        $plannedPartPath = null;
        $copyCapacity = null;
        $publicationCapacity = null;
        $request->setAttribute('s3.copySourceLogEligible', true);
        $copyCancellation = null;
        try {
            $expectedSize = $length ?? $srcObject->size;
            if ($this->tempBudget !== null && ($activeAwsObjectWriter !== null
                || $activeLocalPartWriter !== null
                || $knownPlannedPartStorage !== null
                || $knownUploadStorage !== null)) {
                $physicalSize = $encryptedV2
                    ? EncryptedMultipartPartCodec::worstCaseEncodedBytes($expectedSize)
                    : $expectedSize;
                $copyCapacity = $this->tempBudget->reserveCapacity($physicalSize, 'upload_part_copy');
                if ($activeLocalPartWriter !== null) {
                    $publicationCapacity = $this->tempBudget->reserveCapacity(
                        $physicalSize,
                        'fenced_part_publish',
                    );
                }
            }
            $bufferedWorkLock = $srcSseAlgo !== null || $encryptedV2
                ? \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire()
                : null;
            $awsReservation = $activeAwsObjectWriter?->reserveMultipartPart(
                $bucketInfo,
                $key,
                $ownerId,
                $uploadId,
                $partNumber,
                copy: true,
            );
            if ($srcSseAlgo !== null && $this->encryption !== null) {
                // Size guard: encrypted objects must be fully buffered for decryption.
                if ($srcObject->size > $this->maxEncryptedObjectSize) {
                    throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                        'Encrypted source object exceeds maximum size for decryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                    );
                }

                // Source is encrypted: read ciphertext, decrypt, then write plaintext as part.
                [$ciphertext] = BufferedSsePayload::readExpected(
                    $this->copySourceStream($sourceStorage, $srcBucketInfo, $srcObject, $srcKey, $srcPath, null, null, $awsReservation),
                    $srcObject->size,
                    $this->maxEncryptedObjectSize,
                );

                $decode = function () use ($srcSseAlgo, $sourceCustomerKey, $ciphertext, $srcObject, $offset, $length): string {
                    if ($srcSseAlgo === 'SSE-C') {
                        \assert($sourceCustomerKey !== null);
                        $plaintext = $this->encryption?->decryptSseC(
                            $ciphertext,
                            $sourceCustomerKey,
                            $srcObject->userMetadata['__sse-iv'],
                            $srcObject->userMetadata['__sse-tag'],
                        ) ?? throw new \LogicException('Copy source encryption service disappeared.');
                    } else {
                        $plaintext = $this->encryption?->decryptSseS3(
                            $ciphertext,
                            $srcObject->userMetadata['__sse-key'],
                            $srcObject->userMetadata['__sse-iv'],
                            $srcObject->userMetadata['__sse-tag'],
                        ) ?? throw new \LogicException('Copy source encryption service disappeared.');
                    }
                    if (strlen($plaintext) !== $srcObject->size) {
                        throw new \OpsFour\S3Server\Exception\InternalErrorException(
                            'Decrypted copy source size does not match metadata.',
                        );
                    }

                    if ($offset === null) {
                        return $plaintext;
                    }

                    return $length === null
                        ? substr($plaintext, $offset)
                        : substr($plaintext, $offset, $length);
                };
                $plaintext = $awsReservation !== null
                    ? ($activeAwsObjectWriter ?? throw new \LogicException('AWS multipart writer disappeared.'))
                        ->transform($awsReservation, $decode)
                    : $decode();

                $stream = new \Amp\ByteStream\ReadableBuffer($plaintext);
            } else {
                // A corrupt or misbehaving backend must not stage unlimited
                // physical bytes for a source with small committed metadata.
                // Read one extra byte for a full-source copy so the existing
                // post-write size check can reject an oversized source.
                $expectedSize = $length ?? $srcObject->size;
                $readLimit = $length ?? ($expectedSize < PHP_INT_MAX ? $expectedSize + 1 : PHP_INT_MAX);
                $stream = new LimitedReadableStream(
                    $this->copySourceStream($sourceStorage, $srcBucketInfo, $srcObject, $srcKey, $srcPath, $offset, $length, $awsReservation),
                    $readLimit,
                );
            }

            $needsCrc64Capture = $initiatedChecksumAlgorithm === 'crc64nvme'
                || ($offset === null && isset($srcObject->systemMetadata['checksum-crc64nvme']));
            $crc64Capture = null;
            $crc64Value = null;
            if (! $encryptedV2 && $needsCrc64Capture) {
                $crc64Capture = new Crc64NvmeCapturingStream($stream);
                $stream = $crc64Capture;
            }

            if ($encryptedV2 && $this->encryption !== null && $uploadSseAlgorithm !== null) {
                $expectedSize = $length ?? $srcObject->size;
                [$plaintext, $checksums] = BufferedSsePayload::read(
                    $stream,
                    $this->maxEncryptedObjectSize,
                    expectedBytes: $expectedSize,
                    captureCrc64Nvme: $needsCrc64Capture,
                );
                $sourceMd5 = [];
                if ($offset === null && preg_match('/\A"([0-9a-fA-F]{32})"\z/', $srcObject->etag, $sourceMd5) === 1
                    && ! hash_equals(strtolower($sourceMd5[1]), $checksums->md5Hex)) {
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source payload does not match its metadata.');
                }
                if ($offset === null) {
                    $this->assertSourceChecksums($srcObject, new StorageWriteResult(
                        '',
                        $checksums->size,
                        $checksums->md5Hex,
                        $checksums->crc32Base64(),
                        $checksums->crc32cBase64(),
                        $checksums->sha1Base64(),
                        $checksums->sha256Base64(),
                        $checksums->crc64NvmeBase64(),
                    ), $sourceStorage);
                }
                $encode = fn(): string => EncryptedMultipartPartCodec::encode(
                    $this->encryption,
                    $uploadSseAlgorithm,
                    $plaintext,
                    $destinationCustomerKey,
                    $bucketInfo->generation,
                    $bucket,
                    $uploadId,
                    $partNumber,
                );
                $ciphertext = $awsReservation !== null
                    ? ($activeAwsObjectWriter ?? throw new \LogicException('AWS multipart writer disappeared.'))
                        ->transform($awsReservation, $encode)
                    : $encode();
                if ($awsReservation !== null) {
                    $awsStage = $activeAwsObjectWriter->stage(
                        $awsReservation,
                        new \Amp\ByteStream\ReadableBuffer($ciphertext),
                        strlen($ciphertext),
                        $copyCapacity,
                    );
                    if ($awsStage->result->size !== strlen($ciphertext)
                        || ! hash_equals(md5($ciphertext), strtolower($awsStage->result->md5Hex))) {
                        throw new \OpsFour\S3Server\Exception\InternalErrorException(
                            'Encrypted AWS multipart copy stage does not match its ciphertext.',
                        );
                    }
                    $outcome = $activeAwsObjectWriter->dispatchMultipartPart($awsReservation, $key, $uploadId, $partNumber, $awsStage);
                    $plannedPartPath = $outcome->result->path;
                    $awsDispatchToken = $outcome->dispatch;
                    $physical = $outcome->result;
                } elseif ($activeLocalPartWriter !== null) {
                    $validatedPhysical = null;
                    $outcome = $activeLocalPartWriter->publish(
                        $bucket,
                        $bucketInfo->generation,
                        $uploadId,
                        $partNumber,
                        new \Amp\ByteStream\ReadableBuffer($ciphertext),
                        partFormat: EncryptedMultipartPartCodec::FORMAT,
                        logicalSize: $checksums->size,
                        validateBeforeArm: static function (\OpsFour\S3Server\Checksum\ChecksumResult $staged) use ($ciphertext, &$validatedPhysical): void {
                            MultipartPartLogicalValidator::assertEncodedBytes($staged, $ciphertext);
                            $validatedPhysical = $staged;
                        },
                        knownPhysicalSize: strlen($ciphertext),
                        capacity: $copyCapacity,
                        publicationCapacity: $publicationCapacity,
                    );
                    if ($outcome->result === null || $outcome->dispatchId === null || $validatedPhysical === null) {
                        throw $outcome->error ?? new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted local multipart part copy dispatch did not complete.');
                    }
                    MultipartPartLogicalValidator::assertPhysicalMatches($validatedPhysical, $outcome->result);
                    $plannedPartPath = $outcome->path;
                    $dispatchId = $outcome->dispatchId;
                    $physical = $outcome->result;
                } else {
                    if ($plannedPartStorage === null || $partIntentStore === null) {
                        throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted multipart parts require durable planned-path backends.');
                    }
                    $plannedPartPath = $plannedPartStorage->planMultipartPartPath(
                        $bucket,
                        $bucketInfo->generation,
                        $uploadId,
                        $partNumber,
                        bin2hex(random_bytes(16)),
                    );
                    $dispatchId = $this->startWriteIntent(
                        $partIntentStore,
                        $bucket,
                        $bucketInfo->generation,
                        $storageTier,
                        $uploadId,
                        $plannedPartPath,
                        $localFencedPart,
                    );
                    try {
                        $ciphertextBody = new \Amp\ByteStream\ReadableBuffer($ciphertext);
                        $physical = $knownPlannedPartStorage !== null
                            ? $knownPlannedPartStorage->putPartAtPathKnownSize(
                                $bucket,
                                $key,
                                $uploadId,
                                $partNumber,
                                $ciphertextBody,
                                $plannedPartPath,
                                strlen($ciphertext),
                                $copyCapacity,
                            )
                            : $plannedPartStorage->putPartAtPath(
                                $bucket,
                                $key,
                                $uploadId,
                                $partNumber,
                                $ciphertextBody,
                                $plannedPartPath,
                            );
                    } catch (\Throwable $e) {
                        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                            $e,
                            fn() => $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath, ownerId: $ownerId),
                        );
                        throw $e;
                    }
                }
                if ($physical->path !== $plannedPartPath
                    || $physical->size !== strlen($ciphertext)
                    || ! hash_equals(md5($ciphertext), strtolower($physical->md5Hex))) {
                    $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath, $uploadId, $ownerId);
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted multipart part copy write does not match its payload.');
                }
                $writeResult = new StorageWriteResult(
                    $physical->path,
                    $checksums->size,
                    $checksums->md5Hex,
                    $checksums->crc32Base64(),
                    $checksums->crc32cBase64(),
                    $checksums->sha1Base64(),
                    $checksums->sha256Base64(),
                    $checksums->crc64NvmeBase64(),
                );
            } else {
                $expectedSize = $length ?? $srcObject->size;
                if ($awsReservation !== null) {
                    $awsStage = $activeAwsObjectWriter->stage($awsReservation, $stream, $expectedSize, $copyCapacity);
                    $logicalStage = $awsStage->result;
                    if ($crc64Capture !== null) {
                        $crc64Value = $crc64Capture->finalizeBase64();
                        $logicalStage = self::withCrc64Nvme($logicalStage, $crc64Value);
                    }
                    $sourceMd5 = [];
                    if ($awsStage->result->size !== $expectedSize
                        || ($offset === null
                            && preg_match('/\A"([0-9a-fA-F]{32})"\z/', $srcObject->etag, $sourceMd5) === 1
                            && ! hash_equals(strtolower($sourceMd5[1]), strtolower($awsStage->result->md5Hex)))) {
                        throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source payload does not match its metadata.');
                    }
                    if ($offset === null) {
                        $this->assertSourceChecksums($srcObject, $logicalStage, $uploadStorage);
                    }
                    $outcome = $activeAwsObjectWriter->dispatchMultipartPart($awsReservation, $key, $uploadId, $partNumber, $awsStage);
                    $plannedPartPath = $outcome->result->path;
                    $awsDispatchToken = $outcome->dispatch;
                    $writeResult = $outcome->result;
                } elseif ($activeLocalPartWriter !== null) {
                    $validated = null;
                    $expectedSize = $length ?? $srcObject->size;
                    $outcome = $activeLocalPartWriter->publish(
                        $bucket,
                        $bucketInfo->generation,
                        $uploadId,
                        $partNumber,
                        $stream,
                        validateBeforeArm: function (\OpsFour\S3Server\Checksum\ChecksumResult $checksums) use ($expectedSize, $offset, $srcObject, $sourceStorage, $crc64Capture, &$crc64Value, &$validated): void {
                            if ($checksums->size !== $expectedSize) {
                                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source payload does not match its metadata.');
                            }
                            $sourceMd5 = [];
                            if ($offset === null) {
                                if (preg_match('/\A"([0-9a-fA-F]{32})"\z/', $srcObject->etag, $sourceMd5) === 1
                                    && ! hash_equals(strtolower($sourceMd5[1]), $checksums->md5Hex)) {
                                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source payload does not match its metadata.');
                                }
                                $logicalStage = MultipartPartLogicalValidator::stagedResult($checksums);
                                if ($crc64Capture !== null) {
                                    $crc64Value = $crc64Capture->finalizeBase64();
                                    $logicalStage = self::withCrc64Nvme($logicalStage, $crc64Value);
                                }
                                $this->assertSourceChecksums($srcObject, $logicalStage, $sourceStorage);
                            }
                            $validated = $checksums;
                        },
                        knownPhysicalSize: $expectedSize,
                        capacity: $copyCapacity,
                        publicationCapacity: $publicationCapacity,
                    );
                    if ($outcome->result === null || $outcome->dispatchId === null || $validated === null) {
                        throw $outcome->error ?? new \OpsFour\S3Server\Exception\InternalErrorException('Local multipart part copy dispatch did not complete.');
                    }
                    MultipartPartLogicalValidator::assertPhysicalMatches($validated, $outcome->result);
                    $plannedPartPath = $outcome->path;
                    $dispatchId = $outcome->dispatchId;
                    $writeResult = $outcome->result;
                } elseif ($partIntentStore !== null && $plannedPartStorage !== null) {
                    $plannedPartPath = $plannedPartStorage->planMultipartPartPath(
                        $bucket,
                        $bucketInfo->generation,
                        $uploadId,
                        $partNumber,
                        bin2hex(random_bytes(16)),
                    );
                    $dispatchId = $this->startWriteIntent(
                        $partIntentStore,
                        $bucket,
                        $bucketInfo->generation,
                        $storageTier,
                        $uploadId,
                        $plannedPartPath,
                        $localFencedPart,
                    );
                    try {
                        $writeResult = $knownPlannedPartStorage !== null
                            ? $knownPlannedPartStorage->putPartAtPathKnownSize(
                                $bucket,
                                $key,
                                $uploadId,
                                $partNumber,
                                $stream,
                                $plannedPartPath,
                                $expectedSize,
                                $copyCapacity,
                            )
                            : $plannedPartStorage->putPartAtPath($bucket, $key, $uploadId, $partNumber, $stream, $plannedPartPath);
                    } catch (\Throwable $e) {
                        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                            $e,
                            fn() => $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath, ownerId: $ownerId),
                        );
                        throw $e;
                    }
                    if ($writeResult->path !== $plannedPartPath) {
                        $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath, ownerId: $ownerId);
                        throw new \OpsFour\S3Server\Exception\InternalErrorException('Multipart part copy did not use its planned path.');
                    }
                } else {
                    $writeResult = $knownUploadStorage !== null
                        ? $knownUploadStorage->putPartKnownSize(
                            $bucket,
                            $key,
                            $uploadId,
                            $partNumber,
                            $stream,
                            $expectedSize,
                            $copyCapacity,
                        )
                        : $uploadStorage->putPart($bucket, $key, $uploadId, $partNumber, $stream);
                }
            }
            if ($crc64Capture !== null) {
                try {
                    $crc64Value ??= $crc64Capture->finalizeBase64();
                } catch (\Throwable $error) {
                    \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                        $error,
                        fn() => $this->deleteUncommittedPart(
                            $uploadStorage,
                            $storageTier,
                            $bucket,
                            $writeResult->path,
                            $plannedPartPath !== null ? $uploadId : null,
                            $ownerId,
                        ),
                    );
                    throw $error;
                }
                $writeResult = new StorageWriteResult(
                    path: $writeResult->path,
                    size: $writeResult->size,
                    md5Hex: $writeResult->md5Hex,
                    crc32Base64: $writeResult->crc32Base64,
                    crc32cBase64: $writeResult->crc32cBase64,
                    sha1Base64: $writeResult->sha1Base64,
                    sha256Base64: $writeResult->sha256Base64,
                    crc64NvmeBase64: $crc64Value,
                );
            }
        } catch (\Amp\CancelledException $error) {
            $copyCancellation = $error;
            $this->retireAwsReservation($awsReservation);
            throw $error;
        } catch (\Throwable $error) {
            if ($awsReservation !== null && $awsDispatchToken === null) {
                $activeAwsObjectWriter?->retireBeforeDispatch($awsReservation);
            }
            throw $error;
        } finally {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                $copyCancellation,
                static function () use ($stream): void {
                    $stream?->close();
                },
                static function () use ($bufferedWorkLock): void {
                    $bufferedWorkLock?->release();
                },
                function () use (&$awsStage, $activeAwsObjectWriter): void {
                    if ($awsStage !== null) {
                        $activeAwsObjectWriter?->release($awsStage);
                        $awsStage = null;
                    }
                },
                function () use ($copyCapacity): void {
                    $this->releaseUnusedCapacity($copyCapacity);
                },
                function () use ($publicationCapacity): void {
                    $this->releaseUnusedCapacity($publicationCapacity);
                },
            );
        }

        $expectedSize = $length ?? $srcObject->size;
        try {
            MultipartPartLogicalValidator::assertValidStorageResult($writeResult);
            $sourceMd5 = [];
            if ($writeResult->size !== $expectedSize
                || ($offset === null
                    && preg_match('/\A"([0-9a-fA-F]{32})"\z/', $srcObject->etag, $sourceMd5) === 1
                    && ! hash_equals(strtolower($sourceMd5[1]), strtolower($writeResult->md5Hex)))) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source payload does not match its metadata.');
            }
            if ($offset === null) {
                $this->assertSourceChecksums($srcObject, $writeResult, $uploadStorage);
            }
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                function () use ($writeResult, $uploadStorage, $storageTier, $bucket, $plannedPartPath, $uploadId, $ownerId): void {
                    if ($writeResult->path !== '') {
                        $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $writeResult->path, $plannedPartPath !== null ? $uploadId : null, $ownerId);
                    }
                },
            );
            throw $e;
        }
        $request->setAttribute('s3.copySourceReadComplete', true);
        $request->setAttribute('s3.copySourceBytesTransferred', $expectedSize);

        $etag = '"' . strtolower($writeResult->md5Hex) . '"';
        $partChecksumCrc64Nvme = $initiatedChecksumAlgorithm === 'crc64nvme'
            ? ($writeResult->crc64NvmeBase64
                ?? throw new \OpsFour\S3Server\Exception\InternalErrorException('CRC64NVME part-copy calculation did not complete.'))
            : null;

        $previousPartPath = null;
        $commitCallbackCompleted = false;
        try {
            $this->metadata->transaction(function () use ($request, $ownerId, $bucketInfo, $srcBucketInfo, $srcObject, $srcVersionId, $bucket, $key, $uploadId, $partNumber, $etag, $writeResult, $partFormat, $uploadSseAlgorithm, $checksumContract, $partChecksumCrc64Nvme, $plannedPartPath, $dispatchId, $awsDispatchToken, $storageTier, &$previousPartPath, &$commitCallbackCompleted): void {
                \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId, $srcBucketInfo->ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                $this->assertPlainUploadAllowed($bucket, $uploadSseAlgorithm);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $srcBucketInfo);
                (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $srcObject, $srcVersionId !== null);
                $activeUpload = $this->metadata->getMultipartUpload($uploadId);
                if (
                    $activeUpload === null
                    || $activeUpload['bucket'] !== $bucket
                    || $activeUpload['key_name'] !== $key
                    || $activeUpload['owner_id'] !== $ownerId
                    || ($activeUpload['bucket_generation'] ?? '') !== $bucketInfo->generation
                ) {
                    throw new NoSuchUploadException();
                }
                if (($activeUpload['user_metadata']['__mpu-part-format'] ?? null) !== $partFormat) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload format changed during part copy.');
                }
                if (($activeUpload['user_metadata']['__sse-algorithm'] ?? null) !== $uploadSseAlgorithm) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload encryption changed during part copy.');
                }
                if (($activeUpload['storage_tier'] ?? null) !== $storageTier) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload storage tier changed during part copy.');
                }
                $activeChecksumContract = MultipartChecksumContract::fromStored($activeUpload['user_metadata']);
                if (($checksumContract === null) !== ($activeChecksumContract === null)
                    || ($checksumContract !== null && ! $checksumContract->sameAs($activeChecksumContract))) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart checksum contract changed during part copy.');
                }
                $activeObjectLockConfig = $this->assertObjectLockConfiguration(
                    $bucket,
                    $activeUpload['user_metadata'],
                );
                ObjectLockConfigurationSnapshot::assertCurrent($request, $activeObjectLockConfig);
                foreach ($this->metadata->getParts($uploadId) as $part) {
                    if ($part['part_number'] === $partNumber) {
                        $previousPartPath = $part['storage_path'];
                        break;
                    }
                }
                $this->quotas?->assertCanWritePart(
                    $ownerId,
                    $bucket,
                    $uploadId,
                    $partNumber,
                    $writeResult->size,
                );
                if ($partChecksumCrc64Nvme !== null
                    && $this->metadata instanceof \OpsFour\S3Server\Metadata\MultipartPartChecksumStore) {
                    $this->metadata->putPartWithChecksum(
                        $uploadId,
                        $partNumber,
                        $etag,
                        $writeResult->size,
                        $writeResult->path,
                        'crc64nvme',
                        $partChecksumCrc64Nvme,
                    );
                } else {
                    $this->metadata->putPart($uploadId, $partNumber, $etag, $writeResult->size, $writeResult->path);
                }
                if ($plannedPartPath !== null && ($dispatchId !== null
                    ? ! $this->consumeFencedIntent($bucket, $uploadId, $plannedPartPath, $dispatchId)
                    : ($awsDispatchToken !== null
                        ? ! $this->consumeAwsIntent($awsDispatchToken)
                        : ! $this->consumePlannedIntent($bucket, $uploadId, $plannedPartPath)))) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart part write intent expired.');
                }
                if ($previousPartPath !== null && $previousPartPath !== $writeResult->path) {
                    $this->metadata->enqueueStorageGarbage($bucket, $storageTier, $previousPartPath, $ownerId);
                }
                $commitCallbackCompleted = true;
            });
        } catch (\Throwable $e) {
            // Keep a potentially committed part when COMMIT's outcome is unknown.
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                function () use ($commitCallbackCompleted, $uploadId, $partNumber, $writeResult, $uploadStorage, $storageTier, $bucket, $plannedPartPath, $ownerId): void {
                    if (FailedPartCommitCleanup::shouldDelete($commitCallbackCompleted, $this->metadata, $uploadId, $partNumber, $writeResult->path)) {
                        $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $writeResult->path, $plannedPartPath !== null ? $uploadId : null, $ownerId);
                    }
                },
            );
            throw $e;
        }

        if ($previousPartPath !== null && $previousPartPath !== $writeResult->path
            && $this->awsObjectWriter?->isImmutableAttemptPath($storageTier, $previousPartPath) !== true) {
            try {
                \OpsFour\S3Server\Storage\StorageGarbageDiscard::afterDelete(
                    $this->metadata,
                    $uploadStorage,
                    $bucket,
                    $storageTier,
                    $previousPartPath,
                );
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // The committed exact-path job remains for the collector.
            }
        }

        $lastModified = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.000\Z');

        $xml = XmlResponseBuilder::copyPartResult($etag, $lastModified, $partChecksumCrc64Nvme);

        $responseHeaders = ['Content-Type' => 'application/xml'];
        if ($srcObject->versionId !== null || $srcVersioning !== '') {
            $responseHeaders['x-amz-copy-source-version-id'] = $srcObject->versionId ?? 'null';
        }
        if ($uploadSseAlgorithm === EncryptionRequestResolver::SSE_C) {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            $responseHeaders['x-amz-server-side-encryption-customer-key-MD5']
                = $upload['user_metadata']['__sse-customer-key-md5'];
        } elseif ($uploadSseAlgorithm === EncryptionRequestResolver::SSE_S3) {
            $responseHeaders['x-amz-server-side-encryption'] = 'AES256';
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
            body: $xml,
        );
    }

    /**
     * @param array<string, string> $uploadMetadata
     * @return array<string, mixed>|null
     */
    private function assertObjectLockConfiguration(string $bucket, array $uploadMetadata): ?array
    {
        $config = $this->metadata->getObjectLockConfig($bucket);
        $fingerprint = $uploadMetadata[ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY] ?? null;
        if (is_string($fingerprint)) {
            ObjectLockConfigurationSnapshot::assertFingerprint($fingerprint, $config);

            return $config;
        }
        if (isset($uploadMetadata['__object-lock-mode'])
            || isset($uploadMetadata['__object-lock-retain-until-date'])
            || isset($uploadMetadata['__object-lock-legal-hold'])
            || (($config['objectLockEnabled'] ?? null) === 'Enabled'
                && isset($config['rule']['defaultRetention']))) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Object Lock multipart upload must be reinitiated with checksum protection.',
            );
        }

        return $config;
    }

    private function consumeFencedIntent(string $bucket, string $uploadId, string $path, string $dispatchId): bool
    {
        return $this->metadata instanceof FencedMultipartPartWriteIntentStore
            && $this->metadata->consumeLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId);
    }

    private function consumePlannedIntent(string $bucket, string $uploadId, string $path): bool
    {
        return $this->metadata instanceof MultipartPartWriteIntentStore
            && $this->metadata->consumeMultipartPartWriteIntent($bucket, $uploadId, $path);
    }

    private function consumeAwsIntent(LocalObjectWriteDispatchToken $token): bool
    {
        return $this->metadata instanceof ObjectWriteIntentStore
            && $this->metadata->consumeArmedObjectWriteIntent($token->attemptId, $token->dispatchId);
    }

    private function retireAwsReservation(
        ?\OpsFour\S3Server\Storage\AwsS3ForegroundObjectReservation $reservation,
    ): void {
        if ($reservation !== null) {
            $this->awsObjectWriter?->retireBeforeDispatch($reservation);
        }
    }

    private function releaseUnusedCapacity(?StorageTempReservation $capacity): void
    {
        if ($capacity === null || ! $capacity->isOpen()) {
            return;
        }
        try {
            $this->tempBudget?->cleanup($capacity);
        } catch (\Throwable) {
            $capacity->close();
        }
    }

    private function deleteUncommittedPart(StorageBackend $storage, string $storageTier, string $bucket, string $path, ?string $confirmedUploadId = null, ?string $ownerId = null): void
    {
        if ($this->awsObjectWriter?->isImmutableAttemptPath($storageTier, $path) === true) {
            return;
        }
        if (MultipartPartWriteCapabilities::localFilesystem($storage)) {
            // A late create-only worker can still publish after an unknown ACK.
            // Keep its armed SQL tombstone for same-path sealing.
            return;
        }
        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $storage,
            $bucket,
            $storageTier,
            $path,
            'upload_part_copy_uncommitted_path',
            $ownerId,
        );
        if ($confirmedUploadId === null || ! $this->metadata instanceof MultipartPartWriteIntentStore) {
            return;
        }
        try {
            $stream = $storage->getObjectByPath($path);
            $stream->close();
        } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
            try {
                $this->metadata->consumeMultipartPartWriteIntent($bucket, $confirmedUploadId, $path);
            } catch (\Throwable) {
                // Keep the intent for the collector if metadata is unavailable.
            }
        }
    }

    private function copySourceStream(
        StorageBackend $storage,
        \OpsFour\S3Server\Dto\BucketInfo $bucket,
        \OpsFour\S3Server\Dto\ObjectInfo $source,
        string $key,
        string $path,
        ?int $offset,
        ?int $length,
        ?\OpsFour\S3Server\Storage\AwsS3ForegroundObjectReservation $reservation,
    ): \Amp\ByteStream\ReadableStream {
        $backend = $storage;
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        $stream = $backend instanceof AwsS3StorageBackend
            ? $backend->readCopySource($bucket, $source, $key, $path, $offset, $length)
            : $storage->getObjectByPath($path, $offset, $length);

        return $reservation === null
            ? $stream
            : ($this->awsObjectWriter?->renewingStream($reservation, $stream) ?? $stream);
    }

    private function startWriteIntent(
        MultipartPartWriteIntentStore $store,
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $path,
        bool $local,
    ): ?string {
        if (! $local) {
            $store->createMultipartPartWriteIntent($bucket, $bucketGeneration, $storageTier, $uploadId, $path);

            return null;
        }
        if (! $store instanceof FencedMultipartPartWriteIntentStore) {
            throw new \LogicException('Local multipart part fence is unavailable.');
        }
        $store->reserveLocalMultipartPartWriteIntent($bucket, $bucketGeneration, $storageTier, $uploadId, $path);
        $dispatchId = bin2hex(random_bytes(16));
        if (! $store->armLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId)) {
            $store->retireUndispatchedLocalMultipartPartWriteIntent($bucket, $uploadId, $path);
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Local multipart part dispatch could not be armed.');
        }

        return $dispatchId;
    }

    private function assertPlainUploadAllowed(string $bucket, ?string $uploadSseAlgorithm): void
    {
        $bucketEncryption = $this->metadata->getBucketEncryption($bucket);
        \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
        if ($uploadSseAlgorithm === null && $bucketEncryption !== null) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Bucket default encryption changed before multipart part copy.',
            );
        }
    }

    private function assertSourceChecksums(\OpsFour\S3Server\Dto\ObjectInfo $source, StorageWriteResult $write, StorageBackend $storage): void
    {
        $expected = [
            'crc32' => $source->systemMetadata['checksum-crc32'] ?? null,
            'crc32c' => $source->systemMetadata['checksum-crc32c'] ?? null,
            'crc64nvme' => $source->systemMetadata['checksum-crc64nvme'] ?? null,
            'sha1' => $source->systemMetadata['checksum-sha1'] ?? null,
            'sha256' => $source->systemMetadata['checksum-sha256'] ?? null,
        ];
        if ($expected === ['crc32' => null, 'crc32c' => null, 'crc64nvme' => null, 'sha1' => null, 'sha256' => null]) {
            return;
        }

        $actual = [
            'crc32' => $write->crc32Base64,
            'crc32c' => $write->crc32cBase64,
            'crc64nvme' => $write->crc64NvmeBase64,
            'sha1' => $write->sha1Base64,
            'sha256' => $write->sha256Base64,
        ];
        foreach ($expected as $algorithm => $value) {
            if ($value === null || $actual[$algorithm] !== null) {
                continue;
            }
            // Custom storage backends may report only size and MD5. Rehash
            // their bounded staged part before accepting the source bytes.
            $calculator = new ChecksumCalculator(
                isset($source->systemMetadata['checksum-crc64nvme']),
                cooperativeCrc64Nvme: true,
            );
            $readLimit = $source->size < PHP_INT_MAX ? $source->size + 1 : PHP_INT_MAX;
            $stream = new LimitedReadableStream($storage->getObjectByPath($write->path), $readLimit);
            $readCancellation = null;
            try {
                while (($chunk = $stream->read()) !== null) {
                    $calculator->update($chunk);
                }
            } catch (\Throwable $error) {
                if ($error instanceof \Amp\CancelledException) {
                    $readCancellation = $error;
                }
                throw $error;
            } finally {
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                    $readCancellation,
                    $stream->close(...),
                );
            }
            $checksums = $calculator->finalize();
            if ($checksums->size !== $source->size) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source size does not match its metadata.');
            }
            $actual = [
                'crc32' => $checksums->crc32Base64(),
                'crc32c' => $checksums->crc32cBase64(),
                'crc64nvme' => $checksums->crc64NvmeBase64(),
                'sha1' => $checksums->sha1Base64(),
                'sha256' => $checksums->sha256Base64(),
            ];
            break;
        }

        foreach ($expected as $algorithm => $value) {
            if ($value !== null && ($actual[$algorithm] === null || ! hash_equals($value, $actual[$algorithm]))) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source checksum does not match its payload.');
            }
        }
    }

    private static function withCrc64Nvme(StorageWriteResult $result, string $crc64Nvme): StorageWriteResult
    {
        return new StorageWriteResult(
            path: $result->path,
            size: $result->size,
            md5Hex: $result->md5Hex,
            crc32Base64: $result->crc32Base64,
            crc32cBase64: $result->crc32cBase64,
            sha1Base64: $result->sha1Base64,
            sha256Base64: $result->sha256Base64,
            crc64NvmeBase64: $crc64Nvme,
        );
    }

}
