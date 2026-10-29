<?php

declare(strict_types=1);

use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    exit(2);
}

[, $tempDir, $readyPath] = $arguments;
$tempPath = $tempDir . '/s3fw-put-worker';
$coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
$reservation = $coordinator->start($tempPath);
$coordinator->reserve($reservation, 4);
if (file_put_contents($tempPath, 'abcd') !== 4) {
    exit(2);
}
if (file_put_contents($readyPath, 'ready') !== 5) {
    exit(2);
}

fgets(STDIN);
