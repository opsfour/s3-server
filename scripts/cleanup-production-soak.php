<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;

$requiredEnvironment = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing {$name}.");
    }

    return $value;
};

$bucket = $requiredEnvironment('S3_BACKING_BUCKET');
$prefix = trim($requiredEnvironment('S3_BACKING_PREFIX'), '/') . '/';
if (! str_starts_with($prefix, 's3-server/release-soak-')) {
    throw new RuntimeException("Refusing to clean unexpected prefix: {$prefix}");
}

$client = new S3Client([
    'version' => 'latest',
    'region' => $requiredEnvironment('S3_BACKING_REGION'),
    'endpoint' => $requiredEnvironment('S3_BACKING_ENDPOINT'),
    'use_path_style_endpoint' => filter_var(
        getenv('S3_BACKING_PATH_STYLE') ?: 'false',
        FILTER_VALIDATE_BOOLEAN,
    ),
    'credentials' => [
        'key' => $requiredEnvironment('S3_BACKING_ACCESS_KEY'),
        'secret' => $requiredEnvironment('S3_BACKING_SECRET_KEY'),
    ],
]);

$deleted = 0;
do {
    $result = $client->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => $prefix,
        'MaxKeys' => 1000,
    ]);
    $objects = array_map(
        static fn(array $object): array => ['Key' => (string) $object['Key']],
        $result['Contents'] ?? [],
    );
    if ($objects !== []) {
        $client->deleteObjects([
            'Bucket' => $bucket,
            'Delete' => ['Objects' => $objects, 'Quiet' => true],
        ]);
        $deleted += count($objects);
    }
} while ($objects !== []);

printf("Remote cleanup complete: %d objects removed from %s.\n", $deleted, $prefix);
