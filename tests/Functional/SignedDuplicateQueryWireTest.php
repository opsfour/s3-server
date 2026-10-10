<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use OpsFour\S3Server\Http\QueryStringParser;

final class SignedDuplicateQueryWireTest extends S3FunctionalTestCase
{
    public function test_presigned_sdk_object_attributes_keeps_unordered_attribute_list_compatible(): void
    {
        $bucket = 'signed-query-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'data']);
            $command = self::$s3->getCommand('GetObjectAttributes', [
                'Bucket' => $bucket,
                'Key' => 'key',
                'ObjectAttributes' => ['ETag', 'ObjectSize', 'StorageClass', 'Checksum'],
            ]);
            $signed = self::$s3->createPresignedRequest($command, '+5 minutes');
            self::assertCount(4, $signed->getHeader('x-amz-object-attributes'));
            self::assertSame(4, substr_count($signed->getUri()->getQuery(), 'x-amz-object-attributes='));

            $response = (new Client(['http_errors' => false, 'timeout' => 5]))->send($signed);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertStringContainsString('<ObjectSize>4</ObjectSize>', (string) $response->getBody());
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_reordering_conflicting_query_values_cannot_reuse_header_or_presigned_signature(): void
    {
        $bucket = 'signed-query-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'alpha-key', 'Body' => 'a']);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'beta-key', 'Body' => 'b']);

            $url = sprintf('http://%s:%d/%s?list-type=2&prefix=alpha&prefix=beta', self::$host, self::$port, $bucket);
            $unsigned = new Request('GET', $url);
            $signer = new S3SignatureV4('s3', 'us-east-1');
            $credentials = new Credentials(self::$accessKey, self::$secretKey);
            $client = new Client(['http_errors' => false, 'timeout' => 5]);

            foreach ([
                $signer->signRequest($unsigned, $credentials),
                $signer->presign($unsigned, $credentials, '+5 minutes'),
            ] as $signed) {
                $firstQuery = $signed->getUri()->getQuery();
                self::assertSame('alpha', QueryStringParser::parse($firstQuery)['prefix']);
                $reordered = $signed->withUri($signed->getUri()->withQuery(
                    str_replace('prefix=alpha&prefix=beta', 'prefix=beta&prefix=alpha', $firstQuery),
                ));
                self::assertSame('beta', QueryStringParser::parse($reordered->getUri()->getQuery())['prefix']);
                self::assertSame($signed->getHeaderLine('Authorization'), $reordered->getHeaderLine('Authorization'));

                foreach ([$signed, $reordered] as $request) {
                    $response = $client->send($request);
                    self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
                    self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'alpha-key']);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'beta-key']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }
}
