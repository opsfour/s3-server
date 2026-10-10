<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;

/**
 * Worker task for executing EncryptionService methods in a child process.
 *
 * The worker reads master keys from the S3_ENCRYPTION_MASTER_KEYS or
 * S3_ENCRYPTION_MASTER_KEY env vars (inherited from the parent process),
 * so no SSE-S3 master key crosses IPC. SSE-C customer keys are included in
 * the task arguments for large requests and cross local IPC in process memory.
 *
 * Each worker maintains a static EncryptionService instance.
 *
 * @implements Task<mixed, never, never>
 */
final class EncryptionTask implements Task
{
    private static ?EncryptionService $sseS3Service = null;

    private static ?string $keyringFingerprint = null;

    /**
     * @param  string  $method  EncryptionService method name.
     * @param  list<mixed>  $args  Method arguments (must all be serializable).
     */
    public function __construct(
        private readonly string $method,
        private readonly array $args = [],
        private readonly string $expectedKeyringFingerprint = '',
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        if (in_array($this->method, ['encryptSseC', 'decryptSseC'], true)) {
            if ($this->expectedKeyringFingerprint !== '') {
                throw new \InvalidArgumentException('SSE-C worker task must not carry a master-key fingerprint.');
            }

            return (new EncryptionService())->{$this->method}(...$this->args);
        }

        if (! in_array($this->method, ['encryptSseS3', 'decryptSseS3'], true)
            || ! preg_match('/^[a-f0-9]{64}$/D', $this->expectedKeyringFingerprint)) {
            throw new \InvalidArgumentException('Invalid parallel SSE-S3 worker task.');
        }
        if (self::$sseS3Service === null) {
            $masterKeyProvider = new ConfigMasterKeyProvider();
            self::$keyringFingerprint = $masterKeyProvider->keyringFingerprint();
            self::$sseS3Service = new EncryptionService($masterKeyProvider);
        }
        if (self::$keyringFingerprint === null
            || ! hash_equals($this->expectedKeyringFingerprint, self::$keyringFingerprint)) {
            throw new \RuntimeException('Encryption worker keyring differs from its parent.');
        }

        return self::$sseS3Service->{$this->method}(...$this->args);
    }
}
