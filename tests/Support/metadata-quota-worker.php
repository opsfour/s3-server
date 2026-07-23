<?php

declare(strict_types=1);

use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $driver, $ownerId, $bucket, $key] = $argv;
$dsn = getenv('S3_TEST_WORKER_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDERR, "S3_TEST_WORKER_DSN is required.\n");
    exit(2);
}

try {
    $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
    $quotas = QuotaManager::fromGlobalConfig($metadata, new QuotaConfig());

    $metadata->transaction(function () use ($metadata, $quotas, $ownerId, $bucket, $key): void {
        $metadata->lockOwnerForUpdate($ownerId);
        $quotas->assertCanWriteObject($ownerId, $bucket, null, 60, false);

        // Keep the first transaction open long enough for the second process to
        // contend on the same owner row.
        usleep(300_000);
        $metadata->putObjectMetadata(
            bucket: $bucket,
            key: $key,
            ownerId: $ownerId,
            size: 60,
            etag: '"' . md5($key) . '"',
            contentType: 'application/octet-stream',
            storagePath: $bucket . '/' . $key,
        );
    });

    fwrite(STDOUT, "ok\n");
} catch (QuotaExceededException) {
    fwrite(STDOUT, "quota\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(2);
}
