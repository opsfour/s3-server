<?php

declare(strict_types=1);

// Invoked only by scripts/run-local-proxy-gate against its disposable nodes.
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
}
require $autoload;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

function gateEnv(string $name): int
{
    $value = getenv($name);
    if ($value === false || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new RuntimeException("Missing or invalid {$name}.");
    }

    return (int) $value;
}

function gateAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function gateS3(int $port, bool $virtual = false): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => sprintf('http://%s:%d', $virtual ? 's3.localhost' : '127.0.0.1', $port),
        'use_path_style_endpoint' => ! $virtual,
        'retries' => 0,
        'credentials' => ['key' => 'testAccessKey', 'secret' => 'testSecretKey'],
        'http' => ['connect_timeout' => 3, 'timeout' => 20],
    ]);
}

function gateResponse(Client $http, RequestInterface $request): ResponseInterface
{
    return $http->send($request, [
        'http_errors' => false,
        'timeout' => 20,
        // The disposable HTTPS listener has an ephemeral self-signed cert.
        'verify' => $request->getUri()->getScheme() !== 'https',
    ]);
}

/** @param array<string, string> $clientHeaders */
function gateSignedGet(Client $http, int $proxyPort, string $bucket, string $key, bool $virtual, ?string $clientProto = null, bool $tls = false, array $clientHeaders = []): ResponseInterface
{
    $host = $virtual ? "{$bucket}.s3.localhost:{$proxyPort}" : "127.0.0.1:{$proxyPort}";
    $path = $virtual ? "/{$key}" : "/{$bucket}/{$key}";
    $scheme = $tls ? 'https' : 'http';
    $request = new Request('GET', "{$scheme}://127.0.0.1:{$proxyPort}{$path}", ['Host' => $host]);
    $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
        $request,
        new Credentials('testAccessKey', 'testSecretKey'),
    );
    if ($clientProto !== null) {
        $signed = $signed->withHeader('X-Forwarded-Proto', $clientProto);
    }
    foreach ($clientHeaders as $name => $value) {
        $signed = $signed->withHeader($name, $value);
    }

    return gateResponse($http, $signed);
}

function gatePresigned(Client $http, S3Client $s3, int $proxyPort, string $operation, string $bucket, string $key, ?string $body = null): ResponseInterface
{
    $command = $s3->getCommand($operation, ['Bucket' => $bucket, 'Key' => $key]);
    $presigned = $s3->createPresignedRequest($command, '+5 minutes');
    $uri = $presigned->getUri();
    $target = 'http://127.0.0.1:' . $proxyPort . $uri->getPath() . '?' . $uri->getQuery();
    $request = new Request(
        $presigned->getMethod(),
        $target,
        ['Host' => $uri->getHost() . ':' . $proxyPort],
        $body ?? '',
    );

    return gateResponse($http, $request);
}

function gateSecureTransportPolicy(Client $http, S3Client $s3, int $httpPort, int $tlsPort, string $bucket): void
{
    $policy = json_encode(['Version' => '2012-10-17', 'Statement' => [[
        'Sid' => 'RequireSecureTransport',
        'Effect' => 'Deny',
        'Principal' => '*',
        'Action' => 's3:GetObject',
        'Resource' => "arn:aws:s3:::{$bucket}/*",
        'Condition' => ['Bool' => ['aws:SecureTransport' => 'false']],
    ]]], JSON_THROW_ON_ERROR);
    $s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => $policy]);
    $secure = gateSignedGet($http, $tlsPort, $bucket, 'signed', false, 'http', true);
    gateAssert($secure->getStatusCode() === 200, 'HTTPS proxy did not override a client-supplied http proto: ' . (string) $secure->getBody());
    $insecure = gateSignedGet($http, $httpPort, $bucket, 'signed', false, 'https');
    gateAssert($insecure->getStatusCode() === 403, 'HTTP proxy did not override a client-supplied https proto.');
}

