<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata {
    /** Simulate an API node whose wall clock differs from the metadata server. */
    function microtime(bool $asFloat = false): float
    {
        if (! $asFloat) {
            throw new \LogicException('The multipart clock fixture only supports microtime(true).');
        }

        return \microtime(true) + (float) (getenv('S3_TEST_MULTIPART_CLOCK_OFFSET') ?: 0);
    }
}

namespace {
    use OpsFour\S3Server\Factory\MetadataStoreFactory;
    use OpsFour\S3Server\Metadata\MultipartWriteIntent;
    use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (count($_SERVER['argv']) !== 4) {
        fwrite(STDERR, "Usage: multipart-intent-clock-worker.php DRIVER ACTION ATTEMPT_ID\n");
        exit(2);
    }

    $dsn = getenv('S3_TEST_MULTIPART_CLOCK_DSN');
    if ($dsn === false || $dsn === '') {
        fwrite(STDERR, "Missing disposable metadata DSN.\n");
        exit(2);
    }

    $metadata = MetadataStoreFactory::create($_SERVER['argv'][1], ['dsn' => $dsn]);
    if (! $metadata instanceof MultipartWriteIntentStore) {
        throw new \LogicException('Metadata store lacks durable multipart intents.');
    }

    if ($_SERVER['argv'][2] === 'claim') {
        $now = \OpsFour\S3Server\Metadata\microtime(true);
        $claimed = $metadata->claimDueMultipartWriteIntents(10, $now, $now + 300);
        echo json_encode(array_map(static fn($intent): string => $intent->attemptId, $claimed), JSON_THROW_ON_ERROR);
    } elseif ($_SERVER['argv'][2] === 'consume') {
        echo $metadata->consumeMultipartWriteIntent($_SERVER['argv'][3]) ? 'consumed' : 'denied';
    } elseif ($_SERVER['argv'][2] === 'create') {
        $now = $metadata->multipartWriteIntentNow();
        $attemptId = $_SERVER['argv'][3];
        $bucket = $metadata->getBucket('bucket');
        if ($bucket === null) {
            throw new \LogicException('Clock test bucket does not exist.');
        }
        $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: 'clock-' . $attemptId,
            bucket: 'bucket',
            bucketGeneration: $bucket->generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            assemblyPath: 'assembly/' . $attemptId,
            finalPath: 'final/' . $attemptId,
            partDigest: str_repeat('b', 64),
            sseMode: null,
            leaseUntil: $now + 600,
            nextAttemptAt: $now + 600,
        ));
        echo 'created';
    } elseif ($_SERVER['argv'][2] === 'abandon') {
        echo $metadata->abandonMultipartWriteIntent($_SERVER['argv'][3], $metadata->multipartWriteIntentNow())
            ? 'abandoned' : 'denied';
    } elseif ($_SERVER['argv'][2] === 'retry') {
        $intent = $metadata->getMultipartWriteIntent($_SERVER['argv'][3]);
        if ($intent === null) {
            throw new \LogicException('Intent for retry does not exist.');
        }
        echo $metadata->retryMultipartWriteIntent(
            $intent->attemptId,
            $intent->nextAttemptAt,
            $metadata->multipartWriteIntentNow() + 60,
        ) ? 'retried' : 'denied';
    } else {
        throw new \InvalidArgumentException('Unknown worker action.');
    }
}
