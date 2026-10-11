<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\FilesystemException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\LimitedWorkerPool;
use Amp\Parallel\Worker\TaskFailureError;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Parallel\LocalEfsAbortMultipartTask;
use OpsFour\S3Server\Parallel\LocalEfsBucketNamespaceTask;
use OpsFour\S3Server\Parallel\LocalEfsChunkReadTask;
use OpsFour\S3Server\Parallel\LocalEfsDeletePathTask;
use OpsFour\S3Server\Parallel\LocalEfsTempCleanupTask;
use OpsFour\S3Server\Parallel\LocalEfsTempSizeTask;
use OpsFour\S3Server\Parallel\LocalEfsStoredPathCheckTask;
use OpsFour\S3Server\Parallel\LocalMultipartPublishTask;
use OpsFour\S3Server\Parallel\LocalMultipartAssemblyTask;
use OpsFour\S3Server\Parallel\LocalEncryptedMultipartCompletionTask;
use OpsFour\S3Server\Parallel\LocalMultipartSealTask;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;

/**
 * Filesystem-backed storage implementation using amphp/file for async I/O.
 *
 * Storage layout:
 *   Objects: {basePath}/{bucket}/{hash[0:2]}/{hash[2:4]}/{uuid}
 *   Temp writes: {basePath}/.tmp/{uuid}
 *   Multipart parts: {basePath}/.parts/{uploadId}/{partNumber}
 *
 * Write strategy: data is first written to a temp file, then atomically
 * moved to the final path. This prevents partial reads on concurrent access.
 */
final class FilesystemBackend implements ShutdownAwareStorageBackend, RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, BoundedCopyStorageBackend
{
    private const int IO_CHUNK_SIZE = 1_048_576;

    private readonly string $basePath;

    private readonly File\Filesystem $fileFilesystem;

    private readonly File\Filesystem $metadataFilesystem;

    /** @var list<ContextWorkerPool> */
    private array $ownedWorkerPools = [];

    private ?ContextWorkerPool $multipartFencePool = null;

    /** IPC uncertainty retains a slot until backend shutdown. */
    private int $multipartFenceAdmissions = 0;

    private ?WorkerPool $efsDeletePool;

    /** Ambiguous delete-pool submissions retain capacity until node stop. */
    private int $efsDeleteAdmissions = 0;

    private ?ContextWorkerPool $efsNamespacePool = null;

    private ?WorkerPool $efsReadPool;

    /** Ambiguous worker outcomes keep their slots until the node is stopped. */
    private int $efsNamespaceAdmissions = 0;

    /** Uncertain read-task outcomes retain their slots until node stop. */
    private int $efsReadAdmissions = 0;

    private bool $shuttingDown = false;

    private readonly ?EfsWorkerEvidenceScope $efsWorkerScope;

    /**
     * @param  string  $basePath  Root directory for all storage operations.
     *                            Must be an absolute path. Trailing slash is stripped.
     */
    public function __construct(
        string $basePath,
        ?File\Filesystem $fileFilesystem = null,
        ?File\Filesystem $metadataFilesystem = null,
        private readonly ?EfsHostEvidenceReader $efsEvidence = null,
        ?WorkerPool $efsDeletePool = null,
        ?WorkerPool $efsReadPool = null,
        private readonly bool $isolatedEfsRead = false,
        private readonly bool $requireIsolatedReadSession = false,
    ) {
        if ($efsEvidence !== null && $basePath !== $efsEvidence->storageRoot()) {
            throw new \InvalidArgumentException('Guarded filesystem root differs from EFS evidence.');
        }
        $root = rtrim($basePath, '/');
        if ($root === '' || ! str_starts_with($root, '/') || str_contains($basePath, '//')) {
            throw new \InvalidArgumentException('Filesystem storage root must be an absolute directory below /.');
        }
        $this->basePath = $root;
        if (($efsDeletePool !== null || $efsReadPool !== null || $isolatedEfsRead) && $efsEvidence === null) {
            throw new \InvalidArgumentException('An EFS worker pool requires exact host evidence.');
        }
        if ($efsReadPool !== null && ! $isolatedEfsRead) {
            throw new \InvalidArgumentException('An EFS read pool requires explicit isolated-read mode.');
        }
        if ($requireIsolatedReadSession && (! $isolatedEfsRead
            || ! $efsReadPool instanceof LimitedWorkerPool
            || $efsReadPool->getWorkerLimit() !== 2)) {
            throw new \InvalidArgumentException('Qualified EFS reads require an injected two-worker isolated read pool.');
        }
        $this->efsWorkerScope = $efsEvidence?->workerScope();
        $this->efsDeletePool = $efsDeletePool;
        $this->efsReadPool = $efsReadPool;
        if ($fileFilesystem === null) {
            $dataPool = new ContextWorkerPool(8);
            $this->ownedWorkerPools[] = $dataPool;
            $fileFilesystem = new File\Filesystem(new ParallelFilesystemDriver($dataPool));
        }
        $this->fileFilesystem = $fileFilesystem;

        if ($metadataFilesystem === null) {
            $metadataPool = new ContextWorkerPool(8);
            $this->ownedWorkerPools[] = $metadataPool;
            $metadataFilesystem = new File\Filesystem(new ParallelFilesystemDriver($metadataPool));
        }
        $this->metadataFilesystem = $metadataFilesystem;
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;
        foreach ($this->ownedWorkerPools as $pool) {
            $pool->shutdown();
        }
        $this->ownedWorkerPools = [];
        $this->multipartFencePool = null;
        $this->multipartFenceAdmissions = 0;
        $this->efsDeleteAdmissions = 0;
        $this->efsDeletePool = null;
        $this->efsNamespacePool = null;
        $this->efsReadPool = null;
    }