function gateHostSignatureBoundary(Client $http, int $proxyPort, string $bucket, bool $tls = false): void
{
    $signedHost = "127.0.0.1:{$proxyPort}";
    $valid = gateSignedGet($http, $proxyPort, $bucket, 'signed', false, null, $tls, [
        'X-Forwarded-Host' => 'attacker.example',
    ]);
    gateAssert($valid->getStatusCode() === 200, 'A client-supplied X-Forwarded-Host changed a valid Host signature.');

    $tampered = gateSignedGet($http, $proxyPort, $bucket, 'signed', false, null, $tls, [
        'Host' => "attacker.example:{$proxyPort}",
        'X-Forwarded-Host' => $signedHost,
    ]);
    gateAssert($tampered->getStatusCode() === 403, 'A tampered Host was accepted because X-Forwarded-Host matched the signed host.');
    gateAssert(str_contains((string) $tampered->getBody(), '<Code>SignatureDoesNotMatch</Code>'), 'Host tampering did not fail at SigV4 validation.');
}

function gateSourceIpPolicy(Client $http, S3Client $s3, int $proxyPort, string $bucket): void
{
    // Nginx overwrites the client-supplied XFF with 203.0.113.10. The two
    // policies make a mistaken trust in the spoofed address observable.
    foreach ([
        ['203.0.113.0/24', 200],
        ['198.51.100.0/24', 403],
    ] as [$allowedRange, $expectedStatus]) {
        $policy = json_encode(['Version' => '2012-10-17', 'Statement' => [[
            'Sid' => 'DenyOutsideObservedClientRange',
            'Effect' => 'Deny',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => "arn:aws:s3:::{$bucket}/*",
            'Condition' => ['NotIpAddress' => ['aws:SourceIp' => $allowedRange]],
        ]]], JSON_THROW_ON_ERROR);
        $s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => $policy]);

        $result = gateSignedGet($http, $proxyPort, $bucket, 'signed', false, null, false, [
            'X-Forwarded-For' => '198.51.100.44',
        ]);
        gateAssert($result->getStatusCode() === $expectedStatus, "NotIpAddress used the spoofed XFF for {$allowedRange}: " . (string) $result->getBody());
    }
}

function gateMissingForwardedFor(Client $http, int $nodePort, string $bucket): void
{
    // The direct test connection is in the nodes' trusted loopback CIDR but
    // carries no XFF. Data requests must fail closed; LB health must survive.
    $health = $http->get("http://127.0.0.1:{$nodePort}/.health");
    gateAssert($health->getStatusCode() === 200, 'A trusted management health probe without XFF was rejected.');

    $object = gateSignedGet($http, $nodePort, $bucket, 'signed', false);
    gateAssert($object->getStatusCode() === 403, 'A signed S3 request from a trusted peer without XFF was accepted.');
    gateAssert(str_contains((string) $object->getBody(), '<Code>AccessDenied</Code>'), 'Missing XFF did not fail as S3 AccessDenied.');
}

$phase = $argv[1] ?? '';
gateAssert(in_array($phase, ['baseline', 'drain', 'failover', 'unready', 'all-down', 'policy'], true), 'Unknown local proxy gate phase.');
$proxyPort = gateEnv('S3_TEST_PROXY_PORT');
$tlsProxyPort = gateEnv('S3_TEST_TLS_PROXY_PORT');
$nodeTwoPort = gateEnv('S3_TEST_NODE_TWO_PORT');
$bucket = 'proxy-gate-test';
$http = new Client(['http_errors' => false]);
$pathClient = gateS3($proxyPort);
$virtualClient = gateS3($proxyPort, true);

if ($phase === 'policy') {
    $pathClient->createBucket(['Bucket' => $bucket]);
    $pathClient->putObject(['Bucket' => $bucket, 'Key' => 'signed', 'Body' => 'policy-test']);
    gateMissingForwardedFor($http, $nodeTwoPort, $bucket);
    gateHostSignatureBoundary($http, $proxyPort, $bucket);
    gateSecureTransportPolicy($http, $pathClient, $proxyPort, $tlsProxyPort, $bucket);
    gateSourceIpPolicy($http, $pathClient, $proxyPort, $bucket);
    echo "Real HTTPS/HTTP SecureTransport, signed Host, SourceIp, and missing-XFF checks passed.\n";
    exit(0);
}

