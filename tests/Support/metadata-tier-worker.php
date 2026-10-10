<?php

declare(strict_types=1);

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (count($_SERVER['argv']) !== 6) {
    fwrite(STDERR, "Usage: metadata-tier-worker.php DRIVER transition|restore limited|full HOT_PATH COLD_PATH\n");
    exit(2);
}

[, $driver, $operation, $configuration, $hotPath, $coldPath] = $_SERVER['argv'];
$dsn = getenv('S3_TEST_WORKER_DSN');
if ($dsn === false || $dsn === '' || ! in_array($driver, ['postgres', 'mysql'], true)
    || ! in_array($operation, ['transition', 'restore'], true)
    || ! in_array($configuration, ['limited', 'full'], true)) {
    fwrite(STDERR, "Invalid metadata tier worker configuration.\n");
    exit(2);
}

try {
    $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
    $filesystem = new Filesystem(new BlockingFilesystemDriver());
    $hot = new FilesystemBackend($hotPath, $filesystem, $filesystem);
    $cold = new FilesystemBackend($coldPath, $filesystem, $filesystem);
    $tiers = [new StorageTier('STANDARD', $hot, defaultWriteTier: true)];
    if ($configuration === 'full') {
        $tiers[] = new StorageTier('GLACIER', $cold, restoreRequired: true);
    }
    $registry = new StorageTierRegistry($tiers);
    $executor = $operation === 'transition'
        ? new TierTransitionExecutor($metadata, $registry)
        : new RestoreExecutor($metadata, $registry);
    fwrite(STDOUT, json_encode($executor->processNext(1), JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(2);
}
