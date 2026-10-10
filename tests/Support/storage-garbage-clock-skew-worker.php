<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata {
    // Simulate a GC node whose PHP wall clock is thirteen hours ahead of the
    // shared metadata database, without changing the host clock.
    function microtime(bool $asFloat = false): float
    {
        return \microtime(true) + 46_800;
    }
}

namespace {
    use OpsFour\S3Server\Metadata\SqliteMetadataStore;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (count($_SERVER['argv']) !== 2) {
        fwrite(STDERR, "Usage: storage-garbage-clock-skew-worker.php SQLITE_PATH\n");
        exit(2);
    }

    $metadata = new SqliteMetadataStore($_SERVER['argv'][1]);
    $items = $metadata->dequeueStorageGarbage(1);
    fwrite(STDOUT, (string) count($items));
}
