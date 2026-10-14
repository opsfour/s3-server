<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerReadLock;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use PHPUnit\Framework\TestCase;

final class OwnerReadLockTest extends TestCase
{
    public function test_capability_prepares_and_acquires_unique_owner_locks_in_stable_order(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        self::assertInstanceOf(SharedAuthorizationLockStore::class, $metadata);
        $prepared = [];
        $acquired = [];
        $metadata->expects(self::exactly(2))
            ->method('prepareOwnerReadLock')
            ->willReturnCallback(static function (string $ownerId) use (&$prepared): void {
                $prepared[] = $ownerId;
            });
        $metadata->expects(self::exactly(2))
            ->method('lockOwnerForRead')
            ->willReturnCallback(static function (string $ownerId) use (&$acquired): void {
                $acquired[] = $ownerId;
            });
        $metadata->expects(self::never())->method('lockOwnerForUpdate');

        OwnerReadLock::prepare($metadata, 'owner-b', '', 'owner-a', 'owner-b');
        OwnerReadLock::acquire($metadata, 'owner-b', '', 'owner-a', 'owner-b');

        self::assertSame(['owner-a', 'owner-b'], $prepared);
        self::assertSame(['owner-a', 'owner-b'], $acquired);
    }

    public function test_non_capability_uses_exclusive_lock_only_during_acquire(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $acquired = [];
        $metadata->expects(self::exactly(2))
            ->method('lockOwnerForUpdate')
            ->willReturnCallback(static function (string $ownerId) use (&$acquired): void {
                $acquired[] = $ownerId;
            });

        OwnerReadLock::prepare($metadata, 'owner-b', 'owner-a');
        OwnerReadLock::acquire($metadata, 'owner-b', '', 'owner-a', 'owner-a');

        self::assertSame(['owner-a', 'owner-b'], $acquired);
    }

    public function test_cached_and_observed_decorators_preserve_shared_capability(): void
    {
        $inner = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $inner);
        self::assertInstanceOf(SharedAuthorizationLockStore::class, $inner);
        $inner->expects(self::once())->method('prepareOwnerReadLock')->with('owner');
        $inner->expects(self::once())->method('lockOwnerForRead')->with('owner');
        $inner->expects(self::once())
            ->method('lockNamedPoliciesForRead')
            ->with(['policy'])
            ->willReturn(['policy' => '{"Statement":[]}']);
        $inner->expects(self::never())->method('lockOwnerForUpdate');
        $inner->expects(self::never())->method('lockNamedPoliciesForUpdate');
        $metrics = new MetricsCollector();
        $metadata = new ObservedMetadataStore(
            new CachedMetadataStoreDecorator($inner),
            $metrics,
            'test',
        );

        $metadata->prepareOwnerReadLock('owner');
        $metadata->lockOwnerForRead('owner');
        self::assertSame(['policy' => '{"Statement":[]}'], $metadata->lockNamedPoliciesForRead(['policy']));

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('operation="prepareOwnerReadLock"', $rendered);
        self::assertStringContainsString('operation="lockOwnerForRead"', $rendered);
        self::assertStringContainsString('operation="lockNamedPoliciesForRead"', $rendered);
    }

    public function test_decorators_fall_back_to_exclusive_locks_for_custom_stores(): void
    {
        $inner = $this->createMock(MetadataStore::class);
        $inner->expects(self::once())->method('lockOwnerForUpdate')->with('owner');
        $inner->expects(self::once())
            ->method('lockNamedPoliciesForUpdate')
            ->with(['policy'])
            ->willReturn(['policy' => '{"Statement":[]}']);
        $metadata = new ObservedMetadataStore(
            new CachedMetadataStoreDecorator($inner),
            new MetricsCollector(),
            'custom',
        );

        $metadata->prepareOwnerReadLock('owner');
        $metadata->lockOwnerForRead('owner');
        self::assertSame(['policy' => '{"Statement":[]}'], $metadata->lockNamedPoliciesForRead(['policy']));
    }
}
