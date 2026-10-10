<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata {
    function microtime(bool $asFloat = false): float
    {
        if (! $asFloat) {
            throw new \LogicException('The queue clock fixture only supports microtime(true).');
        }

        return \microtime(true) + 46_800;
    }
}

namespace OpsFour\S3Server\Storage {
    function microtime(bool $asFloat = false): float
    {
        if (! $asFloat) {
            throw new \LogicException('The queue clock fixture only supports microtime(true).');
        }

        return \microtime(true) + 46_800;
    }
}

namespace {
    use OpsFour\S3Server\Factory\MetadataStoreFactory;
    use OpsFour\S3Server\Storage\InMemoryBackend;
    use OpsFour\S3Server\Storage\StorageGarbageCollector;
    use OpsFour\S3Server\Storage\StorageTierRegistry;
    use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (count($_SERVER['argv']) !== 3) {
        fwrite(STDERR, "Usage: external-garbage-clock-skew-worker.php DRIVER ACTION\n");
        exit(2);
    }

    $dsn = getenv('S3_TEST_QUEUE_CLOCK_DSN');
    if ($dsn === false || $dsn === '') {
        throw new \LogicException('Missing disposable metadata DSN.');
    }

    $metadata = MetadataStoreFactory::create($_SERVER['argv'][1], ['dsn' => $dsn]);
    if ($_SERVER['argv'][2] === 'claim') {
        echo count($metadata->dequeueStorageGarbage(1));
    } elseif ($_SERVER['argv'][2] === 'retry') {
        $failingBackend = new CallbackStorageBackend(
            new InMemoryBackend(),
            beforeDelete: static function (): void {
                throw new \RuntimeException('Injected physical delete failure.');
            },
        );
        echo (new StorageGarbageCollector($metadata, StorageTierRegistry::single($failingBackend)))->collect(1);
    } else {
        throw new \InvalidArgumentException('Unknown queue clock fixture action.');
    }
}
