<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Internal startup proof from one pinned local reader and its own preflight.
 * This value neither enables EFS serving nor authorizes a physical write.
 * Later checks read only local host evidence; the NFS root is touched once by
 * the preflight after local evidence has passed.
 *
 * @internal The explicit CLI candidate creates this value, then refuses serving.
 */
final readonly class EfsQualificationContext
{
    private function __construct(
        private EfsLocalStartupEvidenceReader $evidence,
        private string $storageRoot,
        private EfsWorkerEvidenceScope $pinnedScope,
    ) {}

    /**
     * @param (Closure(): (string|false))|null $mountInfoReader
     */
    public static function qualify(
        EfsLocalStartupEvidenceReader $evidence,
        string $storageRoot,
        ?Closure $mountInfoReader = null,
    ): self {
        // Both scope reads are local-only. The preflight owns the sole initial
        // storage-root realpath/stat and is constructed from this exact reader.
        $before = $evidence->workerScope();
        if ($storageRoot !== $before->storageRoot) {
            throw new \RuntimeException('EFS qualification storage root differs from pinned evidence.');
        }
        $root = (new EfsStorageStartupPreflight($evidence, $mountInfoReader))->assertExistingRoot($storageRoot);
        $after = $evidence->workerScope();
        if ($root !== $before->storageRoot || $root !== $after->storageRoot
            || ! self::sameScope($before, $after)) {
            throw new \RuntimeException('EFS qualification identity changed during startup.');
        }

        return new self($evidence, $root, $before);
    }

    /** Recheck the same watcher epoch without touching the storage root. */
    public function assertFresh(): void
    {
        $current = $this->evidence->workerScope();
        if (! self::sameScope($this->pinnedScope, $current)) {
            throw new \RuntimeException('EFS qualification identity changed.');
        }
    }

    public function storageRoot(): string
    {
        $this->assertFresh();

        return $this->storageRoot;
    }

    public function workerScope(): EfsWorkerEvidenceScope
    {
        $this->assertFresh();

        return $this->pinnedScope;
    }

    /** Bind a guarded worker to the exact host reader used at qualification. */
    public function assertHostEvidenceReader(EfsHostEvidenceReader $reader): void
    {
        $this->assertFresh();
        if ($this->evidence->hostEvidenceReader() !== $reader) {
            throw new \RuntimeException('EFS worker reader differs from pinned qualification.');
        }
    }

    /** @internal Return only the watcher reader pinned by this qualification. */
    public function hostEvidenceReader(): EfsHostEvidenceReader
    {
        $this->assertFresh();

        return $this->evidence->hostEvidenceReader();
    }

    /**
     * Bind a candidate API configuration and its actual metadata store to this
     * watcher epoch. This check alone does not admit EFS serving or writes.
     */
    public function assertCandidateMetadataBinding(S3ServerConfig $config, MetadataStore $metadata): void
    {
        $this->assertFresh();
        if ($config->storagePath !== $this->storageRoot
            || $config->metadataDriver !== $this->pinnedScope->driver
            || $config->allowUnfencedStorageForTests
            || $config->localObjectWriteTestScratchPath !== null) {
            throw new \RuntimeException('EFS candidate configuration differs from pinned qualification.');
        }

        MetadataDriverStartupGuard::assertRawFencedSqlStore(
            $metadata,
            $config->metadataDriver,
            $config->metadataDsn,
        );
        $this->assertFresh();
    }

    /**
     * Bind the sole physical tier and the handler-facing backend to the exact
     * watcher reader that qualified this root. No storage-root syscall is
     * performed here. Writer, collector and serving admission remain separate.
     */
    public function assertCandidateStorageBinding(StorageBackend $storage, StorageTierRegistry $tiers): void
    {
        $this->assertFresh();
        $all = $tiers->all();
        if (count($all) !== 1 || $tiers->defaultBackend() !== $storage) {
            throw new \RuntimeException('EFS candidate storage tier differs from the handler backend.');
        }

        $backend = $storage;
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if (! $backend instanceof FilesystemBackend
            || $backend->getBasePath() !== $this->storageRoot
            || $backend->hostEvidenceReader() !== $this->evidence->hostEvidenceReader()) {
            throw new \RuntimeException('EFS candidate storage backend differs from pinned qualification.');
        }
        $backend->assertQualifiedEfsReadConfiguration();
        $this->assertFresh();
    }

    /**
     * Prove that one candidate uses this exact watcher epoch, native SQL
     * store, guarded physical tier and node-local scratch. This proof does not
     * wire a runtime or admit EFS serving.
     */
    public function assertCandidateRuntimeInputs(
        S3ServerConfig $config,
        MetadataStore $metadata,
        StorageBackend $storage,
        StorageTierRegistry $tiers,
        EfsNodeLocalScratch $scratch,
    ): void {
        $this->assertCandidateMetadataBinding($config, $metadata);
        $this->assertCandidateStorageBinding($storage, $tiers);
        $scratch->assertBoundTo($this);
        $this->assertFresh();
    }

    private static function sameScope(EfsWorkerEvidenceScope $first, EfsWorkerEvidenceScope $second): bool
    {
        return get_object_vars($first) === get_object_vars($second);
    }
}
