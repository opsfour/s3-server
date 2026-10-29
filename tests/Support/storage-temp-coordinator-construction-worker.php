<?php

declare(strict_types=1);

use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4) {
    exit(2);
}

[, $tempDir, $gatePath, $resultPath] = $arguments;
$deadline = microtime(true) + 5;
while (! is_file($gatePath) && microtime(true) < $deadline) {
    usleep(1_000);
}
if (! is_file($gatePath)) {
    exit(3);
}

$coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
$snapshot = $coordinator->snapshot();
if (! $snapshot->mutationReady() || file_put_contents($resultPath, 'ready') !== 5) {
    exit(4);
}
$coordinator->close();
