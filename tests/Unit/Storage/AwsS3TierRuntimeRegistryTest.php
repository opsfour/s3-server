<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use League\Uri\Http;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\Storage\AwsS3FencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3ProviderProbe;
use OpsFour\S3Server\Storage\AwsS3ProviderWorkerProbe;
use OpsFour\S3Server\Storage\AwsS3ReadExecutor;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeBinding;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeRegistry;
use OpsFour\S3Server\Storage\ParallelAwsS3ReadExecutor;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Tests\Support\AwsS3OperationalStateFixture;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3ProviderProbe;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3ReadExecutor;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3IntentSummaryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** @phpstan-import-type AwsSummary from FixtureAwsS3IntentSummaryStore */
final class AwsS3TierRuntimeRegistryTest extends TestCase
{
    private string $base;

    /** @var list<AwsS3ForegroundObjectWriter> */
    private array $writers = [];

    /** @var array<string, string|false> */
    private array $oldEnvironment = [];

    private ?AwsS3ProviderProbe $sharedProbe = null;

    private ?AwsS3ReadExecutor $sharedReadExecutor = null;

    private ?StorageTempBudgetCoordinator $sharedTempBudget = null;

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'test', 'AWS_SECRET_ACCESS_KEY' => 'test'] as $name => $value) {
            $this->oldEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->base = sys_get_temp_dir() . '/s3-aws-tier-registry-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0700));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->writers) as $writer) {
            $writer->shutdown();
        }
        $this->sharedTempBudget?->close();
        foreach ($this->oldEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->base);
    }

    public function test_rejects_duplicate_binding_and_missing_default(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));

        try {
            new AwsS3TierRuntimeRegistry([$hot, $hot], 'HOT', $metadata);
            self::fail('Duplicate AWS runtime binding was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Duplicate AWS runtime tier: HOT', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires its exact default tier');
        new AwsS3TierRuntimeRegistry([$hot], 'COLD', $metadata);
    }

    public function test_rejects_more_than_two_exact_tiers(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $bindings = [
            $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot')),
            $this->binding('COLD', $metadata, $this->profile('data/cold', '.opsfour/cold')),
            $this->binding('DEEP', $metadata, $this->profile('data/deep', '.opsfour/deep')),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most two exact tiers');
        new AwsS3TierRuntimeRegistry($bindings, 'HOT', $metadata);
    }

    public function test_rejects_cross_tier_prefix_overlap(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));
        $cold = $this->binding('COLD', $metadata, $this->profile('data/hot/archive', '.opsfour/cold'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefixes must be pairwise disjoint');
        new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);
    }

    /** @param array<string, scalar> $override */
    #[DataProvider('providerMismatchProvider')]
    public function test_rejects_provider_identity_mismatch(array $override): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));
        $coldProfile = array_replace($this->profile('data/cold', '.opsfour/cold'), $override);
        $cold = $this->binding('COLD', $metadata, $coldProfile);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('share one exact provider identity and timeout contract');
        new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);
    }

    /** @return iterable<string, array{array<string, scalar>}> */
    public static function providerMismatchProvider(): iterable
    {
        yield 'bucket' => [['bucket' => 'another-private-bucket']];
        yield 'region' => [['region' => 'eu-west-1']];
        yield 'owner' => [['expected_owner_id' => '210987654321']];
        yield 'timeout' => [['request_timeout_seconds' => 21]];
    }

    public function test_rejects_different_metadata_graph_and_partial_binding(): void
    {
        $firstSummaries = $this->summaries();
        $secondSummaries = $this->summaries();
        $firstMetadata = $this->metadata($firstSummaries);
        $secondMetadata = $this->metadata($secondSummaries);
        $hot = $this->binding('HOT', $firstMetadata, $this->profile('data/hot', '.opsfour/hot'));
        $cold = $this->binding('COLD', $secondMetadata, $this->profile('data/cold', '.opsfour/cold'));

        try {
            new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $firstMetadata);
            self::fail('Different metadata graphs were accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('AWS runtime tiers must share one exact metadata graph.', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('incomplete or internally inconsistent');
        new AwsS3TierRuntimeBinding(
            'HOT',
            $hot->backend,
            $hot->metadata,
            $hot->operationalState,
            $hot->foregroundWriter,
            $cold->backgroundWriter,
            $hot->objectIntentCollector,
        );
    }

    public function test_binding_rejects_operational_state_from_another_metadata_graph(): void
    {
        $firstSummaries = $this->summaries();
        $secondSummaries = $this->summaries();
        $firstMetadata = $this->metadata($firstSummaries);
        $secondMetadata = $this->metadata($secondSummaries);
        $hot = $this->binding('HOT', $firstMetadata, $this->profile('data/hot', '.opsfour/hot'));
        $scratch = $this->base . '/hot-mismatched-state';
        self::assertTrue(mkdir($scratch, 0700));
        $writer = $hot->backend->createForegroundObjectWriter(
            $secondMetadata,
            $scratch,
            'HOT',
            16 * 1024 * 1024,
        );
        $this->writers[] = $writer;
        $collector = $hot->backend->createObjectWriteIntentCollector(
            $secondMetadata,
            'HOT',
            new NullLogger(),
            new MetricsCollector(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('incomplete or internally inconsistent');
        new AwsS3TierRuntimeBinding(
            'HOT',
            $hot->backend,
            $secondMetadata,
            $hot->operationalState,
            $writer,
            new AwsS3FencedBackgroundObjectWriter($writer, $hot->backend, 'HOT'),
            $collector,
        );
    }

    public function test_aggregate_snapshot_is_deterministic_bounded_and_metrics_compatible(): void
    {
        $summaries = $this->summaries();
        $summaries['HOT'] = array_replace($summaries['HOT'], [
            'open' => 2,
            'sealed' => 4,
            'part' => 6,
            'oldest_age_seconds' => 5.0,
            'max_owner_open' => 7,
            'global_open' => 17,
        ]);
        $summaries['COLD'] = array_replace($summaries['COLD'], [
            'open' => 3,
            'sealed' => 5,
            'part' => 7,
            'oldest_age_seconds' => 8.0,
            'max_owner_open' => 7,
            'global_open' => 17,
        ]);
        $summaryReads = ['HOT' => 0, 'COLD' => 0];
        $metadata = $this->metadata($summaries, $summaryReads);
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));
        $cold = $this->binding('COLD', $metadata, $this->profile('data/cold', '.opsfour/cold'));
        $registry = new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);
        $reversed = new AwsS3TierRuntimeRegistry([$cold, $hot], 'HOT', $metadata);

        $registry->assertMutationReady();
        $summaryReads = ['HOT' => 0, 'COLD' => 0];
        $snapshot = $registry->snapshot();
        self::assertSame(['HOT' => 1, 'COLD' => 0], $summaryReads);
        $summaryReads = ['HOT' => 0, 'COLD' => 0];
        $reverseSnapshot = $reversed->snapshot();
        self::assertSame(['HOT' => 1, 'COLD' => 0], $summaryReads);

        self::assertSame($snapshot['fingerprint'], $reverseSnapshot['fingerprint']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $snapshot['fingerprint']);
        self::assertTrue($snapshot['mutation_ready']);
        self::assertSame('healthy', $snapshot['reason']);
        self::assertSame('disabled', $snapshot['versioning_state']);
        self::assertSame('clear', $snapshot['drift_state']);
        self::assertSame(5, $snapshot['open']);
        self::assertSame(9, $snapshot['sealed']);
        self::assertSame(13, $snapshot['part']);
        self::assertSame(8.0, $snapshot['oldest_age_seconds']);
        self::assertSame(7, $snapshot['max_owner_open']);
        self::assertSame(17, $snapshot['global_open']);
        (new MetricsCollector())->setAwsOperationalSnapshot($snapshot);

        $summaries['HOT']['global_open'] = ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL;
        $capacitySnapshot = $registry->snapshot();
        self::assertFalse($capacitySnapshot['mutation_ready']);
        self::assertSame('capacity', $capacitySnapshot['reason']);
        self::assertSame(ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL, $capacitySnapshot['global_open']);
        (new MetricsCollector())->setAwsOperationalSnapshot($capacitySnapshot);
    }

    public function test_aggregate_summary_outage_closes_mutation_readiness_with_failed_collector_diagnostics(): void
    {
        $summaries = $this->summaries();
        $reads = 0;
        $inner = new SqliteMetadataStore(':memory:');
        $inner->initialize();
        $metadata = new FixtureAwsS3IntentSummaryStore(
            $inner,
            static function (string $tier) use (&$reads, &$summaries): array {
                if (++$reads > 2) {
                    throw new \RuntimeException('aggregate summary unavailable');
                }

                return self::summaryForTier($summaries, $tier);
            },
        );
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));
        $cold = $this->binding('COLD', $metadata, $this->profile('data/cold', '.opsfour/cold'));
        $registry = new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);

        try {
            $registry->assertMutationReady();
            self::fail('An unavailable aggregate summary left mutation admission open.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('collector', $error->getMessage());
        }
        $snapshot = $registry->snapshot();
        self::assertFalse($snapshot['mutation_ready']);
        self::assertSame('collector', $snapshot['reason']);
        self::assertSame('failed', $snapshot['reconciliation_state']);
    }

    public function test_health_and_metrics_bridge_the_aggregate_snapshot_without_per_tier_output(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));
        $cold = $this->binding('COLD', $metadata, $this->profile('data/cold', '.opsfour/cold'));
        $registry = new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);

        $client = $this->createMock(Client::class);
        $health = (new HealthCheckHandler(
            readinessCheckIntervalSeconds: 0,
            awsOperationalState: $registry,
        ))->handleRequest(new Request($client, 'GET', Http::new('http://127.0.0.1/.health')));
        $body = $health->getBody()->read();
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $health->getStatus());
        self::assertSame('ok', $decoded['status']);
        self::assertSame('healthy', $decoded['aws']['reason']);
        self::assertArrayNotHasKey('tiers', $decoded['aws']);
        self::assertArrayNotHasKey('storage_tier', $decoded['aws']);

        $metrics = (new MetricsHandler(
            new MetricsCollector(),
            awsOperationalState: $registry,
        ))->handleRequest(new Request($client, 'GET', Http::new('http://127.0.0.1/.metrics')));
        $rendered = $metrics->getBody()->read();
        self::assertIsString($rendered);
        self::assertSame(200, $metrics->getStatus());
        self::assertSame(1, substr_count($rendered, 's3_server_aws_reconciliation_failures 0'));
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_[^\n]*storage_tier=/m', $rendered);
    }

    public function test_two_tiers_share_probe_read_staging_and_temporary_capacity(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $probePool = new ContextWorkerPool(1);
        $readPool = new ContextWorkerPool(1);
        $stagePool = new ContextWorkerPool(2);
        $budgetPool = new ContextWorkerPool(2);
        $probe = new AwsS3ProviderWorkerProbe($probePool, 5.0);
        $read = new ParallelAwsS3ReadExecutor($readPool, 1, 1, 1.0, 5.0, new MetricsCollector());
        $tempBudget = new StorageTempBudgetCoordinator($this->base, 16 * 1024 * 1024, 0);

        try {
            $hot = $this->binding(
                'HOT',
                $metadata,
                $this->profile('data/hot', '.opsfour/hot'),
                $probe,
                $read,
                $stagePool,
                $budgetPool,
                $tempBudget,
            );
            $cold = $this->binding(
                'COLD',
                $metadata,
                $this->profile('data/cold', '.opsfour/cold'),
                $probe,
                $read,
                $stagePool,
                $budgetPool,
                $tempBudget,
            );
            new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);

            foreach ([$hot, $cold] as $runtime) {
                self::assertSame($probe, $runtime->operationalState->providerProbe());
                self::assertSame($read, $runtime->backend->readExecutor());
                self::assertSame($tempBudget, $runtime->foregroundWriter->tempBudgetCoordinator());
                self::assertTrue($runtime->operationalState->usesProviderProbePool($probePool));
                self::assertTrue($runtime->backend->usesReadPool($readPool));
                self::assertTrue($runtime->foregroundWriter->usesStagingWorkerPools($stagePool, $budgetPool));
            }
            self::assertSame(16 * 1024 * 1024, $tempBudget->snapshot()->budgetBytes);
            self::assertSame(0, $tempBudget->snapshot()->reservedBytes);
        } finally {
            $probePool->shutdown();
            $readPool->shutdown();
            $stagePool->shutdown();
            $budgetPool->shutdown();
            $tempBudget->close();
        }
    }

    public function test_rejects_a_second_tier_with_a_different_shared_facility_identity(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $hot = $this->binding('HOT', $metadata, $this->profile('data/hot', '.opsfour/hot'));
        $otherBudget = new StorageTempBudgetCoordinator($this->base, 16 * 1024 * 1024, 0);
        try {
            $cold = $this->binding(
                'COLD',
                $metadata,
                $this->profile('data/cold', '.opsfour/cold'),
                $this->sharedProbe,
                $this->sharedReadExecutor,
                tempBudget: $otherBudget,
            );
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('share one exact provider probe, read executor, and temporary-budget coordinator');
            new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);
        } finally {
            $otherBudget->close();
        }
    }

    public function test_partial_graph_failure_rolls_back_every_provisional_attachment_once(): void
    {
        $summaries = $this->summaries();
        $metadata = $this->metadata($summaries);
        $hot = $this->binding(
            'HOT',
            $metadata,
            $this->profile('data/hot', '.opsfour/hot'),
            commit: false,
        );
        $cold = $this->binding(
            'COLD',
            $metadata,
            $this->profile('data/hot/archive', '.opsfour/cold'),
            commit: false,
        );

        try {
            new AwsS3TierRuntimeRegistry([$hot, $cold], 'HOT', $metadata);
            self::fail('The invalid partial graph was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('prefixes must be pairwise disjoint', $error->getMessage());
        } finally {
            $rollback = new \ReflectionMethod(S3ServerRuntimeFactory::class, 'rollbackAwsOperationalAttachments');
            $attachments = [
                [$hot->backend, $hot->operationalState, $hot->tier, $metadata],
                [$cold->backend, $cold->operationalState, $cold->tier, $metadata],
            ];
            $rollback->invoke(null, $attachments, new NullLogger());
            $rollback->invoke(null, $attachments, new NullLogger());
        }

        self::assertNull($hot->backend->operationalState());
        self::assertNull($cold->backend->operationalState());
        self::assertFalse($hot->backend->operationalAttachmentCommitted());
        self::assertFalse($cold->backend->operationalAttachmentCommitted());

    }

    /**
     * @param ObjectWriteIntentStore&AwsS3OperationalSummaryReader $metadata
     * @param array<string, scalar> $profile
     */
    private function binding(
        string $tier,
        ObjectWriteIntentStore&AwsS3OperationalSummaryReader $metadata,
        array $profile,
        ?AwsS3ProviderProbe $probe = null,
        ?AwsS3ReadExecutor $readExecutor = null,
        ?WorkerPool $stagePool = null,
        ?WorkerPool $budgetPool = null,
        ?StorageTempBudgetCoordinator $tempBudget = null,
        bool $commit = true,
    ): AwsS3TierRuntimeBinding {
        $mock = new MockHandler([
            new Response(200, ['x-amz-bucket-region' => (string) $profile['region']]),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ]);
        $validated = AwsS3ProviderValidatedBinding::fromConfiguration(
            $profile,
            new AwsS3ProviderValidator($mock),
        );
        self::assertTrue($validated->admission->providerAdmitted);
        $backend = new AwsS3StorageBackend($validated->admission, $validated);
        class_exists(AwsS3OperationalStateFixture::class);
        $probe ??= $this->sharedProbe ??= new FixtureAwsS3ProviderProbe($validated->admission);
        $readExecutor ??= $this->sharedReadExecutor ??= new FixtureAwsS3ReadExecutor(
            $validated->client ?? throw new \LogicException('Fixture AWS client is unavailable.'),
        );
        $tempBudget ??= $this->sharedTempBudget ??= new StorageTempBudgetCoordinator(
            $this->base,
            16 * 1024 * 1024,
            0,
        );
        $state = AwsS3OperationalStateFixture::attach(
            $backend,
            $validated,
            $tier,
            $metadata,
            $probe,
            $readExecutor,
            $commit,
        );
        $scratch = $this->base . '/' . strtolower($tier) . '-' . count($this->writers);
        self::assertTrue(mkdir($scratch, 0700));
        $writer = $backend->createForegroundObjectWriter(
            $metadata,
            $scratch,
            $tier,
            16 * 1024 * 1024,
            filesystemReserveBytes: 0,
            tempBudget: $tempBudget,
            stagePool: $stagePool,
            budgetPool: $budgetPool,
        );
        $this->writers[] = $writer;
        $collector = $backend->createObjectWriteIntentCollector(
            $metadata,
            $tier,
            new NullLogger(),
            new MetricsCollector(),
        );

        return new AwsS3TierRuntimeBinding(
            $tier,
            $backend,
            $metadata,
            $state,
            $writer,
            new AwsS3FencedBackgroundObjectWriter($writer, $backend, $tier),
            $collector,
        );
    }

    /**
     * @param array<string, AwsSummary> $summaries
     * @param array<string, int>|null $readCounts
     * @return ObjectWriteIntentStore&AwsS3OperationalSummaryReader
     */
    private function metadata(
        array &$summaries,
        ?array &$readCounts = null,
    ): ObjectWriteIntentStore&AwsS3OperationalSummaryReader {
        $inner = new SqliteMetadataStore(':memory:');
        $inner->initialize();

        return new FixtureAwsS3IntentSummaryStore(
            $inner,
            static function (string $tier) use (&$summaries, &$readCounts): array {
                if ($readCounts !== null) {
                    $readCounts[$tier] = ($readCounts[$tier] ?? 0) + 1;
                }
                return self::summaryForTier($summaries, $tier);
            },
        );
    }

    /**
     * @param array<string, AwsSummary> $summaries
     * @return AwsSummary
     */
    private static function summaryForTier(array $summaries, string $tier): array
    {
        return $summaries[$tier] ?? throw new \LogicException("Unknown fixture tier: {$tier}");
    }

    /** @return array<string, scalar> */
    private function profile(string $dataPrefix, string $reservedPrefix): array
    {
        return [
            'bucket' => 'backing-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => $dataPrefix,
            'reserved_prefix' => $reservedPrefix,
        ];
    }

    /** @return array<string, AwsSummary> */
    private function summaries(): array
    {
        $summary = [
            'open' => 0,
            'ambiguous' => 0,
            'seal_reserved' => 0,
            'sealed' => 0,
            'multipart' => 0,
            'part' => 0,
            'cleanup' => 0,
            'failures' => 0,
            'oldest_age_seconds' => 0.0,
            'max_owner_open' => 0,
            'max_owner_sealed' => 0,
            'max_owner_multipart' => 0,
            'max_owner_part' => 0,
            'global_open' => 0,
            'global_seal_reserved' => 0,
            'global_multipart' => 0,
            'global_part' => 0,
            'global_cleanup' => 0,
        ];

        return ['HOT' => $summary, 'COLD' => $summary, 'DEEP' => $summary];
    }
}
