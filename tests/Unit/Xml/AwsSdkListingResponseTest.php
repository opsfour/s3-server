<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Xml\XmlResponseBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsSdkListingResponseTest extends TestCase
{
    public function test_sdk_deserializes_and_decodes_url_encoded_list_objects_fields(): void
    {
        $xml = XmlResponseBuilder::listObjectsResult([
            'name' => 'bucket',
            'prefix' => 'dir/*',
            'marker' => 'dir/*/before',
            'nextMarker' => 'dir/*/next',
            'maxKeys' => 1,
            'delimiter' => '/',
            'isTruncated' => true,
            'encodingType' => 'url',
            'objects' => [new ObjectInfo(
                'bucket',
                'dir/*/file(1)',
                1,
                '"etag"',
                lastModified: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            )],
            'commonPrefixes' => ['dir/*/nested/'],
        ]);
        $requests = [];
        $handler = new GuzzleHandler(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/xml'], $xml),
            ])),
        ]));
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.mock.invalid',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'retries' => 0,
            'http_handler' => static function (RequestInterface $request, array $options) use ($handler, &$requests) {
                $requests[] = $request;

                return $handler($request, $options);
            },
        ]);

        $result = $client->listObjects(['Bucket' => 'bucket']);

        self::assertCount(1, $requests);
        self::assertStringContainsString('encoding-type=url', $requests[0]->getUri()->getQuery());
        self::assertSame('url', $result['EncodingType']);
        self::assertSame('dir/*', $result['Prefix']);
        self::assertSame('dir/*/before', $result['Marker']);
        self::assertSame('dir/*/next', $result['NextMarker']);
        self::assertSame('/', $result['Delimiter']);
        self::assertSame('dir/*/file(1)', $result['Contents'][0]['Key']);
        self::assertSame('dir/*/nested/', $result['CommonPrefixes'][0]['Prefix']);
    }
}
