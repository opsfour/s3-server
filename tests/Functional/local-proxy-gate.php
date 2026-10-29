<?php

declare(strict_types=1);

// Invoked only by scripts/run-local-proxy-gate against disposable local processes.
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Run composer install before the local proxy gate.');
}
require $autoload;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

function gatePort(string $name): int
{
    $value = getenv($name);
    if ($value === false || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new RuntimeException("Missing or invalid {$name}.");
    }

    return (int) $value;
}

function gateProcessId(string $name): int
{
    return gatePort($name);
}

function gateSecret(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing {$name}.");
    }

    return $value;
}

function gateAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function gateS3(int $proxyPort, bool $virtualHosted = false): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => sprintf('http://%s:%d', $virtualHosted ? 's3.localhost' : '127.0.0.1', $proxyPort),
        'use_path_style_endpoint' => ! $virtualHosted,
        'retries' => 0,
        'credentials' => ['key' => 'testAccessKey', 'secret' => 'testSecretKey'],
        'http' => ['connect_timeout' => 3, 'timeout' => 15],
    ]);
}

/** @param array<string, string> $headers */
function gateSignedGet(
    Client $http,
    int $port,
    string $bucket,
    string $key,
    bool $virtualHosted = false,
    array $headers = [],
): ResponseInterface {
    $host = $virtualHosted ? "{$bucket}.s3.localhost:{$port}" : "127.0.0.1:{$port}";
    $path = $virtualHosted ? "/{$key}" : "/{$bucket}/{$key}";
    $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
        new Request('GET', "http://127.0.0.1:{$port}{$path}", ['Host' => $host]),
        new Credentials('testAccessKey', 'testSecretKey'),
    );
    foreach ($headers as $name => $value) {
        $signed = $signed->withHeader($name, $value);
    }

    return $http->send($signed, ['http_errors' => false, 'timeout' => 15]);
}

function gatePresigned(
    Client $http,
    S3Client $s3,
    int $proxyPort,
    string $operation,
    string $bucket,
    string $key,
    string $body = '',
): ResponseInterface {
    $presigned = $s3->createPresignedRequest(
        $s3->getCommand($operation, ['Bucket' => $bucket, 'Key' => $key]),
        '+5 minutes',
    );
    $uri = $presigned->getUri();
    $target = "http://127.0.0.1:{$proxyPort}{$uri->getPath()}";
    if ($uri->getQuery() !== '') {
        $target .= '?' . $uri->getQuery();
    }
    $request = new Request(
        $presigned->getMethod(),
        $target,
        ['Host' => $uri->getAuthority()],
        $body,
    );

    return $http->send($request, ['http_errors' => false, 'timeout' => 15]);
}

function gateDirectRequest(
    Client $http,
    int $originPort,
    string $method,
    string $path,
    string $body = '',
): ResponseInterface {
    $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
        new Request(
            $method,
            "http://127.0.0.1:{$originPort}{$path}",
            ['Host' => "127.0.0.1:{$originPort}"],
            $body,
        ),
        new Credentials('testAccessKey', 'testSecretKey'),
    );
    $signed = $signed
        ->withHeader('X-Forwarded-For', '198.51.100.42')
        ->withHeader('X-Forwarded-Proto', 'https');

    return $http->send($signed, [
        'connect_timeout' => 1,
        'http_errors' => false,
        'timeout' => 3,
    ]);
}

function gateApiWriteReady(Client $http, int $originPort, string $bucket): void
{
    $created = gateDirectRequest($http, $originPort, 'PUT', "/{$bucket}");
    gateAssert($created->getStatusCode() === 200, 'Direct readiness bucket write failed.');

    $body = 'write-ready';
    $stored = gateDirectRequest($http, $originPort, 'PUT', "/{$bucket}/.gate-ready", $body);
    gateAssert($stored->getStatusCode() === 200, 'Direct readiness object write failed.');

    $read = gateDirectRequest($http, $originPort, 'GET', "/{$bucket}/.gate-ready");
    gateAssert($read->getStatusCode() === 200 && (string) $read->getBody() === $body, 'Direct readiness object read failed.');
}

