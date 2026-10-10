<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Psr7\Request as PsrRequest;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/** Signed HTTP writes must remain readable when the default physical tier is not STANDARD. */
final class DefaultWriteTierPlacementTest extends TestCase
{
    public function test_signed_put_copy_and_multipart_completion_publish_the_actual_default_tier(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'tier-placement', 'us-east-1');
        $standard = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $standard->createBucket('tier-placement');
        $cold->createBucket('tier-placement');
        $port = self::unusedPort();
        $runtime = (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: sys_get_temp_dir(),
                perClientRateLimit: 0,
                notificationWorkerEnabled: false,
                enforceMinPartSize: false,
            ),
            metadata: $metadata,
            storage: $cold,
            credentialProvider: new InMemoryCredentialProvider(new Credential('tier-key', 'tier-secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
            storageTiers: new StorageTierRegistry([
                new StorageTier('STANDARD', $standard),
                new StorageTier('COLD', $cold, defaultWriteTier: true),
            ]),
            encryption: new EncryptionService(new ConfigMasterKeyProvider(base64_encode(str_repeat('K', 32)))),
        );
        $secondRuntime = null;
        // Earlier PHPUnit tests can leave allocator pages reserved near the
        // 128 MiB default, causing a 17-byte SSE PUT to fail its memory guard.
        $previousMemoryLimit = ini_set('memory_limit', '256M');
        if ($previousMemoryLimit === false) {
            throw new \RuntimeException('Unable to reserve memory headroom for SSE integration test.');
        }

        try {
            $runtime->server->start();
            $client = (new HttpClientBuilder())->retry(0)->build();
            $sdk = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => "http://127.0.0.1:{$port}",
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => 'tier-key', 'secret' => 'tier-secret'],
            ]);

            self::assertSame(200, self::signed($sdk, $client, 'PutObject', [
                'Bucket' => 'tier-placement', 'Key' => 'put', 'Body' => 'put-payload',
            ])->getStatus());
            self::assertSame('put-payload', self::read($sdk, $client, 'put'));
            self::assertSame(200, self::signed($sdk, $client, 'HeadObject', [
                'Bucket' => 'tier-placement', 'Key' => 'put',
            ])->getStatus());
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'put')?->storageTier);

            $metadata->setBucketVersioning('tier-placement', 'Enabled');
            self::assertSame(200, self::signed($sdk, $client, 'CopyObject', [
                'Bucket' => 'tier-placement', 'Key' => 'copy', 'CopySource' => 'tier-placement/put',
            ])->getStatus());
            self::assertSame('put-payload', self::read($sdk, $client, 'copy'));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'copy')?->storageTier);

            // A second API node has a different default. Existing uploads must
            // keep the tier chosen by the node that created them.
            $secondPort = self::unusedPort();
            $secondRuntime = (new S3ServerRuntimeFactory())->create(
                config: new S3ServerConfig(
                    host: '127.0.0.1',
                    port: $secondPort,
                    storagePath: sys_get_temp_dir(),
                    perClientRateLimit: 0,
                    notificationWorkerEnabled: false,
                    enforceMinPartSize: false,
                ),
                metadata: $metadata,
                storage: $standard,
                credentialProvider: new InMemoryCredentialProvider(new Credential('tier-key', 'tier-secret', 'owner')),
                logger: new NullLogger(),
                metrics: new MetricsCollector(),
                storageTiers: new StorageTierRegistry([
                    new StorageTier('STANDARD', $standard, defaultWriteTier: true),
                    new StorageTier('COLD', $cold),
                ]),
                encryption: new EncryptionService(new ConfigMasterKeyProvider(base64_encode(str_repeat('K', 32)))),
            );
            $secondRuntime->server->start();
            $secondSdk = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => "http://127.0.0.1:{$secondPort}",
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => 'tier-key', 'secret' => 'tier-secret'],
            ]);

            $create = self::signed($sdk, $client, 'CreateMultipartUpload', [
                'Bucket' => 'tier-placement', 'Key' => 'multipart',
            ]);
            self::assertSame(200, $create->getStatus());
            $xml = new \SimpleXMLElement(ByteStream\buffer($create->getBody()));
            $uploadId = (string) $xml->UploadId;
            self::assertNotSame('', $uploadId);

            $part = self::signed($secondSdk, $client, 'UploadPart', [
                'Bucket' => 'tier-placement', 'Key' => 'multipart',
                'UploadId' => $uploadId, 'PartNumber' => 1, 'Body' => 'multipart-payload',
            ]);
            self::assertSame(200, $part->getStatus());
            $etag = $part->getHeader('etag');
            self::assertNotNull($etag);
            $complete = self::signed($secondSdk, $client, 'CompleteMultipartUpload', [
                'Bucket' => 'tier-placement', 'Key' => 'multipart', 'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $etag]]],
            ]);
            self::assertSame(200, $complete->getStatus(), ByteStream\buffer($complete->getBody()));
            self::assertSame('multipart-payload', self::read($sdk, $client, 'multipart'));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'multipart')?->storageTier);
            self::assertSame(200, self::signed($sdk, $client, 'HeadObject', [
                'Bucket' => 'tier-placement', 'Key' => 'multipart',
            ])->getStatus());

            $copyCreate = self::signed($sdk, $client, 'CreateMultipartUpload', [
                'Bucket' => 'tier-placement', 'Key' => 'part-copy',
            ]);
            self::assertSame(200, $copyCreate->getStatus());
            $copyXml = new \SimpleXMLElement(ByteStream\buffer($copyCreate->getBody()));
            $copyUploadId = (string) $copyXml->UploadId;
            self::assertNotSame('', $copyUploadId);
            $copiedPart = self::signed($secondSdk, $client, 'UploadPartCopy', [
                'Bucket' => 'tier-placement', 'Key' => 'part-copy',
                'UploadId' => $copyUploadId, 'PartNumber' => 1,
                'CopySource' => 'tier-placement/put',
            ]);
            $copiedPartBody = ByteStream\buffer($copiedPart->getBody());
            self::assertSame(200, $copiedPart->getStatus(), $copiedPartBody);
            $copiedXml = new \SimpleXMLElement($copiedPartBody);
            $copiedEtag = (string) $copiedXml->ETag;
            self::assertNotSame('', $copiedEtag);
            $copyComplete = self::signed($secondSdk, $client, 'CompleteMultipartUpload', [
                'Bucket' => 'tier-placement', 'Key' => 'part-copy', 'UploadId' => $copyUploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $copiedEtag]]],
            ]);
            self::assertSame(200, $copyComplete->getStatus(), ByteStream\buffer($copyComplete->getBody()));
            self::assertSame('put-payload', self::read($sdk, $client, 'part-copy'));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'part-copy')?->storageTier);

            $encryptedPut = self::signed($sdk, $client, 'PutObject', [
                'Bucket' => 'tier-placement', 'Key' => 'encrypted-put',
                'Body' => 'encrypted-payload', 'ServerSideEncryption' => 'AES256',
            ]);
            self::assertSame(200, $encryptedPut->getStatus(), ByteStream\buffer($encryptedPut->getBody()));
            self::assertSame('encrypted-payload', self::read($sdk, $client, 'encrypted-put'));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'encrypted-put')?->storageTier);

            self::assertSame(200, self::signed($sdk, $client, 'CopyObject', [
                'Bucket' => 'tier-placement', 'Key' => 'encrypted-copy',
                'CopySource' => 'tier-placement/encrypted-put', 'ServerSideEncryption' => 'AES256',
            ])->getStatus());
            self::assertSame('encrypted-payload', self::read($sdk, $client, 'encrypted-copy'));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'encrypted-copy')?->storageTier);

            $encryptedCreate = self::signed($sdk, $client, 'CreateMultipartUpload', [
                'Bucket' => 'tier-placement', 'Key' => 'encrypted-multipart',
                'ServerSideEncryption' => 'AES256',
            ]);
            self::assertSame(200, $encryptedCreate->getStatus());
            $encryptedXml = new \SimpleXMLElement(ByteStream\buffer($encryptedCreate->getBody()));
            $encryptedUploadId = (string) $encryptedXml->UploadId;
            self::assertNotSame('', $encryptedUploadId);
            $encryptedPart = self::signed($secondSdk, $client, 'UploadPart', [
                'Bucket' => 'tier-placement', 'Key' => 'encrypted-multipart',
                'UploadId' => $encryptedUploadId, 'PartNumber' => 1, 'Body' => 'encrypted-multipart-payload',
            ]);
            self::assertSame(200, $encryptedPart->getStatus());
            $encryptedEtag = $encryptedPart->getHeader('etag');
            self::assertNotNull($encryptedEtag);
            $encryptedComplete = self::signed($secondSdk, $client, 'CompleteMultipartUpload', [
                'Bucket' => 'tier-placement', 'Key' => 'encrypted-multipart', 'UploadId' => $encryptedUploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $encryptedEtag]]],
            ]);
            self::assertSame(200, $encryptedComplete->getStatus(), ByteStream\buffer($encryptedComplete->getBody()));
            self::assertSame('encrypted-multipart-payload', self::read($sdk, $client, 'encrypted-multipart'));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'encrypted-multipart')?->storageTier);

            $customerKey = str_repeat('C', 32);
            $customerHeaders = [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
                'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
            ];
            $customerCreate = self::signedRaw($client, 'POST', "http://127.0.0.1:{$port}/tier-placement/customer-multipart?uploads", $customerHeaders);
            self::assertSame(200, $customerCreate->getStatus());
            $customerXml = new \SimpleXMLElement(ByteStream\buffer($customerCreate->getBody()));
            $customerUploadId = (string) $customerXml->UploadId;
            self::assertNotSame('', $customerUploadId);
            $customerPart = self::signedRaw(
                $client,
                'PUT',
                "http://127.0.0.1:{$secondPort}/tier-placement/customer-multipart?partNumber=1&uploadId={$customerUploadId}",
                $customerHeaders,
                'customer-payload',
            );
            self::assertSame(200, $customerPart->getStatus());
            $customerEtag = $customerPart->getHeader('etag');
            self::assertNotNull($customerEtag);
            $completionBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
                . htmlspecialchars($customerEtag, ENT_XML1)
                . '</ETag></Part></CompleteMultipartUpload>';
            $customerComplete = self::signedRaw(
                $client,
                'POST',
                "http://127.0.0.1:{$secondPort}/tier-placement/customer-multipart?uploadId={$customerUploadId}",
                $customerHeaders + ['content-type' => 'application/xml'],
                $completionBody,
            );
            self::assertSame(200, $customerComplete->getStatus(), ByteStream\buffer($customerComplete->getBody()));
            $customerGet = self::signedRaw($client, 'GET', "http://127.0.0.1:{$port}/tier-placement/customer-multipart", $customerHeaders);
            self::assertSame(200, $customerGet->getStatus());
            self::assertSame('customer-payload', ByteStream\buffer($customerGet->getBody()));
            self::assertSame('COLD', $metadata->getObjectMetadata('tier-placement', 'customer-multipart')?->storageTier);
        } finally {
            try {
                $secondRuntime?->stop();
                $runtime->stop();
            } finally {
                ini_set('memory_limit', $previousMemoryLimit);
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function signed(S3Client $sdk, HttpClient $client, string $operation, array $arguments): Response
    {
        $signed = $sdk->createPresignedRequest($sdk->getCommand($operation, $arguments), '+5 minutes');

        return self::sendSigned($client, $signed);
    }

    /** @param array<string, string> $headers */
    private static function signedRaw(HttpClient $client, string $method, string $url, array $headers, string $body = ''): Response
    {
        $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            new PsrRequest($method, $url, $headers, $body),
            new Credentials('tier-key', 'tier-secret'),
        );

        return self::sendSigned($client, $signed);
    }

    private static function sendSigned(HttpClient $client, RequestInterface $signed): Response
    {
        $method = $signed->getMethod();
        if ($method === '') {
            throw new \UnexpectedValueException('The signed request has no method.');
        }
        $request = new Request((string) $signed->getUri(), $method, (string) $signed->getBody());
        foreach ($signed->getHeaders() as $name => $values) {
            if (! is_string($name) || $name === '') {
                throw new \UnexpectedValueException('The signed request has an invalid header name.');
            }
            if (strtolower($name) !== 'host') {
                $request->setHeader($name, $values);
            }
        }
        $request->setTransferTimeout(10);

        return $client->request($request);
    }

    private static function read(S3Client $sdk, HttpClient $client, string $key): string
    {
        $response = self::signed($sdk, $client, 'GetObject', [
            'Bucket' => 'tier-placement', 'Key' => $key,
        ]);
        $body = ByteStream\buffer($response->getBody());
        self::assertSame(200, $response->getStatus(), $body);

        return $body;
    }

    private static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
