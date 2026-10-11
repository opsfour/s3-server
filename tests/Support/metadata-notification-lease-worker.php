<?php

declare(strict_types=1);

use OpsFour\S3Server\Factory\MetadataStoreFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (count($_SERVER['argv']) < 6 || count($_SERVER['argv']) > 7) {
    fwrite(STDERR, "Usage: metadata-notification-lease-worker.php DRIVER READY_PATH START_PATH BUCKET EXPECTED_COUNT [notification|storage_garbage]\n");
    exit(2);
}

[, $driver, $readyPath, $startPath, $bucket, $expectedCount] = $_SERVER['argv'];
$expectedCount = (int) $expectedCount;
$queue = $_SERVER['argv'][6] ?? 'notification';
if ($expectedCount <= 0) {
    fwrite(STDERR, "EXPECTED_COUNT must be positive.\n");
    exit(2);
}
if (!in_array($queue, ['notification', 'storage_garbage'], true)) {
    fwrite(STDERR, "Unsupported lease queue.\n");
    exit(2);
}
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
    $deadline = microtime(true) + 10;
    while (! is_file($startPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for lease start.');
        }
        usleep(10_000);
    }

    // SKIP LOCKED may return an empty batch while the other process holds
    // candidate rows. A real worker polls again after that transient result.
    $ids = [];
    $claims = [];
    $leaseDeadline = microtime(true) + 10;
    while (count($ids) < $expectedCount) {
        $batch = $queue === 'notification'
            ? $metadata->dequeueNotifications($expectedCount - count($ids))
            : $metadata->dequeueStorageGarbage($expectedCount - count($ids));
        foreach ($batch as $row) {
            if ($row['bucket'] !== $bucket) {
                throw new RuntimeException('Worker claimed another bucket queue item.');
            }
            $ids[] = (int) $row['id'];
            if ($queue === 'storage_garbage') {
                if (! isset($row['lease_generation'])) {
                    throw new RuntimeException('Storage garbage claim is missing its lease generation.');
                }
                $claims[] = [
                    'id' => (int) $row['id'],
                    'lease_expires_at' => (float) $row['lease_expires_at'],
                    'lease_generation' => (int) $row['lease_generation'],
                ];
            }
        }
        if ($batch !== []) {
            continue;
        }
        if (microtime(true) >= $leaseDeadline) {
            throw new RuntimeException('Timed out waiting for queue leases.');
        }
        usleep(10_000);
    }
    fwrite(STDOUT, json_encode($queue === 'storage_garbage' ? $claims : $ids, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(2);
}
