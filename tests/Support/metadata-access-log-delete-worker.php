<?php

declare(strict_types=1);

use OpsFour\S3Server\Exception\BucketNotEmptyException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (count($_SERVER['argv']) !== 5) {
    fwrite(STDERR, "Usage: metadata-access-log-delete-worker.php DRIVER BUCKET OWNER READY_PATH\n");
    exit(2);
}

[, $driver, $bucket, $owner, $readyPath] = $_SERVER['argv'];
$dsn = getenv('S3_TEST_WORKER_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDERR, "S3_TEST_WORKER_DSN is required.\n");
    exit(2);
}

try {
    $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
    if (file_put_contents($readyPath, 'ready') === false) {
        throw new RuntimeException('Could not publish worker readiness.');
    }
    try {
        $metadata->transaction(function () use ($metadata, $owner, $bucket): void {
            // Match DeleteBucketHandler's lock order before deleting the target.
            $metadata->lockOwnerForUpdate($owner);
            $metadata->deleteBucket($owner, $bucket);
        });
        fwrite(STDOUT, "deleted\n");
    } catch (BucketNotEmptyException) {
        fwrite(STDOUT, "not_empty\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(2);
}
