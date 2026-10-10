<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalEncryptedMultipartCompletionFence;
use OpsFour\S3Server\Storage\LocalEncryptedMultipartCompletionResult;

/**
 * Scalar-only candidate task. The SSE-S3 keyring stays in inherited worker
 * environment; an SSE-C customer key crosses local process IPC in memory.
 *
 * @implements Task<LocalEncryptedMultipartCompletionResult, never, never>
 * @internal Candidate handler integration exists; production EFS admission is closed.
 */
final readonly class LocalEncryptedMultipartCompletionTask implements Task
{
    /** @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts */
    public function __construct(
        private EfsWorkerEvidenceScope $scope,
        private string $bucket,
        private string $key,
        private string $generation,
        private string $attemptId,
        private string $uploadId,
        private array $parts,
        private string $target,
        private string $mode,
        private int $maxPlaintextBytes,
        private ?string $customerKey = null,
        private string $expectedKeyringFingerprint = '',
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): LocalEncryptedMultipartCompletionResult
    {
        $cancellation->throwIfRequested();
        $evidence = EfsHostEvidenceReader::fromWorkerScope($this->scope);
        $evidence->assertFresh();
        if ($this->mode === EncryptionRequestResolver::SSE_S3) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $this->expectedKeyringFingerprint) || $this->customerKey !== null) {
                throw new \InvalidArgumentException('Encrypted completion SSE-S3 worker keyring is invalid.');
            }
            $provider = new ConfigMasterKeyProvider();
            if (! hash_equals($this->expectedKeyringFingerprint, $provider->keyringFingerprint())) {
                throw new \RuntimeException('Encrypted completion worker keyring differs from its parent.');
            }
            $encryption = new EncryptionService($provider);
        } elseif ($this->mode === EncryptionRequestResolver::SSE_C) {
            if ($this->expectedKeyringFingerprint !== '' || $this->customerKey === null || strlen($this->customerKey) !== 32) {
                throw new \InvalidArgumentException('Encrypted completion SSE-C worker key is invalid.');
            }
            $encryption = new EncryptionService();
        } else {
            throw new \InvalidArgumentException('Encrypted completion worker mode is invalid.');
        }

        return (new LocalEncryptedMultipartCompletionFence(
            $this->scope->storageRoot,
            $encryption,
            $this->maxPlaintextBytes,
            evidence: $evidence,
        ))->complete(
            $this->bucket,
            $this->key,
            $this->generation,
            $this->attemptId,
            $this->uploadId,
            $this->parts,
            $this->target,
            $this->mode,
            $this->customerKey,
        );
    }
}
