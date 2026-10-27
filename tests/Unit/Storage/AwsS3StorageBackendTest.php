<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\BoundedCopyStorageBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartPartBackend;
use OpsFour\S3Server\Storage\RecoverableObjectWriteBackend;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmissionCapability;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class AwsS3StorageBackendTest extends TestCase
{
    public function test_admission_only_backend_remains_unwrapped_and_exposes_no_operational_capabilities(): void
    {
        $admission = AwsS3ProviderAdmission::admitted('us-east-1');
        $backend = new AwsS3StorageBackend($admission);
        $observed = StorageTierRegistry::single($backend)->withObservability(new MetricsCollector());

        self::assertSame($backend, $observed->defaultBackend());
        self::assertInstanceOf(AwsS3ProviderAdmissionCapability::class, $observed->defaultBackend());
        self::assertSame($admission, $backend->awsS3ProviderAdmission());
        $backendClass = new \ReflectionClass(AwsS3StorageBackend::class);
        foreach ([
            RecoverableObjectWriteBackend::class,
            RecoverableMultipartPartBackend::class,
            RecoverableMultipartAssemblyBackend::class,
            BoundedCopyStorageBackend::class,
        ] as $forbiddenInterface) {
            self::assertFalse($backendClass->implementsInterface($forbiddenInterface));
        }

        foreach ([
            static fn() => $backend->putObject('bucket', 'key', new ReadableBuffer('body')),
            static fn() => $backend->getObjectByPath('path'),
            static fn() => $backend->deleteObjectByPath('path', 'bucket'),
            static fn() => $backend->createBucket('bucket'),
            static fn() => $backend->deleteBucket('bucket'),
            static fn() => $backend->bucketExists('bucket'),
            static fn() => $backend->putPart('bucket', 'key', 'upload', 1, new ReadableBuffer('part')),
            static fn() => $backend->assembleMultipartUpload('bucket', 'key', 'upload', []),
            static fn() => $backend->abortMultipartUpload('bucket', 'key', 'upload'),
            static fn() => $backend->copyObject('source', 'bucket', 'key'),
        ] as $operation) {
            try {
                $operation();
                self::fail('AWS storage operations must remain unavailable in Story 4.1.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('write fence is complete', $error->getMessage());
            }
        }
    }

    public function test_generic_observed_backend_does_not_claim_aws_provider_identity(): void
    {
        $backend = new ObservedStorageBackend(new InMemoryBackend(), new MetricsCollector(), 'memory');

        self::assertNotContains(AwsS3ProviderAdmissionCapability::class, class_implements($backend));
    }
}
