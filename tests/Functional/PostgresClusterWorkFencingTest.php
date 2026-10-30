<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\ClusterWorkClaim;
use OpsFour\S3Server\Metadata\ClusterWorkToken;
use OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Notification\NotificationProcessor;
use OpsFour\S3Server\Runtime\ClusterWorkSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresClusterWorkFencingTest extends TestCase
{
    private PostgresConnectionPool $firstPool;
    private PostgresConnectionPool $secondPool;
    private PostgresMetadataStore $first;
    private PostgresMetadataStore $second;

    protected function setUp(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN to an isolated s3audit_ PostgreSQL database.');
        }
        $config = PostgresConfig::fromString(trim($dsn));
        $this->firstPool = new PostgresConnectionPool($config);
        $this->secondPool = new PostgresConnectionPool($config);
        $database = $this->firstPool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertIsString($database['database_name'] ?? null);
        self::assertStringStartsWith('s3audit_', $database['database_name']);
        $this->first = new PostgresMetadataStore($this->firstPool);
        $this->second = new PostgresMetadataStore($this->secondPool);
        $this->first->initialize();
        $this->dropRetryFixtures();
        $this->clearWorkRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->firstPool)) {
            $this->dropRetryFixtures();
            $this->clearWorkRows();
            $this->firstPool->close();
        }
        if (isset($this->secondPool)) {
            $this->secondPool->close();
        }
    }

    public function test_race_takeover_heartbeat_and_stale_token_rejection(): void
    {
        $id = $this->first->enqueueNotification('bucket', 'key', 's3:ObjectCreated:Put', 'https://example.test/hook', '{}');
        $claims = \Amp\Future\await([
            \Amp\async(fn(): ?ClusterWorkClaim => $this->first->claimClusterNotification('node-a:incarnation:notification', 0.15)),
            \Amp\async(fn(): ?ClusterWorkClaim => $this->second->claimClusterNotification('node-b:incarnation:notification', 0.15)),
        ]);
        $won = array_values(array_filter($claims));
        self::assertCount(1, $won);
        $firstClaim = $won[0];
        self::assertSame((string) $id, $firstClaim->token->workId);

        \Amp\delay(0.02);
        $renewed = ($firstClaim->token->workerId === 'node-a:incarnation:notification' ? $this->first : $this->second)
            ->renewClusterWork($firstClaim->token, 0.5);
        self::assertNotNull($renewed);
        self::assertSame($firstClaim->token->generation, $renewed->generation);
        self::assertGreaterThan($firstClaim->token->heartbeatAt, $renewed->heartbeatAt);
        self::assertGreaterThan($firstClaim->token->leaseExpiresAt, $renewed->leaseExpiresAt);

        \Amp\delay(0.55);
        $takeoverStore = $firstClaim->token->workerId === 'node-a:incarnation:notification' ? $this->second : $this->first;
        $takeover = $takeoverStore->claimClusterNotification('node-c:incarnation:notification', 1.0);
        self::assertNotNull($takeover);
        self::assertGreaterThan($firstClaim->token->generation, $takeover->token->generation);

        $this->expectException(\RuntimeException::class);
        $this->first->transactionWithClusterWork(
            $firstClaim->token,
            fn(): bool => $this->first->updateNotificationStatus($id, 'sent'),
        );
    }

    public function test_operational_snapshot_uses_database_time_and_tracks_drain_takeover_and_stale_ack(): void
    {
        $this->first->registerClusterMember('cluster-observe', 'node-api', 'inc-api', ['api'], true, false, 30.0);
        $this->first->registerClusterMember('cluster-observe', 'node-worker', 'inc-worker', ['reconcile'], true, false, 30.0);
        self::assertTrue($this->first->heartbeatClusterMember('cluster-observe', 'node-api', 'inc-api', false, false, 30.0));
        $notReady = $this->first->clusterMembershipSnapshot('cluster-observe', 'node-api', 'inc-api');
        self::assertSame(['ready' => 0, 'draining' => 0], $notReady->roleCounts['api']);
        self::assertTrue($this->first->heartbeatClusterMember('cluster-observe', 'node-api', 'inc-api', false, true, 30.0));

        $draining = $this->first->clusterMembershipSnapshot('cluster-observe', 'node-api', 'inc-api');
        self::assertSame('current', $draining->membership);
        self::assertNotNull($draining->lastHeartbeatAt);
        self::assertNotNull($draining->heartbeatAgeSeconds);
        self::assertGreaterThanOrEqual(0.0, $draining->heartbeatAgeSeconds);
        self::assertSame(['ready' => 0, 'draining' => 1], $draining->roleCounts['api']);
        self::assertSame(['ready' => 1, 'draining' => 0], $draining->roleCounts['reconcile']);

        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id) VALUES ('future-abandoned', 'upload-future-a', 'b', 'generation', 'owner', 'hot', 'assembly-a', 'final-a', 'digest-a', 'abandoned', 0, EXTRACT(EPOCH FROM clock_timestamp()) + 60, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')");
        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id) VALUES ('future-active', 'upload-future-b', 'b', 'generation', 'owner', 'hot', 'assembly-b', 'final-b', 'digest-b', 'active', EXTRACT(EPOCH FROM clock_timestamp()) + 60, 0, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')");
        $multipartState = $this->first->clusterOperationalSnapshot('cluster-observe', 'node-worker', 'inc-worker');
        self::assertSame(0, $multipartState->work['multipart_intent']->pending);
        self::assertSame(1, $multipartState->work['multipart_intent']->activeLeases);
        self::assertSame(0, $multipartState->work['multipart_intent']->expiredLeases);

        $id = $this->first->enqueueNotification('bucket', 'key', 'event', 'https://example.test/hook', '{}');
        $first = $this->first->claimClusterNotification('node-a:notification', 10.0);
        self::assertNotNull($first);
        self::assertFalse($first->token->takeover);
        $this->firstPool->execute(
            'UPDATE s3_notification_queue SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE id = $1',
            [$id],
        );
        $expired = $this->first->clusterOperationalSnapshot('cluster-observe', 'node-worker', 'inc-worker');
        self::assertSame(1, $expired->work['notification']->expiredLeases);

        $takeover = $this->second->claimClusterNotification('node-b:notification', 10.0);
        self::assertNotNull($takeover);
        self::assertTrue($takeover->token->takeover);
        $afterTakeover = $this->first->clusterOperationalSnapshot('cluster-observe', 'node-worker', 'inc-worker');
        self::assertSame(1, $afterTakeover->work['notification']->activeLeases);
        self::assertSame(1, $afterTakeover->work['notification']->successfulTakeovers);
        self::assertGreaterThanOrEqual(2, $afterTakeover->work['notification']->highestGeneration);

        try {
            $this->first->transactionWithClusterWork($first->token, static fn(): bool => true);
            self::fail('The stale acknowledgement unexpectedly retained ownership.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cluster work ownership was lost.', $error->getMessage());
        }
        $this->second->recordClusterWorkOutcome($takeover->token, ClusterWorkOutcomeRecorder::FAILED_TAKEOVER);
        $outcomes = $this->first->clusterOperationalSnapshot('cluster-observe', 'node-worker', 'inc-worker');
        self::assertSame(1, $outcomes->work['notification']->staleAcknowledgements);
        self::assertSame(1, $outcomes->work['notification']->failedTakeovers);

        $this->firstPool->execute(
            "UPDATE s3_cluster_membership SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE node_id = 'node-api'",
        );
        $expiredMember = $this->first->clusterMembershipSnapshot('cluster-observe', 'node-api', 'inc-api');
        self::assertSame('stale', $expiredMember->membership);
        self::assertSame(['ready' => 0, 'draining' => 0], $expiredMember->roleCounts['api']);
    }

    public function test_current_takeover_token_is_the_only_completion_authority(): void
    {
        $id = $this->first->enqueueNotification('bucket', 'key', 's3:ObjectCreated:Put', 'https://example.test/hook', '{}');
        $old = $this->first->claimClusterNotification('node-a:incarnation:notification', 0.05);
        self::assertNotNull($old);
        \Amp\delay(0.08);
        $current = $this->second->claimClusterNotification('node-b:incarnation:notification', 1.0);
        self::assertNotNull($current);

        try {
            $this->first->transactionWithClusterWork(
                $old->token,
                fn(): bool => $this->first->updateNotificationStatus($id, 'sent'),
            );
            self::fail('A stale cluster token changed authoritative state.');
        } catch (\RuntimeException) {
            // Expected ownership loss.
        }

        self::assertTrue($this->second->transactionWithClusterWork(
            $current->token,
            fn(): bool => $this->second->updateNotificationStatus($id, 'sent'),
        ));
        self::assertSame(['sent' => 1], $this->second->getNotificationQueueStats());
    }

    public function test_deadlock_retries_are_bounded_and_use_fresh_transactions(): void
    {
        $this->first->enqueueNotification('bucket', 'key', 's3:ObjectCreated:Put', 'https://example.test/hook', '{}');
        $this->firstPool->execute('CREATE SEQUENCE s3_test_cluster_retry_seq');
        $this->firstPool->execute(<<<'SQL'
            CREATE FUNCTION s3_test_cluster_retry_fn() RETURNS trigger AS $$
            BEGIN
                IF nextval('s3_test_cluster_retry_seq') <= 2 THEN
                    RAISE EXCEPTION 'synthetic deadlock' USING ERRCODE = '40P01';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
            SQL);
        $this->firstPool->execute('CREATE TRIGGER s3_test_cluster_retry BEFORE UPDATE ON s3_notification_queue FOR EACH ROW EXECUTE FUNCTION s3_test_cluster_retry_fn()');

        self::assertNotNull($this->first->claimClusterNotification('node-a:incarnation:notification', 1.0));
        $row = $this->firstPool->query('SELECT last_value FROM s3_test_cluster_retry_seq')->fetchRow();
        self::assertSame(3, (int) ($row['last_value'] ?? 0));

        $this->firstPool->execute('DROP TRIGGER s3_test_cluster_retry ON s3_notification_queue');
        $this->firstPool->execute('DROP FUNCTION s3_test_cluster_retry_fn()');
        $this->firstPool->execute('DROP SEQUENCE s3_test_cluster_retry_seq');
        $this->first->enqueueNotification('bucket', 'key-2', 's3:ObjectCreated:Put', 'https://example.test/hook', '{}');
        $this->firstPool->execute('CREATE SEQUENCE s3_test_cluster_retry_seq');
        $this->firstPool->execute(<<<'SQL'
            CREATE FUNCTION s3_test_cluster_retry_fn() RETURNS trigger AS $$
            BEGIN
                PERFORM nextval('s3_test_cluster_retry_seq');
                RAISE EXCEPTION 'synthetic serialization failure' USING ERRCODE = '40001';
            END
            $$ LANGUAGE plpgsql
            SQL);
        $this->firstPool->execute('CREATE TRIGGER s3_test_cluster_retry BEFORE UPDATE ON s3_notification_queue FOR EACH ROW EXECUTE FUNCTION s3_test_cluster_retry_fn()');

        try {
            $this->second->claimClusterNotification('node-b:incarnation:notification', 1.0);
            self::fail('Exhausted serialization retries did not fail closed.');
        } catch (\Throwable) {
            $row = $this->firstPool->query('SELECT last_value FROM s3_test_cluster_retry_seq')->fetchRow();
            self::assertSame(3, (int) ($row['last_value'] ?? 0));
            $stats = $this->first->getNotificationQueueStats();
            self::assertSame(1, $stats['pending'] ?? 0);
            self::assertSame(1, $stats['processing'] ?? 0);
        }
    }

    public function test_every_durable_work_kind_persists_worker_heartbeat_and_generation(): void
    {
        $this->firstPool->execute("INSERT INTO s3_tier_transition_jobs (bucket, key_name, source_tier, target_tier, target_storage_class, source_storage_path, next_attempt_at) VALUES ('b', 'k', 'hot', 'cold', 'GLACIER', 'source', 0)");
        $this->firstPool->execute("INSERT INTO s3_restore_jobs (bucket, key_name, source_tier, source_storage_path, restore_days, next_attempt_at) VALUES ('b', 'k', 'cold', 'source', 1, 0)");
        $this->firstPool->execute("INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, next_attempt_at) VALUES ('b', 'hot', 'garbage', 0)");
        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id) VALUES ('multipart-attempt', 'upload', 'b', 'generation', 'owner', 'hot', 'assembly', 'final', 'digest', 'active', 0, 0, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')");
        $this->firstPool->execute("INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, state, lease_until, dispatch_id) VALUES ('object-attempt', 'b', 'generation', 'owner', 'hot', 'object', 'path-digest', 'put', 'active', 0, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')");
        $this->first->enqueueNotification('b', 'k', 's3:ObjectCreated:Put', 'https://example.test/hook', '{}');

        $worker = 'node-a:incarnation:worker';
        $claims = [
            $this->first->claimClusterLifecycle($worker, 1.0),
            $this->first->claimClusterNotification($worker, 1.0),
            $this->first->claimClusterTierTransition($worker, 1.0),
            $this->first->claimClusterRestore($worker, 1.0),
            $this->first->claimClusterStorageGarbage($worker, 1.0),
            $this->first->claimClusterMultipartIntent($worker, 1.0),
            $this->first->claimClusterObjectIntent('hot', $worker, 1.0),
        ];
        self::assertSame(ClusterWorkToken::kinds(), array_map(
            static fn(?ClusterWorkClaim $claim): ?string => $claim?->token->kind,
            $claims,
        ));
        foreach ($claims as $claim) {
            self::assertNotNull($claim);
            self::assertSame($worker, $claim->token->workerId);
            self::assertGreaterThanOrEqual(1, $claim->token->generation);
            self::assertGreaterThan($claim->token->heartbeatAt, $claim->token->leaseExpiresAt);
        }
        $snapshot = $this->first->clusterOperationalSnapshot('cluster-work-catalog', 'node-a', 'incarnation');
        self::assertSame(
            ClusterWorkSnapshot::ALL,
            array_keys($snapshot->work),
        );
        foreach ($snapshot->work as $workClass => $work) {
            self::assertSame(1, $work->activeLeases, $workClass);
            self::assertSame(0, $work->expiredLeases, $workClass);
            self::assertGreaterThanOrEqual(1, $work->highestGeneration, $workClass);
        }
        self::assertInstanceOf(MultipartWriteIntent::class, $claims[5]?->work);
        self::assertInstanceOf(ObjectWriteIntent::class, $claims[6]?->work);
    }

    #[DataProvider('clusterWorkKinds')]
    public function test_every_kind_renews_rejects_stale_takeover_and_fails_closed_during_database_outage(string $kind): void
    {
        $this->seedWorkKind($kind);
        $old = $this->claimWorkKind($this->first, $kind, 'node-a:incarnation:' . $kind, 1.0);
        self::assertNotNull($old);
        $renewed = $this->first->renewClusterWork($old->token, 2.0);
        self::assertNotNull($renewed);
        self::assertSame($old->token->generation, $renewed->generation);
        self::assertGreaterThan($old->token->heartbeatAt, $renewed->heartbeatAt);
        $currentCallbackRan = false;
        $this->first->transactionWithClusterWork($renewed, function () use (&$currentCallbackRan): void {
            $currentCallbackRan = true;
        });
        self::assertTrue($currentCallbackRan);

        $this->expireWorkKind($kind);
        $current = $this->claimWorkKind($this->second, $kind, 'node-b:incarnation:' . $kind, 2.0);
        self::assertNotNull($current);
        self::assertGreaterThan($old->token->generation, $current->token->generation);
        if ($kind === ClusterWorkToken::OBJECT_INTENT) {
            $objectOutcomes = $this->first->clusterOperationalSnapshot('cluster-object-outcome', 'node-a', 'incarnation');
            self::assertSame(2, $objectOutcomes->work['object_intent']->successfulTakeovers);
            self::assertSame(1, $objectOutcomes->work['object_intent']->failedTakeovers);
        }
        $staleCallbackRan = false;
        try {
            $this->first->transactionWithClusterWork($old->token, function () use (&$staleCallbackRan): void {
                $staleCallbackRan = true;
            });
            self::fail('A stale cluster token reached its finalization callback.');
        } catch (\RuntimeException) {
            self::assertFalse($staleCallbackRan);
        }

        $outagePool = new PostgresConnectionPool(PostgresConfig::fromString((string) getenv('S3_TEST_POSTGRES_METADATA_DSN')));
        $outageStore = new PostgresMetadataStore($outagePool);
        $outagePool->close();
        $renewalFailed = false;
        try {
            $outageStore->renewClusterWork($current->token, 2.0);
        } catch (\Throwable) {
            $renewalFailed = true;
        }
        self::assertTrue($renewalFailed, 'Database-outage renewal used a local fallback.');
        $outageCallbackRan = false;
        try {
            $outageStore->transactionWithClusterWork($current->token, function () use (&$outageCallbackRan): void {
                $outageCallbackRan = true;
            });
            self::fail('Database-outage finalization used a local fallback.');
        } catch (\Throwable) {
            self::assertFalse($outageCallbackRan);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function clusterWorkKinds(): iterable
    {
        foreach (ClusterWorkToken::kinds() as $kind) {
            yield $kind => [$kind];
        }
    }

    public function test_lifecycle_release_retries_without_deleting_the_generation_anchor(): void
    {
        $first = $this->first->claimClusterLifecycle('node-a:incarnation:lifecycle', 2.0);
        self::assertNotNull($first);
        $this->firstPool->execute('CREATE SEQUENCE s3_test_cluster_retry_seq');
        $this->firstPool->execute(<<<'SQL'
            CREATE FUNCTION s3_test_cluster_retry_fn() RETURNS trigger AS $$
            BEGIN
                IF nextval('s3_test_cluster_retry_seq') <= 2 THEN
                    RAISE EXCEPTION 'synthetic lifecycle release deadlock' USING ERRCODE = '40P01';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
            SQL);
        $this->firstPool->execute('CREATE TRIGGER s3_test_cluster_retry BEFORE UPDATE ON s3_locks FOR EACH ROW EXECUTE FUNCTION s3_test_cluster_retry_fn()');

        self::assertTrue($this->first->releaseClusterWork($first->token));
        $row = $this->firstPool->query("SELECT COUNT(*) AS lock_count, MAX(lease_generation) AS generation, BOOL_AND(expires_at <= clock_timestamp()) AS expired FROM s3_locks WHERE lock_name = 'lifecycle:global'")->fetchRow();
        self::assertSame(1, (int) ($row['lock_count'] ?? 0));
        self::assertSame($first->token->generation, (int) ($row['generation'] ?? 0));
        self::assertTrue((bool) ($row['expired'] ?? false));
        $released = $this->first->clusterOperationalSnapshot('cluster-release', 'node-a', 'incarnation');
        self::assertSame(0, $released->work['lifecycle']->expiredLeases);
        self::assertSame(0, $released->work['lifecycle']->successfulTakeovers);
        $retry = $this->firstPool->query('SELECT last_value FROM s3_test_cluster_retry_seq')->fetchRow();
        self::assertSame(3, (int) ($retry['last_value'] ?? 0));

        $second = $this->second->claimClusterLifecycle('node-a:incarnation:lifecycle', 2.0);
        self::assertNotNull($second);
        self::assertFalse($second->token->takeover);
        self::assertSame($first->token->generation + 1, $second->token->generation);
        self::assertFalse($this->first->releaseClusterWork($first->token));
        self::assertTrue($this->second->releaseClusterWork($second->token));
        $third = $this->first->claimClusterLifecycle('node-a:incarnation:lifecycle', 2.0);
        self::assertNotNull($third);
        self::assertFalse($third->token->takeover);
        self::assertSame($second->token->generation + 1, $third->token->generation);
        $this->firstPool->execute("UPDATE s3_locks SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE lock_name = 'lifecycle:global'");
        $takeover = $this->second->claimClusterLifecycle('node-b:incarnation:lifecycle', 2.0);
        self::assertNotNull($takeover);
        self::assertTrue($takeover->token->takeover);
        self::assertTrue($this->second->releaseClusterWork($takeover->token, true));
        $outcomes = $this->first->clusterOperationalSnapshot('cluster-release', 'node-a', 'incarnation');
        self::assertSame(1, $outcomes->work['lifecycle']->successfulTakeovers);
        self::assertSame(1, $outcomes->work['lifecycle']->failedTakeovers);
        self::assertSame(1, $outcomes->work['lifecycle']->staleAcknowledgements);
    }

    public function test_cluster_multipart_claim_skips_provably_undispatched_rows(): void
    {
        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id) VALUES ('a-undispatched', 'upload-a', 'b', 'generation', 'owner', 'hot', 'assembly-a', 'final-a', 'digest-a', 'active', 0, 0, NULL)");
        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id) VALUES ('b-dispatched', 'upload-b', 'b', 'generation', 'owner', 'hot', 'assembly-b', 'final-b', 'digest-b', 'active', 0, 0, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')");

        $claim = $this->first->claimClusterMultipartIntent('node-a:incarnation:multipart', 1.0);
        self::assertNotNull($claim);
        self::assertSame('b-dispatched', $claim->token->workId);
        $row = $this->firstPool->query("SELECT state, claim_generation FROM s3_multipart_write_intents WHERE attempt_id = 'a-undispatched'")->fetchRow();
        self::assertSame('active', $row['state'] ?? null);
        self::assertSame(0, (int) ($row['claim_generation'] ?? -1));
    }

    public function test_snapshot_reports_retried_queue_age_and_owned_multipart_claim_state(): void
    {
        $id = $this->first->enqueueNotification('b', 'retry', 'event', 'https://example.test/hook', '{}');
        $this->firstPool->execute(
            "UPDATE s3_notification_queue SET attempts = 2, next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 5 WHERE id = $1",
            [$id],
        );
        $expiredId = $this->first->enqueueNotification('b', 'expired', 'event', 'https://example.test/hook', '{}');
        $this->firstPool->execute(
            "UPDATE s3_notification_queue SET status = 'processing', next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 10 WHERE id = $1",
            [$expiredId],
        );
        $this->firstPool->execute(<<<'SQL'
            INSERT INTO s3_multipart_write_intents (
                attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                assembly_path, final_path, part_digest, state, lease_until, next_attempt_at,
                dispatch_id, claim_worker_id, claim_heartbeat_at
            ) VALUES (
                'owned-abandoned', 'owned-upload', 'b', 'generation', 'owner', 'hot',
                'assembly', 'final', 'digest', 'abandoned', 0,
                EXTRACT(EPOCH FROM clock_timestamp()) + 60,
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'node-a:multipart', EXTRACT(EPOCH FROM clock_timestamp())
            )
            SQL);

        $snapshot = $this->first->clusterOperationalSnapshot('cluster-shape', 'node', 'incarnation');
        self::assertSame(1, $snapshot->work['notification']->pending);
        self::assertSame(1, $snapshot->work['notification']->retryPressure);
        self::assertSame(1, $snapshot->work['notification']->expiredLeases);
        self::assertNotNull($snapshot->work['notification']->oldestEligibleAgeSeconds);
        self::assertGreaterThanOrEqual(9.0, $snapshot->work['notification']->oldestEligibleAgeSeconds);
        self::assertSame(1, $snapshot->work['multipart_intent']->activeLeases);
        self::assertSame(0, $snapshot->work['multipart_intent']->pending);

        $this->firstPool->execute(
            "UPDATE s3_multipart_write_intents SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE attempt_id = 'owned-abandoned'",
        );
        $expired = $this->first->clusterOperationalSnapshot('cluster-shape', 'node', 'incarnation');
        self::assertSame(1, $expired->work['multipart_intent']->expiredLeases);
        $claim = $this->second->claimClusterMultipartIntent('node-b:multipart', 30.0);
        self::assertNotNull($claim);
        self::assertTrue($claim->token->takeover);
        self::assertTrue($this->second->transactionWithClusterWork(
            $claim->token,
            fn(): bool => $this->second->renewMultipartCollectorClaim(
                'owned-abandoned',
                $claim->token->leaseExpiresAt + 30,
                $claim->token->leaseExpiresAt,
                $claim->token->generation,
            ),
        ));
        $renewed = $this->second->getMultipartWriteIntent('owned-abandoned');
        self::assertNotNull($renewed);
        self::assertGreaterThan($claim->token->leaseExpiresAt, $renewed->nextAttemptAt);
        self::assertTrue($this->second->transactionWithClusterWork(
            $claim->token,
            fn(): bool => $this->second->retryMultipartCollectorClaim(
                'owned-abandoned',
                $this->second->multipartWriteIntentNow() + 60,
                $renewed->nextAttemptAt,
                $claim->token->generation,
            ),
        ));
        $released = $this->firstPool->query(
            "SELECT claim_worker_id, claim_heartbeat_at FROM s3_multipart_write_intents WHERE attempt_id = 'owned-abandoned'",
        )->fetchRow();
        self::assertNull($released['claim_worker_id'] ?? null);
        self::assertNull($released['claim_heartbeat_at'] ?? null);
    }

    public function test_large_membership_fails_closed_and_large_queue_omits_exact_count(): void
    {
        $this->firstPool->execute(<<<'SQL'
            INSERT INTO s3_cluster_membership (
                cluster_id, node_id, incarnation_id, roles, ready, draining, heartbeat_at, expires_at
            )
            SELECT 'large-cluster', 'node-' || value, 'incarnation', '["api"]'::jsonb,
                   TRUE, FALSE, clock_timestamp(), clock_timestamp() + INTERVAL '5 minutes'
            FROM generate_series(1, 10001) AS value
            SQL);
        try {
            $this->first->clusterMembershipSnapshot('large-cluster', 'node-1', 'incarnation');
            self::fail('Large live membership published a partial exact role count.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('bounded live-process limit', $error->getMessage());
        }

        $this->firstPool->execute(<<<'SQL'
            INSERT INTO s3_notification_queue (
                bucket, key_name, event_name, destination_url, payload_json, status, next_attempt_at
            )
            SELECT 'b', 'key-' || value, 'event', 'https://example.test/hook', '{}', 'pending', 0
            FROM generate_series(1, 10001) AS value
            SQL);
        $snapshot = $this->first->clusterOperationalSnapshot('empty-cluster', 'node', 'incarnation');
        self::assertNull($snapshot->work['notification']->pending);
        self::assertNotNull($snapshot->work['notification']->oldestEligibleAgeSeconds);
    }

    public function test_cluster_lease_rejects_sub_millisecond_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->first->claimClusterLifecycle('node-a:incarnation:lifecycle', 0.000_001);
    }

    public function test_v47_migration_invalidates_every_pre_owner_active_generation(): void
    {
        $notificationId = $this->first->enqueueNotification('bucket', 'key', 's3:ObjectCreated:Put', 'https://example.test/hook', '{}');
        $this->firstPool->execute("INSERT INTO s3_locks (lock_name, owner_id, expires_at, heartbeat_at, lease_generation) VALUES ('lifecycle:global', 'legacy-worker', clock_timestamp() + INTERVAL '5 minutes', EXTRACT(EPOCH FROM clock_timestamp()), 9)");
        $this->firstPool->execute("UPDATE s3_notification_queue SET status = 'processing', next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) + 300, lease_generation = 7 WHERE id = $1", [$notificationId]);
        $this->firstPool->execute("INSERT INTO s3_tier_transition_jobs (bucket, key_name, source_tier, target_tier, target_storage_class, source_storage_path, status, lease_generation, next_attempt_at) VALUES ('b', 'transition', 'hot', 'cold', 'GLACIER', 'source', 'processing', 3, EXTRACT(EPOCH FROM clock_timestamp()) + 300)");
        $this->firstPool->execute("INSERT INTO s3_restore_jobs (bucket, key_name, source_tier, source_storage_path, restore_days, status, lease_generation, next_attempt_at) VALUES ('b', 'restore', 'cold', 'source', 1, 'processing', 4, EXTRACT(EPOCH FROM clock_timestamp()) + 300)");
        $this->firstPool->execute("INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, status, lease_generation, next_attempt_at) VALUES ('b', 'hot', 'garbage', 'processing', 5, EXTRACT(EPOCH FROM clock_timestamp()) + 300)");
        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, claim_generation, dispatch_id) VALUES ('migration-multipart', 'upload', 'b', 'generation', 'owner', 'hot', 'assembly', 'final', 'digest', 'abandoned', 0, EXTRACT(EPOCH FROM clock_timestamp()) + 300, 6, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')");
        $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, claim_generation, dispatch_id) VALUES ('migration-parked', 'upload-parked', 'b', 'generation', 'owner', 'hot', 'assembly-parked', 'final-parked', 'digest-parked', 'abandoned', 0, 1.0e300, 9, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')");
        $this->firstPool->execute("INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, state, lease_until, claim_generation, dispatch_id) VALUES ('migration-object', 'b', 'generation', 'owner', 'hot', 'object', 'path-digest', 'put', 'reconciling', EXTRACT(EPOCH FROM clock_timestamp()) + 300, 7, 'cccccccccccccccccccccccccccccccc')");

        $this->firstPool->execute('DELETE FROM s3_schema_version WHERE version > 46');
        $this->firstPool->execute('ALTER TABLE s3_locks DROP COLUMN heartbeat_at, DROP COLUMN lease_generation');
        foreach (['s3_notification_queue', 's3_tier_transition_jobs', 's3_restore_jobs', 's3_storage_garbage', 's3_multipart_write_intents', 's3_object_write_intents'] as $table) {
            $this->firstPool->execute("ALTER TABLE {$table} DROP COLUMN claim_worker_id, DROP COLUMN claim_heartbeat_at");
        }
        $migration = PostgresSchema::getMigrationStatements(46);
        $v47 = array_values(array_filter($migration, static fn(string $sql): bool => str_contains($sql, 'ALTER TABLE s3_locks ADD COLUMN')));
        self::assertCount(1, $v47);
        $this->firstPool->execute($v47[0]);
        $this->firstPool->execute("INSERT INTO s3_schema_version (version, description) VALUES (47, 'Schema version 47')");

        foreach (['s3_locks' => ['heartbeat_at', 'lease_generation'], 's3_notification_queue' => ['claim_worker_id', 'claim_heartbeat_at'], 's3_tier_transition_jobs' => ['claim_worker_id', 'claim_heartbeat_at'], 's3_restore_jobs' => ['claim_worker_id', 'claim_heartbeat_at'], 's3_storage_garbage' => ['claim_worker_id', 'claim_heartbeat_at'], 's3_multipart_write_intents' => ['claim_worker_id', 'claim_heartbeat_at'], 's3_object_write_intents' => ['claim_worker_id', 'claim_heartbeat_at']] as $table => $columns) {
            foreach ($columns as $column) {
                $ddl = $this->firstPool->execute('SELECT COUNT(*) AS column_count FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = $1 AND column_name = $2', [$table, $column])->fetchRow();
                self::assertSame(1, (int) ($ddl['column_count'] ?? 0), "Missing {$table}.{$column}");
            }
        }

        $lifecycle = $this->firstPool->query("SELECT lease_generation, heartbeat_at, expires_at <= clock_timestamp() AS expired FROM s3_locks WHERE lock_name = 'lifecycle:global'")->fetchRow();
        self::assertSame(1, (int) ($lifecycle['lease_generation'] ?? 0));
        self::assertNotNull($lifecycle['heartbeat_at'] ?? null);
        self::assertTrue((bool) ($lifecycle['expired'] ?? false));
        foreach ([
            ['s3_notification_queue', 'id', $notificationId, 'pending', 8, 'lease_generation', 'next_attempt_at'],
            ['s3_tier_transition_jobs', 'key_name', 'transition', 'pending', 4, 'lease_generation', 'next_attempt_at'],
            ['s3_restore_jobs', 'key_name', 'restore', 'pending', 5, 'lease_generation', 'next_attempt_at'],
            ['s3_storage_garbage', 'storage_path', 'garbage', 'pending', 6, 'lease_generation', 'next_attempt_at'],
            ['s3_multipart_write_intents', 'attempt_id', 'migration-multipart', 'abandoned', 7, 'claim_generation', 'next_attempt_at'],
            ['s3_object_write_intents', 'attempt_id', 'migration-object', 'reconciling', 8, 'claim_generation', 'lease_until'],
        ] as [$table, $idColumn, $id, $state, $generation, $generationColumn, $leaseColumn]) {
            $stateColumn = str_contains($table, 'write_intents') ? 'state' : 'status';
            $row = $this->firstPool->execute("SELECT {$stateColumn} AS work_state, {$generationColumn} AS generation, claim_worker_id, claim_heartbeat_at, {$leaseColumn} <= EXTRACT(EPOCH FROM clock_timestamp()) AS expired FROM {$table} WHERE {$idColumn} = $1", [$id])->fetchRow();
            self::assertSame($state, $row['work_state'] ?? null, $table);
            self::assertSame($generation, (int) ($row['generation'] ?? 0), $table);
            self::assertNull($row['claim_worker_id'] ?? null, $table);
            self::assertNull($row['claim_heartbeat_at'] ?? null, $table);
            self::assertTrue((bool) ($row['expired'] ?? false), $table);
        }
        $parked = $this->firstPool->query("SELECT claim_generation, next_attempt_at FROM s3_multipart_write_intents WHERE attempt_id = 'migration-parked'")->fetchRow();
        self::assertSame(9, (int) ($parked['claim_generation'] ?? 0));
        self::assertSame(1.0e300, (float) ($parked['next_attempt_at'] ?? 0.0));
    }

    public function test_database_outage_has_no_local_clock_fallback(): void
    {
        $pool = new PostgresConnectionPool(PostgresConfig::fromString((string) getenv('S3_TEST_POSTGRES_METADATA_DSN')));
        $store = new PostgresMetadataStore($pool);
        $pool->close();

        $this->expectException(\Throwable::class);
        $store->claimClusterNotification('node-a:incarnation:notification', 1.0);
    }

    #[DataProvider('staleNotificationOutcomes')]
    public function test_cluster_notification_processor_cannot_persist_any_outcome_after_in_flight_takeover(int $httpStatus, int $maxAttempts): void
    {
        $id = $this->first->enqueueNotification(
            'bucket',
            'key',
            's3:ObjectCreated:Put',
            'https://8.8.8.8/hook',
            '{}',
            $maxAttempts,
        );
        $takeover = null;
        $client = new PostgresFencingNotificationClient(function () use ($id, &$takeover, $httpStatus): int {
            $this->firstPool->execute('UPDATE s3_notification_queue SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE id = $1', [$id]);
            $takeover = $this->second->claimClusterNotification('node-b:incarnation:notification', 300.0);
            self::assertNotNull($takeover);

            return $httpStatus;
        });
        $processor = new NotificationProcessor(
            $this->first,
            pollInterval: 0.005,
            httpClient: new HttpClient($client, []),
            clusterFencing: $this->first,
            clusterWorkerId: 'node-a:incarnation:notification',
        );
        try {
            $processor->start();
            $deadline = microtime(true) + 2.0;
            while ($client->requests === 0 && microtime(true) < $deadline) {
                \Amp\delay(0.005);
            }
            self::assertSame(1, $client->requests);
            \Amp\delay(0.02);
        } finally {
            $processor->stop();
        }

        self::assertNotNull($takeover);
        $row = $this->firstPool->execute('SELECT status, attempts, claim_worker_id, lease_generation FROM s3_notification_queue WHERE id = $1', [$id])->fetchRow();
        self::assertSame('processing', $row['status'] ?? null);
        self::assertSame(0, (int) ($row['attempts'] ?? -1));
        self::assertSame('node-b:incarnation:notification', $row['claim_worker_id'] ?? null);
        self::assertSame($takeover->token->generation, (int) ($row['lease_generation'] ?? 0));
    }

    /** @return iterable<string, array{int, int}> */
    public static function staleNotificationOutcomes(): iterable
    {
        yield 'sent' => [204, 3];
        yield 'retry' => [500, 3];
        yield 'dead-letter' => [500, 1];
    }

    private function seedWorkKind(string $kind): void
    {
        match ($kind) {
            ClusterWorkToken::LIFECYCLE => null,
            ClusterWorkToken::NOTIFICATION => $this->first->enqueueNotification('b', 'notification', 'event', 'https://example.test/hook', '{}'),
            ClusterWorkToken::TIER_TRANSITION => $this->firstPool->execute("INSERT INTO s3_tier_transition_jobs (bucket, key_name, source_tier, target_tier, target_storage_class, source_storage_path, next_attempt_at) VALUES ('b', 'transition', 'hot', 'cold', 'GLACIER', 'source', 0)"),
            ClusterWorkToken::RESTORE => $this->firstPool->execute("INSERT INTO s3_restore_jobs (bucket, key_name, source_tier, source_storage_path, restore_days, next_attempt_at) VALUES ('b', 'restore', 'cold', 'source', 1, 0)"),
            ClusterWorkToken::STORAGE_GARBAGE => $this->firstPool->execute("INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, next_attempt_at) VALUES ('b', 'hot', 'garbage', 0)"),
            ClusterWorkToken::MULTIPART_INTENT => $this->firstPool->execute("INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id) VALUES ('multipart-attempt', 'upload', 'b', 'generation', 'owner', 'hot', 'assembly', 'final', 'digest', 'active', 0, 0, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')"),
            ClusterWorkToken::OBJECT_INTENT => $this->firstPool->execute("INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, state, lease_until, dispatch_id) VALUES ('object-attempt', 'b', 'generation', 'owner', 'hot', 'object', 'path-digest', 'put', 'active', 0, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')"),
            default => throw new \LogicException('Unknown work kind.'),
        };
    }

    private function claimWorkKind(PostgresMetadataStore $store, string $kind, string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return match ($kind) {
            ClusterWorkToken::LIFECYCLE => $store->claimClusterLifecycle($workerId, $leaseSeconds),
            ClusterWorkToken::NOTIFICATION => $store->claimClusterNotification($workerId, $leaseSeconds),
            ClusterWorkToken::TIER_TRANSITION => $store->claimClusterTierTransition($workerId, $leaseSeconds),
            ClusterWorkToken::RESTORE => $store->claimClusterRestore($workerId, $leaseSeconds),
            ClusterWorkToken::STORAGE_GARBAGE => $store->claimClusterStorageGarbage($workerId, $leaseSeconds),
            ClusterWorkToken::MULTIPART_INTENT => $store->claimClusterMultipartIntent($workerId, $leaseSeconds),
            ClusterWorkToken::OBJECT_INTENT => $store->claimClusterObjectIntent('hot', $workerId, $leaseSeconds),
            default => throw new \LogicException('Unknown work kind.'),
        };
    }

    private function expireWorkKind(string $kind): void
    {
        match ($kind) {
            ClusterWorkToken::LIFECYCLE => $this->firstPool->execute("UPDATE s3_locks SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE lock_name = 'lifecycle:global'"),
            ClusterWorkToken::NOTIFICATION => $this->firstPool->execute("UPDATE s3_notification_queue SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE status = 'processing'"),
            ClusterWorkToken::TIER_TRANSITION => $this->firstPool->execute("UPDATE s3_tier_transition_jobs SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE status = 'processing'"),
            ClusterWorkToken::RESTORE => $this->firstPool->execute("UPDATE s3_restore_jobs SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE status = 'processing'"),
            ClusterWorkToken::STORAGE_GARBAGE => $this->firstPool->execute("UPDATE s3_storage_garbage SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE status = 'processing'"),
            ClusterWorkToken::MULTIPART_INTENT => $this->firstPool->execute("UPDATE s3_multipart_write_intents SET next_attempt_at = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE state = 'abandoned'"),
            ClusterWorkToken::OBJECT_INTENT => $this->firstPool->execute("UPDATE s3_object_write_intents SET lease_until = EXTRACT(EPOCH FROM clock_timestamp()) - 1 WHERE state = 'reconciling'"),
            default => throw new \LogicException('Unknown work kind.'),
        };
    }

    private function dropRetryFixtures(): void
    {
        $this->firstPool->execute('DROP TRIGGER IF EXISTS s3_test_cluster_retry ON s3_notification_queue');
        $this->firstPool->execute('DROP TRIGGER IF EXISTS s3_test_cluster_retry ON s3_locks');
        $this->firstPool->execute('DROP FUNCTION IF EXISTS s3_test_cluster_retry_fn()');
        $this->firstPool->execute('DROP SEQUENCE IF EXISTS s3_test_cluster_retry_seq');
    }

    private function clearWorkRows(): void
    {
        $this->firstPool->execute('DELETE FROM s3_cluster_membership');
        $this->firstPool->execute('DELETE FROM s3_notification_queue');
        $this->firstPool->execute('DELETE FROM s3_tier_transition_jobs');
        $this->firstPool->execute('DELETE FROM s3_restore_jobs');
        $this->firstPool->execute('DELETE FROM s3_storage_garbage');
        $this->firstPool->execute('DELETE FROM s3_multipart_write_intents');
        $this->firstPool->execute('DELETE FROM s3_object_write_intents');
        $this->firstPool->execute('DELETE FROM s3_locks');
        $this->firstPool->execute('UPDATE s3_cluster_work_observability SET successful_takeovers = 0, failed_takeovers = 0, stale_acknowledgements = 0, highest_generation = 0');
    }
}

final class PostgresFencingNotificationClient implements DelegateHttpClient
{
    public int $requests = 0;

    public function __construct(private readonly \Closure $onRequest) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $cancellation->throwIfRequested();
        $status = ($this->onRequest)();
        ++$this->requests;

        return new Response('1.1', $status, null, [], '', $request);
    }
}
