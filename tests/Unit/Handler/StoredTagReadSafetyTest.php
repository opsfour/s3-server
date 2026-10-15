<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Bucket\GetBucketTaggingHandler;
use OpsFour\S3Server\Handler\Object\GetObjectTaggingHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class StoredTagReadSafetyTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            7,
            '"321c3cf486ed509164edec1e1981fec8"',
            'text/plain',
            'stored/path',
        );
    }

    public function test_get_bucket_tagging_rejects_invalid_legacy_tags_before_xml_serialization(): void
    {
        $this->metadata->putBucketTagging('bucket', [
            ['key' => 'legacy', 'value' => "control\u{000B}"],
        ]);

        $request = $this->request('http://localhost/bucket?tagging');
        $request->setAttribute('s3.bucket', 'bucket');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored tag metadata is invalid.');
        (new GetBucketTaggingHandler($this->metadata))->handleRequest($request);
    }

    public function test_get_object_tagging_rejects_invalid_legacy_tags_before_xml_serialization(): void
    {
        $this->metadata->putObjectTagging('bucket', 'key', [
            ['key' => "legacy\u{FDD0}", 'value' => 'value'],
        ]);

        $request = $this->request('http://localhost/bucket/key?tagging');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored tag metadata is invalid.');
        (new GetObjectTaggingHandler($this->metadata))->handleRequest($request);
    }

    private function request(string $uri): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new($uri),
        );
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