    /** Recheck the exact backend's optional host evidence before SQL cleanup acknowledgement. */
    public function assertHostEvidenceFresh(): void
    {
        $this->efsEvidence?->assertFresh();
    }

    /** @internal A candidate binder must reject an unguarded or differently guarded backend. */
    public function hostEvidenceReader(): ?EfsHostEvidenceReader
    {
        return $this->efsEvidence;
    }

    /** Prove that this backend can only read through its bounded EFS process pool. */
    public function assertQualifiedEfsReadConfiguration(?ContextWorkerPool $expectedPool = null): void
    {
        if ($this->shuttingDown || $this->efsEvidence === null
            || ! $this->isolatedEfsRead || ! $this->requireIsolatedReadSession
            || ! $this->efsReadPool instanceof ContextWorkerPool
            || $this->efsReadPool->getWorkerLimit() !== 2
            || ! $this->efsReadPool->isRunning()
            || ($expectedPool !== null && $this->efsReadPool !== $expectedPool)) {
            throw new \RuntimeException('EFS backend lacks its exact qualified two-worker read pool.');
        }
    }

    public function __destruct()
    {
        try {
            $this->shutdown();
        } catch (\Throwable) {
            // Process shutdown remains best-effort if the event loop is gone.
        }
    }

    /**
     * {@inheritDoc}
     *
     * Writes the body stream to a temp file, computing MD5 incrementally,
     * then atomically moves it to a unique path sharded by the key hash.
     */
    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        $this->assertLegacyWriteAllowed();

