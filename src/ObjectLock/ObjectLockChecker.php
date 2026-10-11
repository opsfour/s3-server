<?php

declare(strict_types=1);

namespace OpsFour\S3Server\ObjectLock;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\ObjectLockedException;
use OpsFour\S3Server\Http\Iso8601Timestamp;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Checks whether an object version is protected by Object Lock (legal hold or retention).
 *
 * Throws ObjectLockedException if the object cannot be deleted.
 */
final class ObjectLockChecker
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    /**
     * @throws ObjectLockedException
     */
    public function check(string $bucket, string $key, string $versionId, Request $request): void
    {
        $this->checkProtection(
            $bucket,
            $key,
            $versionId,
            strtolower($request->getHeader('x-amz-bypass-governance-retention') ?? '') === 'true'
                && $request->getAttribute('s3.canBypassGovernanceRetention') === true,
        );
    }

    /**
     * @throws ObjectLockedException
     */
    public function checkProtection(
        string $bucket,
        string $key,
        string $versionId,
        bool $canBypassGovernance = false,
    ): void {
        // Check legal hold first.
        $legalHold = $this->metadata->getObjectLegalHold($bucket, $key, $versionId);
        if ($legalHold !== null && $legalHold !== 'OFF') {
            throw new ObjectLockedException();
        }

        // Check retention.
        $retention = $this->metadata->getObjectRetention($bucket, $key, $versionId);
        if ($retention !== null) {
            $mode = $retention['mode'];
            $retainUntilDate = Iso8601Timestamp::parse($retention['retainUntilDate']);
            if (! in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true) || $retainUntilDate === null) {
                throw new ObjectLockedException();
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($retainUntilDate > $now) {
                if ($mode === 'COMPLIANCE') {
                    throw new ObjectLockedException();
                }

                if (! $canBypassGovernance) {
                    throw new ObjectLockedException();
                }
            }
        }
    }
}
