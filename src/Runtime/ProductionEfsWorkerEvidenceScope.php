<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Scalar-only schema-v2 trust basis passed through Amp's process channel.
 *
 * This snapshot is not live evidence and does not authorize serving or a
 * physical write. A worker must construct a ProductionEfsWatcherEvidenceFileReader
 * from it and revalidate the local heartbeat before each guarded operation.
 */
final readonly class ProductionEfsWorkerEvidenceScope
{
    private const int MAX_LEASE_NS = 20_000_000_000;

    private function __construct(
        public int $schemaVersion,
        public string $profile,
        public string $deploymentId,
        public string $instanceId,
        public string $efsId,
        public string $sourceSha256,
        public string $metadataDriver,
        public string $mountPoint,
        public string $storageRoot,
        public string $scratchPath,
        public string $evidenceDirectory,
        public string $containerId,
        public string $bootId,
        public string $hostMountId,
        public string $storageDev,
        public string $storageIno,
        public string $epoch,
        public string $evidenceMountSha256,
        public string $evidenceDirectoryDev,
        public string $evidenceDirectoryIno,
        public int $issuedMonotonicNs,
        public int $expiresMonotonicNs,
        public int $validatedMonotonicNs,
    ) {
        $this->assertValid();
    }

    /** @internal Capture only after the local production reader validated the record. */
    public static function fromValidatedEvidence(
        ProductionEfsWatcherEvidence $evidence,
        string $evidenceMountSha256,
        string $evidenceDirectoryDev,
        string $evidenceDirectoryIno,
        int $validatedMonotonicNs,
    ): self {
        $identity = $evidence->identity;
        $pin = $evidence->hostPin;

        return new self(
            2,
            'production',
            $identity->deploymentId,
            $identity->instanceId,
            $identity->efsId,
            $identity->sourceSha256,
            $identity->metadataDriver,
            $identity->mountPoint,
            $identity->storageRoot,
            $identity->scratchPath,
            $identity->evidenceDirectory,
            $pin->containerId,
            $pin->bootId,
            $pin->hostMountId,
            $pin->storageDev,
            $pin->storageIno,
            $pin->epoch,
            $evidenceMountSha256,
            $evidenceDirectoryDev,
            $evidenceDirectoryIno,
            $evidence->issuedMonotonicNs,
            $evidence->expiresMonotonicNs,
            $validatedMonotonicNs,
        );
    }

    /** Revalidate serialized values before a worker can use the scope. */
    public function __wakeup(): void
    {
        $this->assertValid();
    }

    public function identity(): ProductionEfsIdentity
    {
        return ProductionEfsIdentity::fromJson(json_encode([
            'schemaVersion' => $this->schemaVersion,
            'profile' => $this->profile,
            'deploymentId' => $this->deploymentId,
            'instanceId' => $this->instanceId,
            'efsId' => $this->efsId,
            'sourceSha256' => $this->sourceSha256,
            'metadataDriver' => $this->metadataDriver,
            'mountPoint' => $this->mountPoint,
            'storageRoot' => $this->storageRoot,
            'scratchPath' => $this->scratchPath,
            'evidenceDirectory' => $this->evidenceDirectory,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function hostPin(): ProductionEfsHostPin
    {
        return ProductionEfsHostPin::fromJson(json_encode([
            'schemaVersion' => $this->schemaVersion,
            'profile' => $this->profile,
            'deploymentId' => $this->deploymentId,
            'instanceId' => $this->instanceId,
            'containerId' => $this->containerId,
            'bootId' => $this->bootId,
            'hostMountId' => $this->hostMountId,
            'storageDev' => $this->storageDev,
            'storageIno' => $this->storageIno,
            'epoch' => $this->epoch,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertValid(): void
    {
        if ($this->schemaVersion !== 2 || $this->profile !== 'production'
            || preg_match('/^[a-f0-9]{64}$/D', $this->evidenceMountSha256) !== 1
            || ! self::positivePhpIntDecimal($this->evidenceDirectoryDev)
            || ! self::positivePhpIntDecimal($this->evidenceDirectoryIno)
            || $this->issuedMonotonicNs < 0
            || $this->expiresMonotonicNs <= $this->issuedMonotonicNs
            || $this->expiresMonotonicNs - $this->issuedMonotonicNs > self::MAX_LEASE_NS
            || $this->validatedMonotonicNs < $this->issuedMonotonicNs
            || $this->validatedMonotonicNs >= $this->expiresMonotonicNs) {
            throw new \InvalidArgumentException('Production EFS worker evidence scope is invalid.');
        }

        // Reuse the strict schema-v2 validators and also prove that identity
        // and host pin still describe the same deployment and instance.
        $identity = $this->identity();
        $pin = $this->hostPin();
        if ($identity->deploymentId !== $pin->deploymentId
            || $identity->instanceId !== $pin->instanceId) {
            throw new \InvalidArgumentException('Production EFS worker evidence scope is inconsistent.');
        }
    }

    private static function positivePhpIntDecimal(string $value): bool
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;

        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }
}
