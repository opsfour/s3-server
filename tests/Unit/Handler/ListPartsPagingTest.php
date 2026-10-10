<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Handler\Multipart\ListPartsHandler;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use PHPUnit\Framework\TestCase;

final class ListPartsPagingTest extends TestCase
{
    public function test_wrapped_custom_store_keeps_get_parts_fallback_and_zero_page_skips_it(): void
    {
        $store = $this->createMock(MetadataStore::class);
        $store->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $store->method('getMultipartUpload')->willReturn([
            'upload_id' => 'upload',
            'bucket' => 'bucket',
            'key_name' => 'key',
            'owner_id' => 'owner',
            'user_metadata' => [],
        ]);
        $store->expects(self::once())->method('getParts')->with('upload')->willReturn([
            ['part_number' => 1, 'etag' => 'e1', 'size' => 1, 'storage_path' => 'p1', 'created_at' => '2026-01-01T00:00:00Z'],
            ['part_number' => 3, 'etag' => 'e3', 'size' => 1, 'storage_path' => 'p3', 'created_at' => '2026-01-01T00:00:00Z'],
            ['part_number' => 10, 'etag' => 'e10', 'size' => 1, 'storage_path' => 'p10', 'created_at' => '2026-01-01T00:00:00Z'],
        ]);
        $handler = new ListPartsHandler(new ObservedMetadataStore(
            new CachedMetadataStoreDecorator($store),
            new MetricsCollector(),
            'custom',
        ));

        $zero = self::xml($handler->handleRequest($this->request('max-parts=0'))->getBody());
        self::assertSame('false', (string) $zero->IsTruncated);
        self::assertCount(0, $zero->Part);

        $page = self::xml($handler->handleRequest($this->request('max-parts=1&part-number-marker=2'))->getBody());
        self::assertSame('3', (string) $page->Part[0]->PartNumber);
        self::assertSame('true', (string) $page->IsTruncated);
        self::assertSame('3', (string) $page->NextPartNumberMarker);

        $beyond = self::xml($handler->handleRequest($this->request('max-parts=1&part-number-marker=' . PHP_INT_MAX))->getBody());
        self::assertSame('false', (string) $beyond->IsTruncated);
        self::assertCount(0, $beyond->Part);
    }

    private function request(string $query): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&' . $query),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    private static function xml(\Amp\ByteStream\ReadableStream $body): \SimpleXMLElement
    {
        return new \SimpleXMLElement($body->read() ?? '');
    }
}
