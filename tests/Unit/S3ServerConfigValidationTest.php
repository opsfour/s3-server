<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit;

use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;

final class S3ServerConfigValidationTest extends TestCase
{
    public function test_root_storage_path_is_preserved(): void
    {
        $config = new S3ServerConfig(storagePath: '/');

        self::assertSame('/', $config->storagePath);
        self::assertSame(4, $config->sqliteWorkerPoolSize);
        self::assertFalse($config->isVirtualHostedStyle());
        self::assertFalse($config->allowUnfencedStorageForTests);
        self::assertSame(300.0, $config->localWriteWorkerTimeoutSeconds);
        self::assertSame(5, $config->shutdownReadinessDelay);
    }

    public function test_deprecated_post_budget_alone_supplies_the_canonical_node_budget(): void
    {
        $temp = sys_get_temp_dir() . '/s3-config-temp-' . bin2hex(random_bytes(8));
        try {
            $config = new S3ServerConfig(
                storagePath: '/',
                postSpoolBudgetBytes: 123_456,
                storageTempDir: $temp,
            );

            self::assertSame(123_456, $config->postSpoolBudgetBytes);
            self::assertSame(123_456, $config->storageTempBudgetBytes);
        } finally {
            @rmdir($temp);
        }
    }

    public function test_explicit_deprecated_and_canonical_node_budgets_must_match(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deprecated and must equal');
        new S3ServerConfig(
            storagePath: '/',
            postSpoolBudgetBytes: 123_456,
            storageTempBudgetBytes: 123_457,
        );
    }

    public function test_shutdown_readiness_delay_is_bounded_and_survives_clone(): void
    {
        $config = new S3ServerConfig(storagePath: '/', shutdownReadinessDelay: 0);
        self::assertSame(0, $config->with(['port' => 9001])->shutdownReadinessDelay);

        $this->expectException(\InvalidArgumentException::class);
        new S3ServerConfig(storagePath: '/', shutdownReadinessDelay: 301);
    }

    public function test_unsafe_storage_override_survives_config_clone(): void
    {
        $original = new S3ServerConfig(storagePath: '/tmp/s3');
        $config = $original->with([
            'allowUnfencedStorageForTests' => true,
            'quota' => new QuotaConfig(maxBytesPerOwner: 100),
        ]);
        $updated = $config->with(['port' => 9001]);

        self::assertNotSame($original, $config);
        self::assertFalse($original->allowUnfencedStorageForTests);
        self::assertSame(0, $original->quota->maxBytesPerOwner);
        self::assertTrue($config->allowUnfencedStorageForTests);
        self::assertTrue($updated->allowUnfencedStorageForTests);
        self::assertSame(100, $updated->quota->maxBytesPerOwner);
        self::assertSame(9000, $config->port);
        self::assertSame(9001, $updated->port);
    }

    public function test_cluster_identity_requires_a_node_and_preserves_one_process_incarnation_across_with(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            clusterRoles: 'api,reconcile',
        );
        $copy = $config->with(['port' => 9001]);

        self::assertTrue($config->clusterEnabled());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', (string) $config->clusterIncarnationId);
        self::assertSame($config->clusterIncarnationId, $copy->clusterIncarnationId);
    }

    public function test_cluster_identity_without_node_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clusterNodeId is required');

        new S3ServerConfig(storagePath: '/', clusterId: 'cluster-a');
    }

    public function test_cluster_identity_requires_the_operator_pinned_database_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clusterDatabaseId is required');

        new S3ServerConfig(storagePath: '/', clusterId: 'cluster-a', clusterNodeId: 'node-a');
    }

    public function test_security_test_opt_in_cannot_be_enabled_after_construction(): void
    {
        $config = new S3ServerConfig(storagePath: '/tmp/s3');

        self::assertTrue((new \ReflectionClass($config))->isReadOnly());
        $this->expectException(\Error::class);

        (new \ReflectionProperty($config, 'allowUnfencedStorageForTests'))->setValue($config, true);
    }

    public function test_storage_tiers_cannot_be_added_after_construction(): void
    {
        $config = new S3ServerConfig(storagePath: '/tmp/s3');

        $this->expectException(\Error::class);

        (new \ReflectionProperty($config, 'storageTiers'))->setValue($config, ['ARCHIVE' => []]);
    }

    public function test_nested_quota_limit_cannot_be_disabled_after_construction(): void
    {
        $config = new S3ServerConfig(storagePath: '/tmp/s3', quota: new QuotaConfig(maxBytesPerOwner: 100));

        self::assertTrue((new \ReflectionClass($config->quota))->isReadOnly());
        $this->expectException(\Error::class);

        (new \ReflectionProperty($config->quota, 'maxBytesPerOwner'))->setValue($config->quota, 0);
    }

    public function test_with_revalidates_overrides(): void
    {
        $config = new S3ServerConfig(storagePath: '/tmp/s3');

        $this->expectException(\InvalidArgumentException::class);

        $config->with(['port' => 0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLifecycleIntervals')]
    public function test_non_finite_lifecycle_intervals_are_rejected(float $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new S3ServerConfig(storagePath: '/tmp/s3', lifecycleIntervalSeconds: $interval);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLocalWriteWorkerTimeouts')]
    public function test_invalid_local_write_worker_timeouts_are_rejected(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new S3ServerConfig(storagePath: '/tmp/s3', localWriteWorkerTimeoutSeconds: $timeout);
    }

    /** @return array<string, array{float}> */
    public static function invalidLocalWriteWorkerTimeouts(): array
    {
        return ['zero' => [0.0], 'negative' => [-1.0], 'infinity' => [INF], 'NaN' => [NAN]];
    }

    /** @return array<string, array{float}> */
    public static function invalidLifecycleIntervals(): array
    {
        return ['positive infinity' => [INF], 'negative infinity' => [-INF], 'NaN' => [NAN]];
    }

    public function test_empty_virtual_host_domain_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new S3ServerConfig(storagePath: '/tmp/s3', baseDomain: ' ');
    }

    public function test_explicit_empty_metrics_token_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('metricsBearerToken must not be empty');

        new S3ServerConfig(storagePath: '/tmp/s3', metricsBearerToken: ' ');
    }
}