/** @return array<string, mixed> */
function gatePolicy(string $bucket): array
{
    return [
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => "arn:aws:s3:::{$bucket}/*",
                'Condition' => ['Bool' => ['aws:SecureTransport' => 'false']],
            ],
            [
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => "arn:aws:s3:::{$bucket}/*",
                'Condition' => ['NotIpAddress' => ['aws:SourceIp' => '198.51.100.0/24']],
            ],
        ],
    ];
}

$originPort = gatePort('S3_TEST_ORIGIN_PORT');
$bucket = 'proxy-gate-test';
$http = new Client(['http_errors' => false]);
if (($argv[1] ?? '') === 'readiness') {
    try {
        gateApiWriteReady($http, $originPort, $bucket);
    } catch (Throwable) {
        exit(1);
    }
    echo "API write path is ready.\n";
    exit(0);
}

$proxyPort = gatePort('S3_TEST_PROXY_PORT');
$rewritePort = gatePort('S3_TEST_REWRITE_PROXY_PORT');
$missingXffPort = gatePort('S3_TEST_MISSING_XFF_PROXY_PORT');
$ambiguousXfpPort = gatePort('S3_TEST_AMBIGUOUS_XFP_PROXY_PORT');
$pathClient = gateS3($proxyPort);

if (($argv[1] ?? '') === 'drain') {
    $originPid = gateProcessId('S3_TEST_ORIGIN_PID');
    gateAssert(posix_kill($originPid, SIGTERM), 'Could not signal the API process for drain.');

    $deadline = microtime(true) + 1.5;
    do {
        $health = $http->get("http://127.0.0.1:{$proxyPort}/.health", ['http_errors' => false]);
        if ($health->getStatusCode() === 503) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    gateAssert($health->getStatusCode() === 503, 'Drain did not remove readiness before listener shutdown.');
    gateAssert(
        $http->get("http://127.0.0.1:{$proxyPort}/.live", ['http_errors' => false])->getStatusCode() === 200,
        'Liveness was not reachable during drain propagation.',
    );
    gateAssert(
        $http->get("http://127.0.0.1:{$proxyPort}/.metrics", ['http_errors' => false])->getStatusCode() === 401,
        'Metrics bearer authentication was bypassed during drain propagation.',
    );
    gateAssert(
        $http->get("http://127.0.0.1:{$proxyPort}/.metrics", [
            'headers' => ['Authorization' => 'Bearer ' . gateSecret('S3_TEST_METRICS_TOKEN')],
            'http_errors' => false,
        ])->getStatusCode() === 200,
        'Authenticated metrics were not reachable during drain propagation.',
    );
    $raced = gateSignedGet($http, $proxyPort, $bucket, 'signed');
    gateAssert($raced->getStatusCode() === 503, 'New S3 work was admitted after drain began.');
    gateAssert(str_contains((string) $raced->getBody(), '<Code>SlowDown</Code>'), 'Drain rejection was not a bounded S3 response.');

    echo "Local proxy drain passed: readiness closed before listener shutdown; liveness, metrics, and bounded admission remained available.\n";
    exit(0);
}

$health = $http->get("http://127.0.0.1:{$missingXffPort}/.health");
gateAssert($health->getStatusCode() === 200, 'Health through a trusted proxy without XFF was rejected.');

gateAssert(
    $pathClient->headBucket(['Bucket' => $bucket])['@metadata']['statusCode'] === 200,
    'Proxied HeadBucket failed after direct write readiness.',
);
$pathClient->putObject(['Bucket' => $bucket, 'Key' => 'signed', 'Body' => 'proxy-gate-content']);

foreach ([false, true] as $virtualHosted) {
    $response = gateSignedGet($http, $proxyPort, $bucket, 'signed', $virtualHosted);
    $style = $virtualHosted ? 'virtual-hosted' : 'path-style';
    gateAssert($response->getStatusCode() === 200, "Signed {$style} GET failed: " . (string) $response->getBody());
    gateAssert((string) $response->getBody() === 'proxy-gate-content', "Signed {$style} GET returned the wrong body.");
}

$signedPath = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
    new Request(
        'GET',
        "http://127.0.0.1:{$proxyPort}/{$bucket}/signed",
        ['Host' => "127.0.0.1:{$proxyPort}"],
    ),
    new Credentials('testAccessKey', 'testSecretKey'),
);
$mutatedPath = $signedPath->withUri($signedPath->getUri()->withPath("/{$bucket}/signed-mutated"));
$pathMutation = $http->send($mutatedPath, ['http_errors' => false, 'timeout' => 15]);
gateAssert($pathMutation->getStatusCode() === 403, 'A signed path mutation did not invalidate the signature.');
gateAssert(str_contains((string) $pathMutation->getBody(), '<Code>SignatureDoesNotMatch</Code>'), 'Path mutation failed outside SigV4 validation.');

