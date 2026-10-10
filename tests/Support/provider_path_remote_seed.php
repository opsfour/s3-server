<?php

declare(strict_types=1);

/** Direct backing S3 seed/cleanup for one uniquely scoped provider-path gate. */

use Aws\S3\S3Client;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$action = $argv[1] ?? '';
if (! in_array($action, ['seed', 'cleanup', 'status'], true)) {
    fwrite(STDERR, "Usage: provider_path_remote_seed.php seed|cleanup|status\n");
    exit(2);
}

$names = [
    'S3_GATE_BACKING_BUCKET', 'S3_GATE_BACKING_REGION', 'S3_GATE_BACKING_ENDPOINT',
    'S3_GATE_BACKING_ACCESS_KEY', 'S3_GATE_BACKING_SECRET_KEY', 'S3_GATE_REMOTE_PREFIX',
];
$values = [];
foreach ($names as $name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing {$name}.\n");
        exit(2);
    }
    $values[$name] = $value;
}

$prefix = $values['S3_GATE_REMOTE_PREFIX'];
if (preg_match('#^s3-server/provider-fault/[A-Za-z0-9._-]+/$#D', $prefix) !== 1) {
    fwrite(STDERR, "Refusing an unsafe remote test prefix.\n");
    exit(2);
}
$endpoint = parse_url($values['S3_GATE_BACKING_ENDPOINT']);
if (! is_array($endpoint) || ($endpoint['scheme'] ?? '') !== 'https'
    || ! is_string($endpoint['host'] ?? null)
    || isset($endpoint['user']) || isset($endpoint['pass'])
    || ! in_array($endpoint['path'] ?? '', ['', '/'], true)) {
    fwrite(STDERR, "Backing endpoint must be an HTTPS hostname without credentials or path.\n");
    exit(2);
}

$config = [
    'version' => 'latest',
    'region' => $values['S3_GATE_BACKING_REGION'],
    'endpoint' => $values['S3_GATE_BACKING_ENDPOINT'],
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => $values['S3_GATE_BACKING_ACCESS_KEY'],
        'secret' => $values['S3_GATE_BACKING_SECRET_KEY'],
    ],
    'http' => ['connect_timeout' => 5, 'timeout' => 30],
];
$syntheticCa = getenv('S3_GATE_SYNTHETIC_CA');
if ($syntheticCa !== false && $syntheticCa !== '') {
    if (($endpoint['host'] ?? '') !== 'origin') {
        fwrite(STDERR, "Test CA is restricted to the synthetic origin.\n");
        exit(2);
    }
    $config['http']['verify'] = $syntheticCa;
}
$client = new S3Client($config);
$bucket = $values['S3_GATE_BACKING_BUCKET'];
$key = $prefix . 'fault.bin';

try {
    if ($action === 'seed') {
        $body = str_repeat('x', 65_536);
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentLength' => strlen($body),
            'ContentType' => 'application/octet-stream',
        ]);
        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        if ((int) $head['ContentLength'] !== strlen($body)) {
            throw new RuntimeException('Seed object size mismatch.');
        }
        fwrite(STDOUT, "seeded=true bytes=65536\n");
    } else {
        if ($action === 'cleanup') {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        }
        for ($pass = 1; $pass <= 2; ++$pass) {
            $listed = $client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 10]);
            if (($listed['Contents'] ?? []) !== [] || ($listed['IsTruncated'] ?? false)) {
                throw new RuntimeException('Exact test prefix is not empty.');
            }
        }
        fwrite(STDOUT, "exact_prefix_empty=true passes=2\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Provider-path ' . $action . ' failed: ' . $error::class . "\n");
    exit(1);
}