if ($phase === 'drain') {
    $nodeOnePort = gateEnv('S3_TEST_NODE_ONE_PORT');
    $nodeOnePid = gateEnv('S3_TEST_NODE_ONE_PID');
    $nodeTwoPid = gateEnv('S3_TEST_NODE_TWO_PID');
    $resultFile = getenv('S3_TEST_DRAIN_RESULT');
    if ($resultFile === false || $resultFile === '') {
        throw new RuntimeException('Missing drain result file.');
    }

    $request = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
        new Request('GET', "https://127.0.0.1:{$tlsProxyPort}/{$bucket}/drain-stream"),
        new Credentials('testAccessKey', 'testSecretKey'),
    );
    $stream = $http->send($request, ['stream' => true, 'http_errors' => false, 'timeout' => 30, 'verify' => false]);
    gateAssert($stream->getStatusCode() === 200, 'Drain stream did not start.');
    $upstream = $stream->getHeaderLine('X-Gate-Upstream');
    $drained = str_contains($upstream, ':' . $nodeOnePort) ? 'one' : 'two';
    gateAssert(str_contains($upstream, ':' . ($drained === 'one' ? $nodeOnePort : $nodeTwoPort)), 'Drain stream has an unknown upstream: ' . $upstream);
    $first = $stream->getBody()->read(64 * 1024);
    gateAssert($first !== '' && ! $stream->getBody()->eof(), 'Drain stream finished before SIGTERM.');
    gateAssert(file_put_contents($resultFile, $drained) !== false, 'Could not record the drained API node.');
    gateAssert(posix_kill($drained === 'one' ? $nodeOnePid : $nodeTwoPid, SIGTERM), 'Could not signal the streaming API node.');

    $digest = hash_init('sha256');
    hash_update($digest, $first);
    $length = strlen($first);
    while (! $stream->getBody()->eof()) {
        $part = $stream->getBody()->read(64 * 1024);
        $length += strlen($part);
        hash_update($digest, $part);
    }
    $expected = str_repeat('0123456789abcdef', 1_048_576); // 16 MiB.
    gateAssert($length === strlen($expected) && hash_final($digest) === hash('sha256', $expected), 'SIGTERM truncated or corrupted an active proxied stream.');
    echo "Active stream drained after SIGTERM on node {$drained}.\n";
    exit(0);
}

if ($phase === 'all-down') {
    $health = $http->get("http://127.0.0.1:{$proxyPort}/.health");
    gateAssert($health->getStatusCode() === 502, 'Proxy did not report 502 with both API targets stopped.');
    $object = gateSignedGet($http, $proxyPort, $bucket, 'signed', false);
    gateAssert($object->getStatusCode() === 502, 'Proxy did not report 502 for a signed request with both targets stopped.');
    echo "All-down proxy behavior passed.\n";
    exit(0);
}

if ($phase === 'unready') {
    $survivorPort = gateEnv('S3_TEST_SURVIVOR_PORT');
    $direct = $http->get("http://127.0.0.1:{$survivorPort}/.health");
    gateAssert($direct->getStatusCode() === 503, 'The surviving API node did not report metadata-readiness 503.');
    $proxied = $http->get("http://127.0.0.1:{$proxyPort}/.health");
    gateAssert($proxied->getStatusCode() === 503, 'The proxy did not pass through readiness 503.');
    $live = $http->get("http://127.0.0.1:{$proxyPort}/.live");
    gateAssert($live->getStatusCode() === 200, 'Liveness failed when only metadata readiness was lost.');
    echo "Readiness 503 and liveness 200 passed through the proxy.\n";
    exit(0);
}

