<?php

declare(strict_types=1);

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (count($_SERVER['argv']) !== 3) {
    fwrite(STDERR, "Usage: metadata-garbage-collect-worker.php SQLITE_PATH STORAGE_PATH\n");
    exit(2);
}

$metadata = new SqliteMetadataStore($_SERVER['argv'][1]);
$storage = new FilesystemBackend($_SERVER['argv'][2]);
$collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage));
fwrite(STDOUT, (string) $collector->collect(10));
