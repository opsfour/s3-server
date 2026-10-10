<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaAdmission;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\EfsCandidateMetadataStartupAdmission;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class EfsCandidateMetadataStartupAdmissionTest extends TestCase
{
    public function test_driver_mismatch_fails_before_metadata_queries(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');

        try {
            EfsCandidateMetadataStartupAdmission::assertReady(
                new S3ServerConfig(storagePath: sys_get_temp_dir(), metadataDriver: 'mysql'),
                $metadata,
                StorageTierRegistry::single(new InMemoryBackend()),
            );
            self::fail('A candidate using another metadata driver was admitted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('does not match injected sqlite metadata store', $error->getMessage());
        }
    }

    public function test_stale_schema_fails_before_tier_inventory(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MetadataSchemaAdmission::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        self::assertInstanceOf(MetadataSchemaAdmission::class, $metadata);
        $metadata->expects(self::once())
            ->method('assertCurrentSchema')
            ->willThrowException(new \RuntimeException('Metadata schema is stale.'));
        $metadata->expects(self::never())->method('listReferencedStorageTiers');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Metadata schema is stale.');
        EfsCandidateMetadataStartupAdmission::assertReady(
            new S3ServerConfig(storagePath: sys_get_temp_dir(), metadataDriver: 'mysql'),
            $metadata,
            StorageTierRegistry::single(new InMemoryBackend()),
        );
    }

    public function test_unconfigured_referenced_tier_fails_without_disclosing_its_name(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())
            ->method('listReferencedStorageTiers')
            ->willReturn(['STANDARD', 'private-cold-tier']);

        try {
            EfsCandidateMetadataStartupAdmission::assertReady(
                new S3ServerConfig(storagePath: sys_get_temp_dir(), metadataDriver: 'mysql'),
                $metadata,
                StorageTierRegistry::single(new InMemoryBackend()),
            );
            self::fail('A candidate missing a referenced storage tier was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS candidate metadata references an unconfigured storage tier.', $error->getMessage());
            self::assertStringNotContainsString('private-cold-tier', $error->getMessage());
        }
    }

    public function test_all_referenced_configured_tiers_are_admitted(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())
            ->method('listReferencedStorageTiers')
            ->willReturn(['STANDARD']);

        EfsCandidateMetadataStartupAdmission::assertReady(
            new S3ServerConfig(storagePath: sys_get_temp_dir(), metadataDriver: 'mysql'),
            $metadata,
            StorageTierRegistry::single(new InMemoryBackend()),
        );

        self::addToAssertionCount(1);
    }
}