$presignedMutation = $pathClient->createPresignedRequest(
    $pathClient->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => 'signed']),
    '+5 minutes',
);
$mutationUri = $presignedMutation->getUri();
$query = $mutationUri->getQuery();
$mutatedQuery = preg_replace('/X-Amz-Expires=[0-9]+/', 'X-Amz-Expires=301', $query, 1, $count);
gateAssert(is_string($mutatedQuery) && $count === 1, 'Could not prepare the signed query mutation.');
$queryMutation = $http->send(new Request(
    $presignedMutation->getMethod(),
    "http://127.0.0.1:{$proxyPort}{$mutationUri->getPath()}?{$mutatedQuery}",
    ['Host' => $mutationUri->getAuthority()],
), ['http_errors' => false, 'timeout' => 15]);
gateAssert($queryMutation->getStatusCode() === 403, 'A presigned query mutation did not invalidate the signature.');
gateAssert(str_contains((string) $queryMutation->getBody(), '<Code>SignatureDoesNotMatch</Code>'), 'Query mutation failed outside SigV4 validation.');

foreach ([false, true] as $virtualHosted) {
    $client = gateS3($proxyPort, $virtualHosted);
    $style = $virtualHosted ? 'virtual' : 'path';
    $key = "presigned-{$style}";
    $put = gatePresigned($http, $client, $proxyPort, 'PutObject', $bucket, $key, "{$style}-body");
    gateAssert($put->getStatusCode() === 200, "Presigned {$style} PUT failed: " . (string) $put->getBody());
    $get = gatePresigned($http, $client, $proxyPort, 'GetObject', $bucket, $key);
    gateAssert($get->getStatusCode() === 200, "Presigned {$style} GET failed: " . (string) $get->getBody());
    gateAssert((string) $get->getBody() === "{$style}-body", "Presigned {$style} GET returned the wrong body.");
}

$rewritten = gateSignedGet($http, $rewritePort, $bucket, 'signed');
gateAssert($rewritten->getStatusCode() === 403, 'A proxy Host rewrite did not invalidate the signature.');
gateAssert(
    str_contains((string) $rewritten->getBody(), '<Code>SignatureDoesNotMatch</Code>'),
    'A proxy Host rewrite failed outside SigV4 Host validation.',
);

$missingXff = gateSignedGet($http, $missingXffPort, $bucket, 'signed');
gateAssert($missingXff->getStatusCode() === 403, 'A trusted proxy S3 request without XFF did not fail closed.');

$pathClient->putBucketPolicy([
    'Bucket' => $bucket,
    'Policy' => json_encode(gatePolicy($bucket), JSON_THROW_ON_ERROR),
]);

// Apache must replace client forwarding headers with its observed values.
$spoofed = gateSignedGet($http, $proxyPort, $bucket, 'signed', false, [
    'X-Forwarded-For' => '203.0.113.99',
    'X-Forwarded-Proto' => 'http',
    'X-Forwarded-Host' => 'attacker.example',
]);
gateAssert($spoofed->getStatusCode() === 200, 'The proxy did not replace spoofed forwarding headers.');

$ambiguousXfp = gateSignedGet($http, $ambiguousXfpPort, $bucket, 'signed');
gateAssert($ambiguousXfp->getStatusCode() === 403, 'Ambiguous trusted-proxy XFP did not fail closed.');

$directMissing = gateSignedGet($http, $originPort, $bucket, 'signed');
gateAssert($directMissing->getStatusCode() === 403, 'A direct S3 request from a trusted peer without XFF was accepted.');

echo "Local single-process proxy gate passed: signed path and virtual hosts, presigned PUT/GET, exact Host/path/query failures, trusted XFF/XFP, policy context, and health.\n";
