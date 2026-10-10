<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata {
    /** Simulate an API node whose wall clock differs from the metadata server. */
    function microtime(bool $asFloat = false): float
    {
        if (! $asFloat) {
            throw new \LogicException('The rate-limit clock fixture only supports microtime(true).');
        }

        return \microtime(true) + (float) (getenv('S3_TEST_RATE_LIMIT_CLOCK_OFFSET') ?: 0);
    }
}

namespace {
    use OpsFour\S3Server\Factory\MetadataStoreFactory;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (! in_array(count($_SERVER['argv']), [3, 4], true)) {
        fwrite(STDERR, "Usage: rate-limit-skew-worker.php DRIVER IP [cleanup]\n");
        exit(2);
    }

    $dsn = getenv('S3_TEST_RATE_LIMIT_DSN');
    if ($dsn === false || $dsn === '') {
        fwrite(STDERR, "Missing disposable metadata DSN.\n");
        exit(2);
    }

    $metadata = MetadataStoreFactory::create($_SERVER['argv'][1], ['dsn' => $dsn]);
    if (($_SERVER['argv'][3] ?? '') === 'cleanup') {
        $metadata->rateLimitCleanup(30);
        exit(0);
    }

    echo $metadata->rateLimitCheck($_SERVER['argv'][2], 2.0, 1.0) ? 'allowed' : 'denied';
}
