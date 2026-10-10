<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments)
    || !isset($arguments[1], $arguments[2])
    || !is_string($arguments[1])
    || !is_string($arguments[2])) {
    exit(2);
}

$handle = fopen($arguments[1], 'rb');
if ($handle === false || !flock($handle, LOCK_SH)) {
    exit(1);
}

file_put_contents($arguments[2], 'ready');
sleep(60);

flock($handle, LOCK_UN);
fclose($handle);
