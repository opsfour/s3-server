<?php

declare(strict_types=1);

/**
 * One signed package-API GET with private, URL-free timing output.
 * The target object must already exist. Run only against a loopback API.
 */

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Aws\S3\S3Client;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$required = [
    'S3_GATE_API_ENDPOINT',
    'S3_GATE_BUCKET',
    'S3_GATE_KEY',
    'S3_GATE_ACCESS_KEY',
    'S3_GATE_SECRET_KEY',
    'S3_GATE_EXPECT_STATUS',
];
$values = [];
foreach ($required as $name) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing {$name}.\n");
        exit(2);
    }
    $values[$name] = $value;
}

$endpoint = parse_url($values['S3_GATE_API_ENDPOINT']);
if (! is_array($endpoint)
    || ! in_array($endpoint['scheme'] ?? '', ['http', 'https'], true)
    || ! in_array($endpoint['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || isset($endpoint['user'])
    || isset($endpoint['pass'])
    || preg_match('/^\d{3}$/', $values['S3_GATE_EXPECT_STATUS']) !== 1) {
    fwrite(STDERR, "Probe requires a loopback API endpoint and a three-digit expected status.\n");
    exit(2);
}

$region = getenv('S3_GATE_REGION') ?: 'us-east-1';
$sdk = new S3Client([
    'version' => 'latest',
    'region' => $region,
    'endpoint' => $values['S3_GATE_API_ENDPOINT'],
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => $values['S3_GATE_ACCESS_KEY'],
        'secret' => $values['S3_GATE_SECRET_KEY'],
    ],
]);
$url = (string) $sdk->createPresignedRequest($sdk->getCommand('GetObject', [
    'Bucket' => $values['S3_GATE_BUCKET'],
    'Key' => $values['S3_GATE_KEY'],
]), '+5 minutes')->getUri();
$client = (new HttpClientBuilder())->retry(0)->build();
$request = new Request($url);
$request->setTransferTimeout(30);
$started = hrtime(true);
$record = [
    'utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'status' => null,
    'headers_ms' => null,
    'first_body_ms' => null,
    'total_ms' => null,
    'body_bytes' => 0,
    'body_sha256' => null,
    'request_id' => null,
    'error_class' => null,
];
try {
    $response = $client->request($request);
    $record['status'] = $response->getStatus();
    $record['headers_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
    $record['request_id'] = $response->getHeader('x-amz-request-id');
    $hash = hash_init('sha256');
    while (($chunk = $response->getBody()->read()) !== null) {
        if ($record['first_body_ms'] === null) {
            $record['first_body_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
        }
        $record['body_bytes'] += strlen($chunk);
        hash_update($hash, $chunk);
    }
    $record['body_sha256'] = hash_final($hash);
} catch (Throwable $error) {
    $record['error_class'] = $error::class;
}
$record['total_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");

$expectedHash = getenv('S3_GATE_EXPECT_SHA256');
if ($record['status'] !== (int) $values['S3_GATE_EXPECT_STATUS']
    || $record['error_class'] !== null
    || ($expectedHash !== false && $expectedHash !== '' && $record['body_sha256'] !== $expectedHash)) {
    exit(1);
}
