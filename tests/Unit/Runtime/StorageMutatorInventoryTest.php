<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use PHPUnit\Framework\TestCase;

final class StorageMutatorInventoryTest extends TestCase
{
    public function test_registered_aws_capabilities_are_stable(): void
    {
        $inventory = StorageMutatorInventory::forEnabledRuntime(self::liveRegistrations());
        $inventory->assertAwsCapability('api.create-bucket');
        $inventory->assertAwsCapability('worker.access-log');
        $inventory->assertAwsCapability('worker.storage-garbage');
        $inventory->assertAwsCapability('worker.same-key-seal-reconciliation');
        $this->expectException(\LogicException::class);
        $inventory->register('api.put-object', StorageMutatorInventory::ATTEMPT);
    }

    public function test_unknown_and_unsupported_aws_mutators_fail_closed(): void
    {
        $inventory = StorageMutatorInventory::forEnabledRuntime(self::liveRegistrations());
        $rejections = 0;
        foreach (['api.unknown', 'api.abort-multipart', 'integration.generic-storage-mutation'] as $id) {
            try {
                $inventory->assertAwsCapability($id);
                self::fail('AWS mutator unexpectedly admitted: ' . $id);
            } catch (\LogicException) {
                $rejections++;
            }
        }
        self::assertSame(3, $rejections);

        $unknown = new StorageMutatorInventory();
        $this->expectException(\LogicException::class);
        $unknown->register('api.unknown', StorageMutatorInventory::ATTEMPT);
    }

    public function test_missing_live_site_registration_fails_inventory_admission(): void
    {
        $registrations = array_values(array_filter(
            self::liveRegistrations(),
            static fn(array $registration): bool => $registration['id'] !== 'worker.access-log',
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('inventory is incomplete');
        StorageMutatorInventory::forEnabledRuntime($registrations);
    }

    public function test_mutator_must_register_its_exact_capability(): void
    {
        $inventory = new StorageMutatorInventory();
        $this->expectException(\LogicException::class);
        $inventory->register('api.create-bucket', StorageMutatorInventory::ATTEMPT);
    }

    /** @return list<array{id: string, capability: string}> */
    private static function liveRegistrations(): array
    {
        return [
            ...HandlerRegistrar::storageMutatorRegistrations(),
            ...S3Server::storageMutatorRegistrations(),
            ...AwsS3ObjectWriteIntentReconciler::storageMutatorRegistrations(),
        ];
    }
}
