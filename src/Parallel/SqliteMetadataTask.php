<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;

/**
 * Worker task for executing SqliteMetadataStore methods in a child process.
 *
 * Each worker maintains a static SqliteMetadataStore instance that is reused
 * across calls, following the same pattern as amphp/file's FileTask.
 *
 * @implements Task<mixed, never, never>
 */
final class SqliteMetadataTask implements Task
{
    private static ?SqliteMetadataStore $store = null;

    private static ?string $currentDbPath = null;

    /**
     * @param  string  $databasePath  Absolute path to the SQLite database file.
     * @param  string  $method  MetadataStore method name to invoke.
     * @param  list<mixed>  $args  Method arguments (must all be serializable).
     */
    public function __construct(
        private readonly string $databasePath,
        private readonly string $method,
        private readonly array $args = [],
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        if (self::$store === null || self::$currentDbPath !== $this->databasePath) {
            self::$store = new SqliteMetadataStore($this->databasePath);
            self::$store->initialize();
            self::$currentDbPath = $this->databasePath;
        }

        return self::$store->{$this->method}(...$this->args);
    }
}
