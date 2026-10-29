<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments) || count($arguments) !== 7
    || ! is_string($arguments[1]) || ! is_string($arguments[2])
    || ! is_string($arguments[3]) || ! is_string($arguments[4]) || ! is_string($arguments[5])) {
    exit(2);
}

$lease = \OpsFour\S3Server\Storage\StorageTempWorkerLease::acquire($arguments[1], $arguments[2], $arguments[3], $arguments[6]);
try {
    if (file_put_contents($arguments[4], 'ready') !== 5) {
        exit(3);
    }
    $deadline = microtime(true) + 15;
    while (! is_file($arguments[5]) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($arguments[5])) {
        exit(4);
    }
} finally {
    $lease->close();
}
