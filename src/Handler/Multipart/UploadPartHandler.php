<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\AwsChunkedContentLength;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\ConfigWorkerKeyringCompatible;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartPartChecksumStore;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\ObjectLock\ObjectLockConfigurationSnapshot;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\LocalObjectWriteDispatchToken;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

final class UploadPartHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly bool $requireLocalFencedParts = false,
        private readonly ?LocalFencedMultipartPartWriter $localPartWriter = null,
        private readonly ?AwsS3ForegroundObjectWriter $awsObjectWriter = null,
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
                throw new \InvalidArgumentException('AWS multipart part writer must match the intent-capable default AWS tier.');
            }
        }
    }

    public function handleRequest(Request $request): Response
    {
        $expectedChecksumAlgorithm = MultipartPartLogicalValidator::expectedClientChecksumAlgorithm($request);
        $clientChecksums = MultipartPartLogicalValidator::clientChecksums($request);
        if ($expectedChecksumAlgorithm !== null && (
            ! $this->metadata instanceof MultipartPartChecksumStore
            || ! $this->metadata->supportsMultipartPartChecksums()
        )) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException(
                'Multipart part checksum persistence is unavailable.',
            );
        }
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

        // Verify upload exists and belongs to this owner.
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
        $objectLockConfig = $this->assertObjectLockConfiguration($bucket, $upload['user_metadata']);
        ObjectLockConfigurationSnapshot::assertCurrent($request, $objectLockConfig);
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
        $customerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $uploadSseAlgorithm === 'SSE-C',
            $upload['user_metadata']['__sse-customer-key-md5'] ?? null,
        );
        if ($uploadSseAlgorithm !== 'SSE-C' && $customerKey !== null) {
            throw new InvalidArgumentException('SSE-C headers are not valid for this multipart upload.');
        }

        $contentSha = $request->getHeader('x-amz-content-sha256');
        $isAwsChunked = in_array($contentSha, [
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
            'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
        ], true);
        $declaredLength = MultipartPartSize::declaredLength($isAwsChunked
            ? (string) AwsChunkedContentLength::fromRequest($request)
            : $request->getHeader('content-length'));
        // Read one extra byte to detect an oversized part even if its length
        // header is missing or understates the incoming body.
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
        $plannedPartPath = null;
        $dispatchId = null;
        $awsReservation = null;
        $awsDispatchToken = null;
        $awsStage = null;
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
            $body = MultipartPartSize::boundedBody($request->getBody(), min(MultipartPartSize::MAX_BYTES, $this->maxEncryptedObjectSize));
            $lock = null;
            $writeCancellation = null;
            try {
                $lock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
                $awsReservation = $activeAwsObjectWriter?->reserveMultipartPart(
                    $bucketInfo,
                    $key,
                    $ownerId,
                    $uploadId,
                    $partNumber,
                );
                if ($awsReservation !== null) {
                    $body = $activeAwsObjectWriter->renewingStream($awsReservation, $body);
                }
                [$plaintext, $checksums] = BufferedSsePayload::read($body, $this->maxEncryptedObjectSize);
                $clientChecksums = MultipartPartLogicalValidator::verifiedClientChecksums($request, $expectedChecksumAlgorithm);
                MultipartPartLogicalValidator::assertValid($clientChecksums, $checksums, $declaredLength);
                $encode = fn(): string => EncryptedMultipartPartCodec::encode(
                    $this->encryption,
                    $uploadSseAlgorithm,
                    $plaintext,
                    $customerKey,
                    $bucketInfo->generation,
                    $bucket,
                    $uploadId,
                    $partNumber,
                );
                $ciphertext = $awsReservation !== null
                    ? ($activeAwsObjectWriter ?? throw new \LogicException('AWS multipart writer disappeared.'))->transform($awsReservation, $encode)
                    : $encode();
                if ($awsReservation !== null && is_string($ciphertext)) {
                    $awsWriter = $activeAwsObjectWriter ?? throw new \LogicException('AWS multipart writer disappeared.');
                    $awsStage = $awsWriter->stage($awsReservation, new \Amp\ByteStream\ReadableBuffer($ciphertext));
                    if ($awsStage->result->size !== strlen($ciphertext)
                        || ! hash_equals(md5($ciphertext), strtolower($awsStage->result->md5Hex))) {
                        throw new \OpsFour\S3Server\Exception\InternalErrorException(
                            'Encrypted AWS multipart stage does not match its ciphertext.',
                        );
                    }
                    $outcome = $awsWriter->dispatchMultipartPart($awsReservation, $key, $uploadId, $partNumber, $awsStage);
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
                    );
                    if ($outcome->result === null || $outcome->dispatchId === null || $validatedPhysical === null) {
                        throw $outcome->error ?? new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted local multipart dispatch did not complete.');
                    }
                    MultipartPartLogicalValidator::assertPhysicalMatches($validatedPhysical, $outcome->result);
                    $plannedPartPath = $outcome->path;
                    $dispatchId = $outcome->dispatchId;
                    $physical = $outcome->result;
                } else {
                    if ($plannedPartStorage === null || $partIntentStore === null) {
                        throw new \LogicException('Multipart planned-part capability disappeared.');
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
                        $physical = $plannedPartStorage->putPartAtPath(
                            $bucket,
                            $key,
                            $uploadId,
                            $partNumber,
                            new \Amp\ByteStream\ReadableBuffer($ciphertext),
                            $plannedPartPath,
                        );
                    } catch (\Throwable $e) {
                        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                            $e,
                            fn() => $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath),
                        );
                        throw $e;
                    }
                }
                if ($physical->path !== $plannedPartPath
                    || $physical->size !== strlen($ciphertext)
                    || ! hash_equals(md5($ciphertext), strtolower($physical->md5Hex))) {
                    $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath, $uploadId);
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted multipart part write does not match its payload.');
                }
                $writeResult = new StorageWriteResult(
                    $physical->path,
                    $checksums->size,
                    $checksums->md5Hex,
                    $checksums->crc32Base64(),
                    $checksums->crc32cBase64(),
                    $checksums->sha1Base64(),
                    $checksums->sha256Base64(),
                );
            } catch (\Amp\CancelledException $error) {
                $writeCancellation = $error;
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                    $error,
                    fn() => self::retireAwsReservation($activeAwsObjectWriter, $awsReservation),
                );
                throw $error;
            } catch (\Throwable $error) {
                if ($awsReservation !== null && $awsDispatchToken === null) {
                    self::retireAwsReservation($activeAwsObjectWriter, $awsReservation);
                }
                throw $error;
            } finally {
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                    $writeCancellation,
                    $body->close(...),
                    function () use (&$awsStage, $activeAwsObjectWriter): void {
                        if ($awsStage !== null) {
                            $activeAwsObjectWriter?->release($awsStage);
                            $awsStage = null;
                        }
                    },
                    static function () use ($lock): void {
                        $lock?->release();
                    },
                );
            }
        } else {
            $body = MultipartPartSize::boundedBody($request->getBody());
            $writeCancellation = null;
            try {
                $awsReservation = $activeAwsObjectWriter?->reserveMultipartPart(
                    $bucketInfo,
                    $key,
                    $ownerId,
                    $uploadId,
                    $partNumber,
                );
                if ($awsReservation !== null) {
                    $awsWriter = $activeAwsObjectWriter ?? throw new \LogicException('AWS multipart writer disappeared.');
                    $awsStage = $awsWriter->stage(
                        $awsReservation,
                        $awsWriter->renewingStream($awsReservation, $body),
                    );
                    $clientChecksums = MultipartPartLogicalValidator::verifiedClientChecksums($request, $expectedChecksumAlgorithm);
                    MultipartPartLogicalValidator::assertValid($clientChecksums, $awsStage->result, $declaredLength);
                    $outcome = $awsWriter->dispatchMultipartPart($awsReservation, $key, $uploadId, $partNumber, $awsStage);
                    $plannedPartPath = $outcome->result->path;
                    $awsDispatchToken = $outcome->dispatch;
                    $writeResult = $outcome->result;
                } elseif ($activeLocalPartWriter !== null) {
                    $validated = null;
                    $outcome = $activeLocalPartWriter->publish(
                        $bucket,
                        $bucketInfo->generation,
                        $uploadId,
                        $partNumber,
                        $body,
                        validateBeforeArm: static function (\OpsFour\S3Server\Checksum\ChecksumResult $checksums) use ($request, $expectedChecksumAlgorithm, $declaredLength, &$clientChecksums, &$validated): void {
                            $clientChecksums = MultipartPartLogicalValidator::verifiedClientChecksums($request, $expectedChecksumAlgorithm);
                            MultipartPartLogicalValidator::assertValid($clientChecksums, $checksums, $declaredLength);
                            $validated = $checksums;
                        },
                    );
                    if ($outcome->result === null || $outcome->dispatchId === null || $validated === null) {
                        throw $outcome->error ?? new \OpsFour\S3Server\Exception\InternalErrorException('Local multipart part dispatch did not complete.');
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
                        $writeResult = $plannedPartStorage->putPartAtPath($bucket, $key, $uploadId, $partNumber, $body, $plannedPartPath);
                    } catch (\Throwable $e) {
                        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                            $e,
                            fn() => $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath),
                        );
                        throw $e;
                    }
                    if ($writeResult->path !== $plannedPartPath) {
                        $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $plannedPartPath);
                        throw new \OpsFour\S3Server\Exception\InternalErrorException('Multipart part write did not use its planned path.');
                    }
                } else {
                    $writeResult = $uploadStorage->putPart($bucket, $key, $uploadId, $partNumber, $body);
                }
            } catch (\Amp\CancelledException $error) {
                $writeCancellation = $error;
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                    $error,
                    fn() => self::retireAwsReservation($activeAwsObjectWriter, $awsReservation),
                );
                throw $error;
            } catch (\Throwable $error) {
                if ($awsReservation !== null && $awsDispatchToken === null) {
                    $activeAwsObjectWriter?->retireBeforeDispatch($awsReservation);
                }
                throw $error;
            } finally {
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                    $writeCancellation,
                    $body->close(...),
                    function () use (&$awsStage, $activeAwsObjectWriter): void {
                        if ($awsStage !== null) {
                            $activeAwsObjectWriter?->release($awsStage);
                            $awsStage = null;
                        }
                    },
                );
            }
        }
        try {
            MultipartPartLogicalValidator::assertValidStorageResult($writeResult);
            $clientChecksums = MultipartPartLogicalValidator::verifiedClientChecksums($request, $expectedChecksumAlgorithm);
            MultipartPartLogicalValidator::assertValid($clientChecksums, $writeResult, $declaredLength);
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                function () use ($writeResult, $uploadStorage, $storageTier, $bucket, $plannedPartPath, $uploadId): void {
                    if ($writeResult->path !== '') {
                        $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $writeResult->path, $plannedPartPath !== null ? $uploadId : null);
                    }
                },
            );
            throw $e;
        }

        $etag = '"' . strtolower($writeResult->md5Hex) . '"';

        $responseHeaders = ['ETag' => $etag];
        $checksumAlgorithm = array_key_first($clientChecksums);
        $checksumValue = $checksumAlgorithm === null ? null : $clientChecksums[$checksumAlgorithm];
        if ($checksumAlgorithm !== null && $checksumValue !== null) {
            $responseHeaders[MultipartPartLogicalValidator::headerForAlgorithm($checksumAlgorithm)] = $checksumValue;
        }
        if ($uploadSseAlgorithm === EncryptionRequestResolver::SSE_C) {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            $responseHeaders['x-amz-server-side-encryption-customer-key-MD5']
                = $upload['user_metadata']['__sse-customer-key-md5'];
        } elseif ($uploadSseAlgorithm === EncryptionRequestResolver::SSE_S3) {
            $responseHeaders['x-amz-server-side-encryption'] = 'AES256';
        }

        // Store part metadata.
        $previousPartPath = null;
        $commitCallbackCompleted = false;
        try {
            $this->metadata->transaction(function () use ($request, $ownerId, $bucketInfo, $bucket, $key, $uploadId, $partNumber, $etag, $writeResult, $partFormat, $uploadSseAlgorithm, $checksumAlgorithm, $checksumValue, $plannedPartPath, $dispatchId, $awsDispatchToken, $storageTier, &$previousPartPath, &$commitCallbackCompleted): void {
                \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                $this->assertPlainUploadAllowed($bucket, $uploadSseAlgorithm);
                (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
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
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload format changed during part write.');
                }
                if (($activeUpload['user_metadata']['__sse-algorithm'] ?? null) !== $uploadSseAlgorithm) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload encryption changed during part write.');
                }
                if (($activeUpload['storage_tier'] ?? null) !== $storageTier) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload storage tier changed during part write.');
                }
                $objectLockConfig = $this->assertObjectLockConfiguration(
                    $bucket,
                    $activeUpload['user_metadata'],
                );
                ObjectLockConfigurationSnapshot::assertCurrent($request, $objectLockConfig);
                foreach ($this->metadata->getParts($uploadId) as $part) {
                    if ($part['part_number'] === $partNumber) {
                        $previousPartPath = $part['storage_path'];
                        continue;
                    }
                    $otherAlgorithm = $part['checksum_algorithm'] ?? null;
                    if ($checksumAlgorithm !== null && $otherAlgorithm !== null && $otherAlgorithm !== $checksumAlgorithm) {
                        throw new \OpsFour\S3Server\Exception\InvalidRequestException(
                            'Multipart part checksum algorithms must be consistent.',
                        );
                    }
                }
                $this->quotas?->assertCanWritePart(
                    $ownerId,
                    $bucket,
                    $uploadId,
                    $partNumber,
                    $writeResult->size,
                );
                if ($checksumAlgorithm !== null) {
                    if (! $this->metadata instanceof MultipartPartChecksumStore
                        || ! $this->metadata->supportsMultipartPartChecksums()
                        || $checksumValue === null) {
                        throw new \OpsFour\S3Server\Exception\InternalErrorException('Verified multipart checksum cannot be persisted.');
                    }
                    $this->metadata->putPartWithChecksum(
                        $uploadId,
                        $partNumber,
                        $etag,
                        $writeResult->size,
                        $writeResult->path,
                        $checksumAlgorithm,
                        $checksumValue,
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
                    $this->metadata->enqueueStorageGarbage($bucket, $storageTier, $previousPartPath);
                }
                $commitCallbackCompleted = true;
            });
        } catch (\Throwable $e) {
            // A returned callback does not prove COMMIT failed: the database
            // may still publish this part after an acknowledgment failure.
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                function () use ($commitCallbackCompleted, $uploadId, $partNumber, $writeResult, $uploadStorage, $storageTier, $bucket, $plannedPartPath): void {
                    if (FailedPartCommitCleanup::shouldDelete($commitCallbackCompleted, $this->metadata, $uploadId, $partNumber, $writeResult->path)) {
                        $this->deleteUncommittedPart($uploadStorage, $storageTier, $bucket, $writeResult->path, $plannedPartPath !== null ? $uploadId : null);
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

        return new Response(
            status: 200,
            headers: $responseHeaders,
        );
    }

    /**
     * @param array<string, string> $uploadMetadata
     * @return array<string, mixed>|null
     */
    private function assertObjectLockConfiguration(string $bucket, array $uploadMetadata): ?array
    {
        $config = $this->metadata->getObjectLockConfig($bucket);
        $configurationFingerprint = $uploadMetadata[ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY] ?? null;
        if (is_string($configurationFingerprint)) {
            ObjectLockConfigurationSnapshot::assertFingerprint($configurationFingerprint, $config);

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

    private static function retireAwsReservation(
        ?AwsS3ForegroundObjectWriter $writer,
        ?\OpsFour\S3Server\Storage\AwsS3ForegroundObjectReservation $reservation,
    ): void {
        if ($writer !== null && $reservation !== null) {
            $writer->retireBeforeDispatch($reservation);
        }
    }

    private function deleteUncommittedPart(StorageBackend $storage, string $storageTier, string $bucket, string $path, ?string $confirmedUploadId = null): void
    {
        if ($this->awsObjectWriter?->isImmutableAttemptPath($storageTier, $path) === true) {
            return;
        }
        if (MultipartPartWriteCapabilities::localFilesystem($storage)) {
            // An accepted worker link can arrive after an ambiguous response.
            // The armed SQL row must survive until its path is sealed.
            return;
        }
        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $storage,
            $bucket,
            $storageTier,
            $path,
            'upload_part_uncommitted_path',
        );
        if ($confirmedUploadId === null || ! $this->metadata instanceof MultipartPartWriteIntentStore) {
            return;
        }
        try {
            $stream = $storage->getObjectByPath($path);
            $stream->close();
        } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
            // A completed planned write has no later worker writes. Once its
            // exact path is confirmed absent, this attempt needs no tombstone.
            try {
                $this->metadata->consumeMultipartPartWriteIntent($bucket, $confirmedUploadId, $path);
            } catch (\Throwable) {
                // Keep the intent for the collector if metadata is unavailable.
            }
        }
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
                'Bucket default encryption changed before multipart part write.',
            );
        }
    }
}