        return $this->writeObjectAtPath($body, StoragePath::forObject($this->basePath, $bucket, $key));
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return StoragePath::forMultipartCompletion($this->basePath, $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject($this->basePath, $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        $this->assertLegacyWriteAllowed();
        StoragePath::assertObjectWritePath($this->basePath, $path, $bucket, $key);

        return $this->writeObjectAtPath(
            $body,
            $path,
            str_starts_with($path, rtrim($this->basePath, '/') . '/.assemblies/'),
        );
    }

    private function writeObjectAtPath(ReadableStream $body, string $finalPath, bool $fencedMultipart = false): StorageWriteResult
    {
        $this->efsEvidence?->assertFresh();
        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        try {
            return $this->writeLeasedObjectAtPath($body, $finalPath, $tempPath, $fencedMultipart);
        } finally {
            $tempLease->release();
        }
    }

    private function writeLeasedObjectAtPath(ReadableStream $body, string $finalPath, string $tempPath, bool $fencedMultipart): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        // Write to temp file, computing checksums as data streams in
        $tempFile = null;
        try {
            $tempFile = $this->fileFilesystem->openFile($tempPath, 'w');
            $this->copyStreamToFile($body, $tempFile, static function (string $chunk) use ($calculator, &$size): void {
                $calculator->update($chunk);
                $size += strlen($chunk);
            });

            self::closeAndRelease($tempFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to write object to filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // Create target directory and atomically move temp to final path
        $targetDir = dirname($finalPath);

        try {
            $this->ensureDirectory($targetDir);
            if ($fencedMultipart) {
                $this->publishFencedMultipartPath($tempPath, $finalPath);
                $this->safeDelete($tempPath);
            } else {
                $this->atomicMove($tempPath, $finalPath);
            }
        } catch (CancelledException $e) {
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize object in filesystem backend.', $e);
        }

        return new StorageWriteResult(
            path: $finalPath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        $this->assertRequiredEfsActive();
        $this->efsEvidence?->assertFresh();
        $storagePath = $this->storedPathForAccess($storagePath);
        if ($this->efsEvidence !== null && $this->isolatedEfsRead) {
            return $this->openEfsReadSession($storagePath, $offset, $length);
        }
        if (! $this->assertStoredPathConfined($storagePath)) {
            throw new NoSuchKeyException();
        }

        $this->efsEvidence?->assertFresh();
        $file = $this->fileFilesystem->openFile($storagePath, 'r');

        try {
            $this->efsEvidence?->assertFresh();
            if ($offset !== null && $offset > 0) {
                $file->seek($offset);
            }
            $this->efsEvidence?->assertFresh();
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($file);
            throw $e;
        } catch (\Throwable $e) {
            if ($this->efsEvidence !== null) {
                // The legacy test profile never serves production EFS. After
                // host loss, an explicit close may block on hard NFS.
                $this->efsEvidence->assertFresh();
            }
            $file->close();
            throw new InternalErrorException('Failed to seek in object file.', $e);
        }

        $stream = new FileReadableStream($file, self::IO_CHUNK_SIZE);
        $readStream = $this->efsEvidence !== null
            ? new EfsGuardedReadableStream($stream, $this->efsEvidence) : $stream;

        if ($length !== null) {
            return new LimitedReadableStream($readStream, $length);
        }

        return $readStream;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        $this->efsEvidence?->assertFresh();
        $storagePath = $this->storedPathForAccess($storagePath);
        FlysystemPathScope::assertDeletePath(substr($storagePath, strlen($this->basePath) + 1), $bucket);

        if ($this->efsEvidence !== null) {
            $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded EFS delete has no worker scope.');
            // A failed submission or worker acknowledgement is ambiguous; the
            // caller must keep its exact-path SQL cleanup job for retry.
            $this->runEfsDeleteTask(fn(): null => $this->efsDeletePool()->submit(
                new LocalEfsDeletePathTask($scope, $storagePath, $bucket),
            )->getFuture()->await());
            return;
        }

        if (! $this->assertStoredPathConfined($storagePath)) {
            return; // Idempotent: silently succeed if already gone
        }

        try {
            $this->metadataFilesystem->deleteFile($storagePath);
        } catch (FilesystemException) {
            // TOCTOU: file was deleted between exists() and deleteFile() — idempotent success.
            if ($this->pathExists($storagePath)) {
                throw new InternalErrorException('Failed to delete object file.');
            }
            return;
        }

        // Clean up empty parent directories without removing a shared root.
        // Multipart garbage paths live outside the bucket directory.
        $bucketDir = $this->basePath . '/' . $bucket;
        $partsDir = $this->basePath . '/.parts';
        $assembliesDir = $this->basePath . '/.assemblies';
        if (str_starts_with($storagePath, $bucketDir . '/')) {
            $root = $bucketDir;
        } elseif (str_starts_with($storagePath, $partsDir . '/')) {
            // A collector may install a same-path seal after this delete.
            // Keep the namespace directory even if it looks empty now.
            return;
        } elseif (str_starts_with($storagePath, $assembliesDir . '/')) {
            return;
        } else {
            return;
        }
        $dir = dirname($storagePath);

        while ($dir !== $root && str_starts_with($dir, $root . '/')) {
            if (! $this->metadataFilesystem->isDirectory($dir)) {
                break;
            }

            $entries = $this->metadataFilesystem->listFiles($dir);

            if (count($entries) > 0) {
                break;
            }

            try {
                $this->metadataFilesystem->deleteDirectory($dir);
            } catch (FilesystemException) {
                break; // Concurrent write made directory non-empty — stop climbing.
            }
            $dir = dirname($dir);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function createBucket(string $bucket): void
    {
        StoragePath::assertBucketPathSegment($bucket);
        if ($this->efsEvidence !== null) {
            $this->runEfsNamespaceTask($bucket, true);
            return;
        }
        $bucketDir = $this->basePath . '/' . $bucket;

        $this->ensureDirectory($bucketDir);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteBucket(string $bucket): void
    {
        throw new \LogicException('Name-only filesystem bucket deletion is unsafe after bucket-name reuse.');
    }

    /**
     * {@inheritDoc}
     */
    public function bucketExists(string $bucket): bool
    {
        StoragePath::assertBucketPathSegment($bucket);
        if ($this->efsEvidence !== null) {
            return $this->runEfsNamespaceTask($bucket, false);
        }
        return $this->metadataFilesystem->isDirectory($this->basePath . '/' . $bucket);
    }

    // ---------------------------------------------------------------
    // Multipart upload operations (not yet in the interface)
    // ---------------------------------------------------------------

    /**
     * Write a single part of a multipart upload to disk.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key (for logging/context only).
     * @param  string  $uploadId  Multipart upload identifier.
     * @param  int  $partNumber  Part number (1-10000).
     * @param  ReadableStream  $data  Part data stream.
     * @return StorageWriteResult Metadata about the written part.
     */
    public function putPart(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
    ): StorageWriteResult {
        $this->assertLegacyWriteAllowed();
        $partPath = StoragePath::forPart($this->basePath, $uploadId, $partNumber);

        return $this->writePartAtPath($bucket, $key, $uploadId, $partNumber, $data, $partPath);
    }

    public function planMultipartPartPath(string $bucket, string $bucketGeneration, string $uploadId, int $partNumber, string $attemptId): string
    {
        return StoragePath::forPlannedPart($this->basePath, $bucket, $bucketGeneration, $uploadId, $partNumber, $attemptId);
    }

    public function putPartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path): StorageWriteResult
    {
        $this->assertLegacyWriteAllowed();
        StoragePath::assertPlannedPartPath($this->basePath, $path, $uploadId, $partNumber);

        return $this->writePartAtPath($bucket, $key, $uploadId, $partNumber, $data, $path, true);
    }

    private function writePartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $partPath, bool $planned = false): StorageWriteResult
    {
        $this->efsEvidence?->assertFresh();
        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure directories exist
        $partDir = dirname($partPath);
        $tempDir = dirname($tempPath);

        $this->ensureDirectory($tempDir);
        $this->ensureDirectory($partDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        $calculator = new ChecksumCalculator();
        $size = 0;

        // Write to temp first
        $tempFile = null;
        try {
            $tempFile = $this->fileFilesystem->openFile($tempPath, 'w');
            $this->copyStreamToFile($data, $tempFile, static function (string $chunk) use ($calculator, &$size): void {
                $calculator->update($chunk);
                $size += strlen($chunk);
            });

            self::closeAndRelease($tempFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to write multipart part to filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // Every attempt receives a unique path. Metadata atomically selects the
        // winning attempt, so a failed replacement cannot destroy the previous part.
        try {
            if ($planned) {
                $this->publishFencedMultipartPath($tempPath, $partPath);
                $this->safeDelete($tempPath);
            } else {
                $this->atomicMove($tempPath, $partPath);
            }
        } catch (CancelledException $e) {
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize multipart part in filesystem backend.', $e);
        }

        $tempLease->release();

        return new StorageWriteResult(
            path: $partPath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    /**
     * Assemble all parts of a multipart upload into a single object file.
     *
     * Parts are concatenated in the order specified by the $parts array.
     * After assembly, the individual part files are cleaned up.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @param  string  $uploadId  Upload identifier.
     * @param  array<int, array{partNumber: int, etag: string, storagePath: string}>  $parts  Ordered committed parts.
     * @return StorageWriteResult Metadata about the assembled object.
     */
    public function assembleMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
    ): StorageWriteResult {
        $this->assertLegacyWriteAllowed();

        return $this->assembleAtPath($uploadId, $parts, StoragePath::forObject($this->basePath, $bucket, $key));
    }

    public function assembleMultipartUploadAtPath(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
        string $path,
    ): StorageWriteResult {
        $this->assertLegacyWriteAllowed();
        $this->assertCompletionPath($path);
        StoragePath::assertObjectWritePath($this->basePath, $path, $bucket, $key);

        return $this->assembleAtPath($uploadId, $parts, $path);
    }

    /**
     * @internal The caller must first durably arm this exact completion
     * attempt. An IPC failure is ambiguous: retain the SQL intent for sealing.
     *
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     */
    public function assembleMultipartUploadFencedAtPath(
        string $bucket,
        string $key,
        string $generation,
        string $attemptId,
        string $uploadId,
        array $parts,
        string $path,
    ): StorageWriteResult {
        $this->efsEvidence?->assertFresh();
        $planned = StoragePath::forMultipartCompletion($this->basePath, $bucket, $key, $generation, $attemptId);
        if ($path !== $planned['assembly']) {
            throw new \InvalidArgumentException('Fenced multipart assembly path differs from its armed attempt.');
        }
        // Keep the SQL-selected paths untouched for the handler's exact-part
        // transaction checks; only the worker's physical paths are canonical.
        foreach ($parts as $index => $part) {
            $parts[$index]['storagePath'] = $this->storedPathForAccess($part['storagePath']);
        }
        $result = $this->runMultipartFenceTask(fn(): StorageWriteResult => $this->multipartFencePool()->submit(
            new LocalMultipartAssemblyTask(
                $this->basePath,
                $bucket,
                $key,
                $generation,
                $attemptId,
                $uploadId,
                $parts,
                $path,
                $this->efsWorkerScope,
            ),
        )->getFuture()->await());

        return $result;
    }

    /**
     * @internal The exact SQL attempt must be armed before calling this worker.
     * An ambiguous result must retain the intent for permanent path sealing.
     *
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     */
    public function completeEncryptedMultipartUploadFencedAtPath(
        string $bucket,
        string $key,
        string $generation,
        string $attemptId,
        string $uploadId,
        array $parts,
        string $path,
        string $mode,
        int $maxPlaintextBytes,
        ?string $customerKey,
        string $expectedKeyringFingerprint,
    ): LocalEncryptedMultipartCompletionResult {
        $this->assertRequiredEfsActive();
        $this->efsEvidence?->assertFresh();
        $scope = $this->efsWorkerScope
            ?? throw new InternalErrorException('Encrypted EFS completion requires a qualified worker evidence scope.');
        $planned = StoragePath::forMultipartCompletion($this->basePath, $bucket, $key, $generation, $attemptId);
        if ($path !== $planned['final'] || $maxPlaintextBytes < 1
            || ($mode === EncryptionRequestResolver::SSE_S3
                && ($customerKey !== null || preg_match('/^[a-f0-9]{64}$/D', $expectedKeyringFingerprint) !== 1))
            || ($mode === EncryptionRequestResolver::SSE_C
                && ($customerKey === null || strlen($customerKey) !== 32 || $expectedKeyringFingerprint !== ''))
            || ! in_array($mode, [EncryptionRequestResolver::SSE_S3, EncryptionRequestResolver::SSE_C], true)) {
            throw new \InvalidArgumentException('Encrypted EFS completion differs from its armed attempt or encryption mode.');
        }

        return $this->runMultipartFenceTask(fn(): LocalEncryptedMultipartCompletionResult => $this->multipartFencePool()->submit(
            new LocalEncryptedMultipartCompletionTask(
                $scope,
                $bucket,
                $key,
                $generation,
                $attemptId,
                $uploadId,
                $parts,
                $path,
                $mode,
                $maxPlaintextBytes,
                $customerKey,
                $expectedKeyringFingerprint,
            ),
        )->getFuture()->await());
    }

    /** @param array<int, array{partNumber: int, etag: string, storagePath: string}> $parts */
    private function assembleAtPath(string $uploadId, array $parts, string $finalPath): StorageWriteResult
    {
        $this->efsEvidence?->assertFresh();
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $partPrefix = rtrim($this->basePath, '/') . '/.parts/' . $uploadId . '/';
        foreach ($parts as $index => $part) {
            $partPath = $this->storedPathForAccess($part['storagePath']);
            if (! str_starts_with($partPath, $partPrefix)
                || ! preg_match('/^' . $part['partNumber'] . '-[a-f0-9]{32}(?:-[a-f0-9]{32})?$/D', substr($partPath, strlen($partPrefix)))) {
                throw new \InvalidArgumentException('Multipart part path does not match the upload.');
            }

            if (! $this->assertStoredPathConfined($partPath)) {
                throw new InternalErrorException('Multipart part is unavailable in filesystem backend.');
            }
            $parts[$index]['storagePath'] = $partPath;
        }

        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        $calculator = new ChecksumCalculator();
        $size = 0;
        $partMd5s = '';

        // Concatenate all parts into the temp file
        $outFile = null;
        try {
            $outFile = $this->fileFilesystem->openFile($tempPath, 'w');
            foreach ($parts as $part) {
                $this->efsEvidence?->assertFresh();
                $partNumber = $part['partNumber'];
                $partPath = $part['storagePath'];

                if (! $this->metadataFilesystem->exists($partPath)) {
                    throw new InternalErrorException('Multipart part is unavailable in filesystem backend.');
                }

                $this->efsEvidence?->assertFresh();
                $partFile = $this->fileFilesystem->openFile($partPath, 'r');
                $partStream = new FileReadableStream($partFile, self::IO_CHUNK_SIZE);
                $readStream = $this->efsEvidence !== null
                    ? new EfsGuardedReadableStream($partStream, $this->efsEvidence) : $partStream;
                $partMd5Context = hash_init('md5');

                $this->copyStreamToFile($readStream, $outFile, static function (string $chunk) use (
                    $calculator,
                    $partMd5Context,
                    &$size,
                ): void {
                    $calculator->update($chunk);
                    hash_update($partMd5Context, $chunk);
                    $size += strlen($chunk);
                });

                self::closeAndRelease($partFile);

                // Collect raw MD5 bytes of each part for the multipart ETag
                $partMd5 = hash_final($partMd5Context);
                if (strtolower(trim($part['etag'], '"')) !== $partMd5) {
                    throw new InternalErrorException('Multipart part failed physical checksum verification.');
                }

                $partMd5s .= hex2bin($partMd5);
            }

            self::closeAndRelease($outFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($outFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($outFile);
            $this->safeDelete($tempPath);

            if ($e instanceof InternalErrorException) {
                throw $e;
            }

            throw new InternalErrorException('Failed to assemble multipart upload in filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // The multipart ETag is: MD5(concat(part_md5_bytes))-partCount
        $compositeMd5 = hash('md5', $partMd5s);

        // Move assembled file to final path
        $targetDir = dirname($finalPath);

        try {
            $this->ensureDirectory($targetDir);
            $this->publishFencedMultipartPath($tempPath, $finalPath);
            $this->safeDelete($tempPath);
        } catch (CancelledException $e) {
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize assembled object in filesystem backend.', $e);
        }

        $tempLease->release();

        return new StorageWriteResult(
            path: $finalPath,
            size: $size,
            md5Hex: $compositeMd5 . '-' . count($parts),
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    /**
     * Abort a multipart upload by deleting all associated part files.
     *
     * @param  string  $bucket  Bucket name (for context only).
     * @param  string  $key  Object key (for context only).
     * @param  string  $uploadId  Upload identifier whose parts to delete.
     */
    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->efsEvidence?->assertFresh();
        StoragePath::assertSafeUploadIdSegment($uploadId);
        if ($this->efsEvidence !== null) {
            $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded EFS abort has no worker scope.');
            $this->runEfsDeleteTask(fn(): null => $this->efsDeletePool()->submit(
                new LocalEfsAbortMultipartTask($scope, $uploadId),
            )->getFuture()->await());
            return;
        }
        $this->cleanupParts($uploadId);
    }

    /**
     * Copy an object from one storage path to a new location.
     *
     * The copy is performed via temp file + atomic move, same as putObject.
     * MD5 is computed on the copied data.
     *
     * @param  string  $srcPath  Absolute path of the source object.
     * @param  string  $dstBucket  Destination bucket name.
     * @param  string  $dstKey  Destination object key.
     * @return StorageWriteResult Metadata about the copied object.
     */
    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        $this->efsEvidence?->assertFresh();
        if ($this->requireIsolatedReadSession) {
            throw new InternalErrorException('Qualified EFS CopyObject requires an isolated read and fenced write path.');
        }
        $srcPath = $this->storedPathForAccess($srcPath);
        if (! $this->assertStoredPathConfined($srcPath)) {
            throw new NoSuchKeyException();
        }

        $tempPath = StoragePath::forTemp($this->basePath);
        $finalPath = StoragePath::forObject($this->basePath, $dstBucket, $dstKey);

        // Ensure temp directory
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        $calculator = new ChecksumCalculator();
        $size = 0;

        $srcFile = null;
        $dstFile = null;
        try {
            $this->efsEvidence?->assertFresh();
            $srcFile = $this->fileFilesystem->openFile($srcPath, 'r');
            $srcStream = new FileReadableStream($srcFile, self::IO_CHUNK_SIZE);
            $readStream = $this->efsEvidence !== null
                ? new EfsGuardedReadableStream($srcStream, $this->efsEvidence) : $srcStream;
            $dstFile = $this->fileFilesystem->openFile($tempPath, 'w');
            $this->copyStreamToFile($readStream, $dstFile, static function (string $chunk) use ($calculator, &$size): void {
                $calculator->update($chunk);
                $size += strlen($chunk);
            });

            self::closeAndRelease($srcFile);
            self::closeAndRelease($dstFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($srcFile);
            self::safeCloseAndRelease($dstFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($srcFile);
            self::safeCloseAndRelease($dstFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to copy object in filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // Move to final destination
        $targetDir = dirname($finalPath);

        try {
            $this->efsEvidence?->assertFresh();
            $this->ensureDirectory($targetDir);
            $this->efsEvidence?->assertFresh();
            $this->atomicMove($tempPath, $finalPath);
        } catch (CancelledException $e) {
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize copied object in filesystem backend.', $e);
        }

        $tempLease->release();

        return new StorageWriteResult(
            path: $finalPath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Maximum copied bytes must be non-negative.');
        }
        $this->efsEvidence?->assertFresh();
        if ($this->requireIsolatedReadSession) {
            throw new InternalErrorException('Qualified EFS CopyObject requires a fenced destination writer.');
        }

        $source = new LimitedReadableStream($this->getObjectByPath($srcPath), $maxBytes);
        try {
            return $this->putObject($dstBucket, $dstKey, $source);
        } finally {
            $source->close();
        }
    }

    /**
     * Get the base path for this storage backend.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * @param callable(string): void $onChunk
     */
    private function copyStreamToFile(ReadableStream $source, File\File $target, callable $onChunk): void
    {
        $buffer = '';

        while (($chunk = $source->read()) !== null) {
            $onChunk($chunk);
            $buffer .= $chunk;

            if (strlen($buffer) >= self::IO_CHUNK_SIZE) {
                $this->efsEvidence?->assertFresh();
                $target->write($buffer);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $this->efsEvidence?->assertFresh();
            $target->write($buffer);
        }
    }

    private function assertTempFileSize(string $path, int $expectedSize): void
    {
        $this->efsEvidence?->assertFresh();
        if ($this->efsEvidence !== null) {
            $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded EFS staging has no worker scope.');
            $this->runEfsDeleteTask(fn(): null => $this->efsDeletePool()->submit(
                new LocalEfsTempSizeTask($scope, $path, $expectedSize),
            )->getFuture()->await());
            return;
        }
        // Amp File::write() returns void, and some drivers can accept a short
        // local write without throwing. A fresh stat avoids cached file sizes.
        clearstatcache(true, $path);
        if (@filesize($path) !== $expectedSize) {
            throw new InternalErrorException('Filesystem staging file is incomplete.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if ($this->metadataFilesystem->isDirectory($path)) {
            return;
        }

        try {
            $this->metadataFilesystem->createDirectoryRecursively($path, 0700);
        } catch (FilesystemException $e) {
            if ($this->isDirectoryPath($path)) {
                return;
            }

            throw new InternalErrorException('Failed to create filesystem storage directory.', $e);
        }
    }

    private function atomicMove(string $from, string $to): void
    {
        try {
            $this->metadataFilesystem->move($from, $to);
        } catch (FilesystemException $e) {
            throw new InternalErrorException('Failed to move filesystem storage file.', $e);
        }
    }

    /**
     * Publish only after the caller has committed a one-shot multipart SQL
     * dispatch. A worker may finish after an ambiguous response, so callers
     * must retain the intent and let the collector seal the exact path.
     */
    private function publishFencedMultipartPath(string $tempPath, string $targetPath): void
    {
        $this->runMultipartFenceTask(fn(): null => $this->multipartFencePool()->submit(new LocalMultipartPublishTask(
            $this->basePath,
            $tempPath,
            $targetPath,
            $this->efsWorkerScope,
        ))->getFuture()->await());
    }

    /** Physical half of a committed multipart collector claim. */
    public function sealFencedMultipartPath(string $path, string $attemptId): void
    {
        $this->runMultipartFenceTask(fn(): null => $this->multipartFencePool()->submit(new LocalMultipartSealTask(
            $this->basePath,
            $path,
            $attemptId,
            $this->efsWorkerScope,
        ))->getFuture()->await());
    }

    /**
     * Reserve before submitting. A failed submission, lost IPC response or
     * stale post-task evidence may leave a hard-NFS child alive. Only a
     * successful task result or definite task failure with fresh evidence
     * returns capacity. Collector retries after restart handle retained slots.
     *
     * @template TResult
     * @param \Closure(): TResult $submit
     * @return TResult
     */
    private function runMultipartFenceTask(\Closure $submit): mixed
    {
        $this->assertRequiredEfsActive();
        $this->efsEvidence?->assertFresh();
        if ($this->multipartFenceAdmissions >= 2) {
            throw new SlowDownException('Local multipart fence worker capacity is exhausted.');
        }
        $this->multipartFenceAdmissions++;
        try {
            $result = $submit();
        } catch (TaskFailureException|TaskFailureError $error) {
            $this->efsEvidence?->assertFresh();
            $this->multipartFenceAdmissions--;
            throw $error;
        }
        $this->efsEvidence?->assertFresh();
        $this->multipartFenceAdmissions--;

        return $result;
    }

    private function multipartFencePool(): ContextWorkerPool
    {
        $this->assertRequiredEfsActive();
        if ($this->multipartFencePool === null) {
            $this->multipartFencePool = new ContextWorkerPool(2);
            $this->ownedWorkerPools[] = $this->multipartFencePool;
        }

        return $this->multipartFencePool;
    }

    private function efsDeletePool(): WorkerPool
    {
        $this->assertRequiredEfsActive();
        if ($this->efsDeletePool === null) {
            $pool = new ContextWorkerPool(2);
            $this->efsDeletePool = $pool;
            $this->ownedWorkerPools[] = $pool;
        }

        return $this->efsDeletePool;
    }

    /**
     * One shared bound covers deletes, aborts, temp checks, cleanup and path
     * checks. Pool checkout can suspend; reserve before it to avoid a queued
     * third hard-NFS task. Only a definitive worker response plus fresh host
     * evidence permits reuse. IPC or watcher uncertainty keeps the slot.
     *
     * @template TResult
     * @param \Closure(): TResult $submit
     * @return TResult
     */
    private function runEfsDeleteTask(\Closure $submit): mixed
    {
        $this->assertRequiredEfsActive();
        $this->efsEvidence?->assertFresh();
        if ($this->efsDeleteAdmissions >= 2) {
            throw new SlowDownException('EFS delete worker capacity is exhausted.');
        }
        $this->efsDeleteAdmissions++;
        try {
            $result = $submit();
        } catch (TaskFailureException|TaskFailureError $error) {
            $this->efsEvidence?->assertFresh();
            $this->efsDeleteAdmissions--;
            throw $error;
        }
        $this->efsEvidence?->assertFresh();
        $this->efsDeleteAdmissions--;

        return $result;
    }

    private function efsNamespacePool(): ContextWorkerPool
    {
        $this->assertRequiredEfsActive();
        if ($this->efsNamespacePool === null) {
            $this->efsNamespacePool = new ContextWorkerPool(2);
            $this->ownedWorkerPools[] = $this->efsNamespacePool;
        }

        return $this->efsNamespacePool;
    }

    private function efsReadPool(): WorkerPool
    {
        $this->assertRequiredEfsActive();
        if ($this->efsReadPool === null) {
            $this->efsReadPool = new ContextWorkerPool(2);
            $this->ownedWorkerPools[] = $this->efsReadPool;
        }

        return $this->efsReadPool;
    }

    private function openEfsReadSession(string $path, ?int $offset, ?int $length): ReadableStream
    {
        $this->assertRequiredEfsActive();
        $evidence = $this->efsEvidence ?? throw new \LogicException('Guarded read has no EFS evidence.');
        $evidence->assertFresh();
        if ($this->efsReadAdmissions >= 2) {
            throw new SlowDownException('EFS read worker capacity is exhausted.');
        }
        $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded read has no worker scope.');
        $this->efsReadAdmissions++;
        // A failed submit may have crossed IPC. Keep the slot on ambiguity.
        $execution = $this->efsReadPool()->submit(new LocalEfsChunkReadTask($scope, $path, $offset, $length));
        $future = $execution->getFuture();
        $future->finally(function () use ($future, $evidence): void {
            try {
                $future->await();
            } catch (TaskFailureException|TaskFailureError) {
                // A task failure is a definitive worker response.
            } catch (\Throwable) {
                // An IPC/worker failure may leave a hard-NFS process alive.
                return;
            }
            try {
                $evidence->assertFresh();
                $this->efsReadAdmissions--;
            } catch (\Throwable) {
                // The latched node is being stopped; retain uncertain capacity.
            }
        })->ignore();
        $stream = new LocalEfsChunkReadableStream($execution, $evidence);
        try {
            $stream->primeRead();
            $evidence->assertFresh();
        } catch (\Throwable $error) {
            $stream->close();
            throw $error;
        }

        return new EfsGuardedReadableStream($stream, $evidence);
    }

    private function assertRequiredEfsActive(): void
    {
        if ($this->requireIsolatedReadSession && $this->shuttingDown) {
            throw new InternalErrorException('Qualified EFS backend is shutting down.');
        }
    }

    private function assertLegacyWriteAllowed(): void
    {
        if ($this->requireIsolatedReadSession) {
            throw new InternalErrorException('Qualified EFS writes require an isolated fenced writer.');
        }
    }

    private function runEfsNamespaceTask(string $bucket, bool $create): bool
    {
        $this->assertRequiredEfsActive();
        $this->efsEvidence?->assertFresh();
        if ($this->efsNamespaceAdmissions >= 2) {
            throw new SlowDownException('EFS bucket namespace worker capacity is exhausted.');
        }
        $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded EFS namespace has no worker scope.');
        $this->efsNamespaceAdmissions++;
        // A failed submit, IPC future, or post-worker evidence check may leave
        // a hard-NFS worker alive. A task failure is a definitive
        // worker response, so a fresh host check permits reusing that slot.
        try {
            $result = $this->efsNamespacePool()->submit(new LocalEfsBucketNamespaceTask($scope, $bucket, $create))
                ->getFuture()->await();
        } catch (TaskFailureException|TaskFailureError $error) {
            $this->efsEvidence?->assertFresh();
            $this->efsNamespaceAdmissions--;
            throw $error;
        }
        $this->efsEvidence?->assertFresh();
        $this->efsNamespaceAdmissions--;
        return $result;
    }

    /** @param-out null $file */
    private static function closeAndRelease(?\Amp\File\File &$file): void
    {
        if ($file === null) {
            return;
        }

        try {
            $file->close();
        } finally {
            // ParallelFilesystemDriver releases its reserved worker when the
            // ParallelFile object is destroyed, not merely when close() returns.
            $file = null;
        }
    }

    /** @param-out null $file */
    private static function safeCloseAndRelease(?\Amp\File\File &$file): void
    {
        try {
            self::closeAndRelease($file);
        } catch (\Throwable) {
            // Cleanup must never replace the exception that triggered it.
        }
    }

    private function pathExists(string $path): bool
    {
        return $this->metadataFilesystem->exists($path);
    }

    private function isDirectoryPath(string $path): bool
    {
        return $this->metadataFilesystem->isDirectory($path);
    }

    /**
     * Safely delete a file, swallowing any exceptions.
     *
     * Used for cleanup in error paths where we must not mask the original exception.
     */
    private function safeDelete(string $path): void
    {
        try {
            $this->efsEvidence?->assertFresh();
            if ($this->metadataFilesystem->exists($path)) {
                $this->metadataFilesystem->deleteFile($path);
            }
        } catch (\Throwable) {
            // Intentionally swallowed: cleanup failure should not mask the original error.
        }
    }

    /** Delete only legacy part files; fenced attempts and seals remain reserved. */
    private function cleanupParts(string $uploadId): void
    {
        $this->efsEvidence?->assertFresh();
        $partsDir = $this->basePath . '/.parts/' . $uploadId;

        if (! $this->metadataFilesystem->isDirectory($partsDir)) {
            return;
        }

        $files = $this->metadataFilesystem->listFiles($partsDir);

        foreach ($files as $file) {
            $this->efsEvidence?->assertFresh();
            $filePath = $partsDir . '/' . $file;
            if ($this->metadataFilesystem->isDirectory($filePath)) {
                throw new InternalErrorException('Unexpected multipart directory blocks legacy cleanup.');
            }
            // Planned attempts and permanent seals use two 32-byte tokens.
            // Keep their names occupied so a late create-only link fails.
            if (preg_match('/^[1-9][0-9]{0,4}-[a-f0-9]{32}-[a-f0-9]{32}$/D', $file) === 1) {
                continue;
            }
            // Old backend paths had either a numeric name or one random
            // token. Unknown debris must keep the queue job pending.
            if (! ctype_digit($file)
                && preg_match('/^[1-9][0-9]{0,4}-[a-f0-9]{32}$/D', $file) !== 1) {
                throw new InternalErrorException('Unexpected multipart path blocks legacy cleanup.');
            }

            try {
                $this->metadataFilesystem->deleteFile($filePath);
            } catch (FilesystemException) {
                if ($this->metadataFilesystem->exists($filePath)) {
                    throw new InternalErrorException('Failed to delete multipart part from filesystem backend.');
                }
            }
        }

        // Never remove this directory here. A worker can link a planned path
        // or a collector can install its seal after the listing above.
    }

    /**
     * Clean up stale temp files that may remain after client disconnects.
     *
     * Scans the `.tmp` directory and deletes files older than `$maxAgeSeconds`.
     *
     * @return int Number of files cleaned up.
     */
    public function cleanupStaleTempFiles(int $maxAgeSeconds = 3600): int
    {
        $this->efsEvidence?->assertFresh();
        if ($this->efsEvidence !== null) {
            $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded EFS cleanup has no worker scope.');
            $cleaned = $this->runEfsDeleteTask(fn(): int => $this->efsDeletePool()->submit(
                new LocalEfsTempCleanupTask($scope, $maxAgeSeconds),
            )->getFuture()->await());
            return $cleaned;
        }
        $tmpDir = $this->basePath . '/.tmp';

        if (! $this->metadataFilesystem->isDirectory($tmpDir)) {
            return 0;
        }

        $cleaned = 0;
        $cutoff = time() - $maxAgeSeconds;

        foreach ($this->metadataFilesystem->listFiles($tmpDir) as $file) {
            $filePath = $tmpDir . '/' . $file;
            $handle = @fopen($filePath, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                // Writers hold a shared lock until the temp file is moved.
                // A crashed writer releases it, even on a different process.
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $stat = fstat($handle);
                if ($stat !== false && ($stat['mtime'] ?? 0) < $cutoff) {
                    try {
                        $this->metadataFilesystem->deleteFile($filePath);
                        $cleaned++;
                    } catch (FilesystemException) {
                        // A concurrent operation may have removed the file.
                    }
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return $cleaned;
    }

    private function assertCompletionPath(string $path): void
    {
        StoragePath::assertMultipartCompletionPath($this->basePath, $path);
    }

    /** Preserve legacy SQL spellings while accessing their one physical path. */
    private function storedPathForAccess(string $path): string
    {
        $prefix = $this->basePath . '/';
        if (! str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Stored object path is outside the storage namespace.');
        }
        $relative = substr($path, strlen($prefix));
        // Older configurations ending in "/" persisted one extra separator
        // directly after the root. No other separator normalization is safe.
        if (str_starts_with($relative, '/')) {
            $relative = substr($relative, 1);
        }
        FlysystemPathScope::assertStoredPath($relative);

        return $prefix . $relative;
    }

    private function assertStoredPathConfined(string $path): bool
    {
        $this->efsEvidence?->assertFresh();
        if ($this->efsEvidence !== null) {
            $scope = $this->efsWorkerScope ?? throw new \LogicException('Guarded EFS path check has no worker scope.');
            $confined = $this->runEfsDeleteTask(fn(): bool => $this->efsDeletePool()->submit(
                new LocalEfsStoredPathCheckTask($scope, $path),
            )->getFuture()->await());
            return $confined;
        }
        clearstatcache(true, $this->basePath);
        clearstatcache(true, $path);
        $root = realpath($this->basePath);
        if ($root === false) {
            throw new InternalErrorException('Filesystem storage root is unavailable.');
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            if ($this->metadataFilesystem->exists($path)) {
                throw new \InvalidArgumentException('Stored object path cannot be resolved safely.');
            }

            return false;
        }

        $prefix = rtrim($root, '/') . '/';
        if (! str_starts_with($resolved, $prefix)) {
            throw new \InvalidArgumentException('Stored object path escapes the storage root.');
        }

        return true;
    }
}
