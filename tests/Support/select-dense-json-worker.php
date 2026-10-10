<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input = '[' . implode(',', array_fill(0, 240_000, '{"a":0}')) . ']';

try {
    \OpsFour\S3Server\Select\JsonProcessor::parse($input, 'DOCUMENT');
} catch (\OpsFour\S3Server\Select\SelectMemoryLimitException) {
    echo 'rejected';

    exit(0);
}

echo 'accepted';
exit(1);
