<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\AwsS3GarbageClaimStore;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use PHPUnit\Framework\TestCase;

final class AwsS3GarbageClaimDecoratorTest extends TestCase
{
    public function test_runtime_metadata_decorators_forward_the_atomic_aws_garbage_capability(): void
    {
        $inner = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            AwsS3GarbageClaimStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $inner);
        $inner->expects(self::exactly(2))
            ->method('deleteAwsGarbageClaim')
            ->willReturn(true);
        $cached = new CachedMetadataStoreDecorator($inner);
        $observed = new ObservedMetadataStore($cached, new MetricsCollector(), 'postgres');
        $backend = new AwsS3StorageBackend(AwsS3ProviderAdmission::denied(
            'us-east-1',
            true,
            true,
            AwsS3ProviderAdmission::VERSIONING_UNKNOWN,
            AwsS3ProviderAdmission::REASON_VERSIONING_CHECK_FAILED,
        ));

        foreach ([$cached, $observed] as $metadata) {
            self::assertInstanceOf(AwsS3GarbageClaimStore::class, $metadata);
            self::assertTrue($backend->deleteClaimedGarbage(
                $metadata,
                7,
                'bucket',
                'STANDARD',
                'path',
                'owner',
                3,
                static fn(): float => 123.0,
                static function (): void {},
            ));
        }
    }
}
