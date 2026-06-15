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
 * so no key material crosses IPC.
 *
 * Each worker maintains a static EncryptionService instance.
 *
 * @implements Task<mixed, never, never>
 */
final class EncryptionTask implements Task
{
    private static ?EncryptionService $service = null;

    /**
     * @param  string  $method  EncryptionService method name.
     * @param  list<mixed>  $args  Method arguments (must all be serializable).
     */
    public function __construct(
        private readonly string $method,
        private readonly array $args = [],
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        if (self::$service === null) {
            $masterKeyProvider = new ConfigMasterKeyProvider();
            self::$service = new EncryptionService($masterKeyProvider);
        }

        return self::$service->{$this->method}(...$this->args);
    }
}