if ($phase === 'baseline') {
    $nodeOnePort = gateEnv('S3_TEST_NODE_ONE_PORT');
    foreach ([$proxyPort, $nodeOnePort, $nodeTwoPort] as $port) {
        $health = $http->get("http://127.0.0.1:{$port}/.health");
        gateAssert($health->getStatusCode() === 200, "Health probe on {$port} did not return 200.");
    }

    $pathClient->createBucket(['Bucket' => $bucket]);
    $pathClient->putObject(['Bucket' => $bucket, 'Key' => 'signed', 'Body' => 'shared-across-nodes']);
    gateAssert((string) $pathClient->getObject(['Bucket' => $bucket, 'Key' => 'signed'])['Body'] === 'shared-across-nodes', 'Path-style SDK round trip failed.');

    $seen = [];
    for ($attempt = 0; $attempt < 12; $attempt++) {
        foreach ([false, true] as $virtual) {
            $result = gateSignedGet($http, $proxyPort, $bucket, 'signed', $virtual);
            gateAssert($result->getStatusCode() === 200, ($virtual ? 'Virtual-hosted' : 'Path-style') . ' signed GET failed: ' . (string) $result->getBody());
            gateAssert((string) $result->getBody() === 'shared-across-nodes', 'Signed GET body mismatch.');
            $seen[$result->getHeaderLine('X-Gate-Upstream')] = true;
        }
    }
    gateAssert(count($seen) >= 2, 'Nginx did not route signed requests to both API nodes: ' . implode(', ', array_keys($seen)));

    foreach ([$pathClient, $virtualClient] as $index => $client) {
        $key = $index === 0 ? 'presigned-path' : 'presigned-virtual';
        $put = gatePresigned($http, $client, $proxyPort, 'PutObject', $bucket, $key, 'presigned-content');
        gateAssert($put->getStatusCode() === 200, "Presigned PUT {$key} failed: " . (string) $put->getBody());
        $get = gatePresigned($http, $client, $proxyPort, 'GetObject', $bucket, $key);
        gateAssert($get->getStatusCode() === 200, "Presigned GET {$key} failed: " . (string) $get->getBody());
        gateAssert((string) $get->getBody() === 'presigned-content', "Presigned GET {$key} body mismatch.");
    }

    $large = str_repeat('0123456789abcdef', 262_144); // 4 MiB through the proxy.
    $pathClient->putObject(['Bucket' => $bucket, 'Key' => 'stream', 'Body' => $large]);
    $pathClient->putObject(['Bucket' => $bucket, 'Key' => 'drain-stream', 'Body' => str_repeat('0123456789abcdef', 1_048_576)]);
    $stream = $http->send(
        (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            new Request('GET', "http://127.0.0.1:{$proxyPort}/{$bucket}/stream"),
            new Credentials('testAccessKey', 'testSecretKey'),
        ),
        ['stream' => true, 'http_errors' => false, 'timeout' => 20],
    );
    gateAssert($stream->getStatusCode() === 200, 'Streaming GET did not return 200.');
    $digest = hash_init('sha256');
    $length = 0;
    while (! $stream->getBody()->eof()) {
        $part = $stream->getBody()->read(64 * 1024);
        $length += strlen($part);
        hash_update($digest, $part);
    }
    gateAssert($length === strlen($large) && hash_final($digest) === hash('sha256', $large), 'Streaming GET was truncated or corrupted.');

    gateSecureTransportPolicy($http, $pathClient, $proxyPort, $tlsProxyPort, $bucket);

    echo "Baseline passed: both upstreams, both host styles, signed and presigned GET/PUT, 4 MiB stream, real HTTPS SecureTransport policy.\n";
} else {
    $survivorPort = gateEnv('S3_TEST_SURVIVOR_PORT');
    $health = $http->get("http://127.0.0.1:{$survivorPort}/.health");
    gateAssert($health->getStatusCode() === 200, 'Surviving node is not healthy.');
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $result = gateSignedGet($http, $tlsProxyPort, $bucket, 'signed', $attempt % 2 === 1, null, true);
        gateAssert($result->getStatusCode() === 200, 'Passive failover signed GET failed: ' . (string) $result->getBody());
        gateAssert((string) $result->getBody() === 'shared-across-nodes', 'Passive failover read wrong data.');
        gateAssert(str_contains($result->getHeaderLine('X-Gate-Upstream'), ':' . $survivorPort), 'Failover request did not reach the surviving node.');
    }
    gateHostSignatureBoundary($http, $tlsProxyPort, $bucket, true);
    gateAssert($pathClient->headBucket(['Bucket' => $bucket])['@metadata']['statusCode'] === 200, 'SDK HEAD did not survive failover.');
    echo "Passive upstream failure routing passed with the second node.\n";
}
