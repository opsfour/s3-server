<?php

declare(strict_types=1);

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Middleware\RequestBodySpool;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (count($_SERVER['argv'] ?? []) !== 3) {
    exit(2);
}

[, $tempDir, $readyPath] = $_SERVER['argv'];
$pool = new ContextWorkerPool(1);
$spool = new RequestBodySpool($pool, $tempDir);
$path = $spool->create('s3-md5-');
$spool->append($path, 'crash-only-sensitive-payload');
if (!touch($path, time() - 90_000) || file_put_contents($readyPath, $path) !== strlen($path)) {
    exit(2);
}

fgets(STDIN);
