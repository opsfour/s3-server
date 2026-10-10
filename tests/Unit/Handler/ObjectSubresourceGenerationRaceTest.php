<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\GetObjectAclHandler;
use OpsFour\S3Server\Handler\Object\GetObjectLegalHoldHandler;
use OpsFour\S3Server\Handler\Object\GetObjectRetentionHandler;
use OpsFour\S3Server\Handler\Object\GetObjectTaggingHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectSubresourceGenerationRaceTest extends TestCase
{
    public function test_missing_object_resolution_rechecks_reused_bucket_before_reporting_a_status(): void
    {
        /** @var array<string, \Closure(MetadataStore): RequestHandler> $handlers */
        $handlers = [
            'tagging' => static fn(MetadataStore $metadata): RequestHandler => new GetObjectTaggingHandler($metadata),
            'acl' => static fn(MetadataStore $metadata): RequestHandler => new GetObjectAclHandler($metadata),
            'retention' => static fn(MetadataStore $metadata): RequestHandler => new GetObjectRetentionHandler($metadata),
            'legal-hold' => static fn(MetadataStore $metadata): RequestHandler => new GetObjectLegalHoldHandler($metadata),
        ];

        foreach ($handlers as $query => $handlerFactory) {
            foreach (['', '&versionId=missing'] as $versionQuery) {
                $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
                $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
                $currentBucket = $oldBucket;

                $metadata = $this->createMock(MetadataStore::class);
                $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
                    return $currentBucket;
                });
                $metadata->method('getObjectLockConfig')->willReturn(['objectLockEnabled' => 'Enabled']);
                $missing = static function () use (&$currentBucket, $newBucket): null {
                    $currentBucket = $newBucket;
                    return null;
                };
                $metadata->method('getObjectMetadata')->willReturnCallback($missing);
                $metadata->method('getObjectMetadataByVersion')->willReturnCallback($missing);

                try {
                    $handlerFactory($metadata)->handleRequest($this->request($oldBucket, $query . $versionQuery));
                    self::fail("{$query}{$versionQuery} returned after bucket reuse.");
                } catch (OperationAbortedException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function test_missing_object_lock_configuration_rechecks_reused_bucket(): void
    {
        foreach (['retention', 'legal-hold'] as $query) {
            $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
            $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
            $currentBucket = $oldBucket;

            $metadata = $this->createMock(MetadataStore::class);
            $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
                return $currentBucket;
            });
            $metadata->method('getObjectLockConfig')->willReturnCallback(static function () use (&$currentBucket, $newBucket): null {
                $currentBucket = $newBucket;
                return null;
            });

            $handler = $query === 'retention'
                ? new GetObjectRetentionHandler($metadata)
                : new GetObjectLegalHoldHandler($metadata);
            try {
                $handler->handleRequest($this->request($oldBucket, $query));
                self::fail("{$query} returned after bucket reuse.");
            } catch (OperationAbortedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function request(BucketInfo $bucket, string $query): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?' . $query),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);

        return $request;
    }
}
