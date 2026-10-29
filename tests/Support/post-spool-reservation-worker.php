<?php

declare(strict_types=1);

use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) < 3 || count($arguments) > 5) {
    exit(2);
}

[, $tempDir, $readyPath] = $arguments;
$prefix = $arguments[3] ?? 's3-post-';
$budgetBytes = isset($arguments[4]) ? (int) $arguments[4] : 5;
if (!in_array($prefix, ['s3-post-', 's3-md5-'], true)) {
    exit(2);
}
$tempPath = $tempDir . '/' . $prefix . 'worker.tmp';
$coordinator = new PostObjectSpoolCoordinator($tempDir);
$reservation = $coordinator->start($tempPath);
$coordinator->reserve($reservation, 4, $budgetBytes);
if (file_put_contents($tempPath, 'abcd') !== 4) {
    exit(2);
}
if (file_put_contents($readyPath, 'ready') !== 5) {
    exit(2);
}

// Keep the lease locked until the test terminates this process abruptly.
fgets(STDIN);
