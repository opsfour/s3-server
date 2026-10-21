<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\LocalDurableFilePublisher;

/** @implements Task<null, never, never> */
final readonly class LocalDurableFilesystemTask implements Task
{
    private const string PUBLISH = 'publish';

    private const string DELETE = 'delete';

    private const string ENSURE_DIRECTORY = 'ensure-directory';

    private const string ASSERT_WRITABLE = 'assert-writable';

    private function __construct(
        private string $operation,
        private string $root,
        private string $path,
        private string $targetPath,
    ) {
        foreach ([$root, $path, $targetPath] as $value) {
            if (str_contains($value, "\0")) {
                throw new \InvalidArgumentException('Local durability task paths must not contain null bytes.');
            }
        }
        if ($root === '' || $path === '') {
            throw new \InvalidArgumentException('Local durability task paths must not be empty.');
        }
        if ($operation === self::PUBLISH) {
            if ($targetPath === '' || $targetPath === $path) {
                throw new \InvalidArgumentException('Local publication requires distinct staging and target paths.');
            }

            return;
        }
        if ($targetPath !== '') {
            throw new \InvalidArgumentException('Local single-path operations must not have a target path.');
        }
    }

    public static function publish(string $root, string $stagedPath, string $targetPath): self
    {
        return new self(self::PUBLISH, $root, $stagedPath, $targetPath);
    }

    public static function delete(string $root, string $path): self
    {
        return new self(self::DELETE, $root, $path, '');
    }

    public static function ensureDirectory(string $root, string $path): self
    {
        return new self(self::ENSURE_DIRECTORY, $root, $path, '');
    }

    public static function assertWritable(string $root): self
    {
        return new self(self::ASSERT_WRITABLE, $root, $root, '');
    }

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $cancellation->throwIfRequested();
        $publisher = new LocalDurableFilePublisher(
            $this->root,
            createRoot: $this->operation === self::ENSURE_DIRECTORY,
        );

        match ($this->operation) {
            self::PUBLISH => $publisher->publish($this->path, $this->targetPath),
            self::DELETE => $publisher->delete($this->path),
            self::ENSURE_DIRECTORY => $publisher->ensureDirectory($this->path),
            self::ASSERT_WRITABLE => $publisher->assertWritable(),
            default => throw new \LogicException('Unknown local durability operation.'),
        };

        return null;
    }
}
