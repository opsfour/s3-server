<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Server\Driver\Client;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Middleware\ClusterRoleAdmissionMiddleware;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\ClusterMembershipStore;
use OpsFour\S3Server\Runtime\ClusterMembershipSnapshot;
use OpsFour\S3Server\Runtime\ClusterOperationalSnapshot;
use OpsFour\S3Server\Runtime\ClusterOperationalSnapshotStore;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\ClusterRoleSet;
use OpsFour\S3Server\Runtime\ClusterWorkSnapshot;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClusterRoleTest extends TestCase
{
    public function test_membership_snapshot_rejects_non_integer_or_extra_role_count_fields(): void
    {
        $counts = $this->zeroRoleCounts();
        $counts['api'] = ['ready' => '1', 'draining' => 0, 'extra' => 0];

        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionClass(ClusterMembershipSnapshot::class))->newInstanceArgs([
            1000.0, 'current', 999.0, 1.0, $counts,
        ]);
    }

    public function test_operational_snapshot_rejects_incomplete_role_count_fields(): void
    {
        $counts = $this->zeroRoleCounts();
        $counts['api'] = ['ready' => 0];
        $work = [];
        foreach (ClusterWorkSnapshot::ALL as $workClass) {
            $work[$workClass] = new ClusterWorkSnapshot($workClass, null, null, null, null, null, 0, 0, 0, 0);
        }

        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionClass(ClusterOperationalSnapshot::class))->newInstanceArgs([
            1000.0, 'current', 999.0, 1.0, $counts, $work,
        ]);
    }

    public function test_operational_snapshot_reports_db_time_roles_and_health_without_identifiers_in_metrics_shape(): void
    {
        $store = new ClusterOperationalRoleStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile', 'reconcile'));
        $controller->registerReady();

        $response = (new HealthCheckHandler(clusterRoles: $controller))->handleRequest($this->request('GET'));
        $body = $response->getBody()->read();
        self::assertNotNull($body);
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatus());
        self::assertSame('ready', $payload['node']['state']);
        self::assertSame('cluster-a', $payload['node']['cluster_id']);
        self::assertSame('node-a', $payload['node']['node_id']);
        self::assertSame($controller->healthIdentity()['incarnation_id'], $payload['node']['incarnation_id']);
        self::assertSame('current', $payload['node']['membership']);
        self::assertSame(990, $payload['node']['last_heartbeat_at']);
        self::assertSame(1, $payload['cluster']['role_counts']['reconcile']['ready']);
        self::assertSame(1000, $payload['cluster']['observed_at']);
        self::assertGreaterThan(0, $store->membershipSnapshotCalls);
        self::assertSame(0, $store->operationalSnapshotCalls, 'Health must not collect durable-work queues.');

        $store->snapshotUnavailable = true;
        $failed = (new HealthCheckHandler(clusterRoles: $controller))->handleRequest($this->request('GET'));
        $failedBody = $failed->getBody()->read();
        self::assertNotNull($failedBody);
        $failedPayload = json_decode($failedBody, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(503, $failed->getStatus());
        self::assertSame('unavailable', $failedPayload['cluster']['collection']);
        self::assertStringNotContainsString('database secret', $failedBody);
    }

    public function test_local_dependency_failure_is_node_scoped_without_claiming_shared_cluster_failure(): void
    {
        $store = new ClusterOperationalRoleStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile', 'reconcile'));
        $controller->registerReady();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException(new \RuntimeException('local metadata connection failed'));

        $response = (new HealthCheckHandler(metadata: $metadata, clusterRoles: $controller))
            ->handleRequest($this->request('GET'));
        $body = $response->getBody()->read();
        self::assertNotNull($body);
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatus());
        self::assertSame('unavailable', $payload['dependencies']['metadata']['state']);
        self::assertSame('node', $payload['dependencies']['metadata']['scope']);
        self::assertSame(['all_mutations'], $payload['dependencies']['metadata']['affected_scopes']);
        self::assertSame('unavailable', $payload['dependencies']['credentials']['state']);
        self::assertSame('unobserved', $payload['dependencies']['credentials']['observation']);
        self::assertSame('unavailable', $payload['dependencies']['storage_admission']['state']);
        self::assertSame('unobserved', $payload['dependencies']['storage_admission']['observation']);
        self::assertSame('stopped', $payload['node']['state']);
        self::assertSame('current', $payload['cluster']['collection']);
        self::assertSame([], $payload['cluster']['missing_roles']);
    }

    public function test_cluster_drain_short_circuit_reports_full_unobserved_dependency_catalog(): void
    {
        $store = new ClusterOperationalRoleStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile', 'reconcile'));
        $controller->registerReady();
        $drain = new \OpsFour\S3Server\Runtime\NodeDrainController();
        $drain->beginDrain();

        $response = (new HealthCheckHandler(clusterRoles: $controller, drain: $drain))
            ->handleRequest($this->request('GET'));
        $body = $response->getBody()->read();
        self::assertNotNull($body);
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatus());
        self::assertSame(
            ['admission', 'metadata', 'storage_admission', 'credentials', 'role_coverage', 'temporary_capacity', 'drain'],
            array_keys($payload['dependencies']),
        );
        self::assertSame('draining', $payload['dependencies']['drain']['reason']);
        self::assertSame('node', $payload['dependencies']['drain']['scope']);
        self::assertSame(['all_mutations'], $payload['dependencies']['drain']['affected_scopes']);
        foreach (array_diff(array_keys($payload['dependencies']), ['drain']) as $dependency) {
            self::assertSame('unavailable', $payload['dependencies'][$dependency]['state']);
            self::assertSame('unobserved', $payload['dependencies'][$dependency]['observation']);
            self::assertArrayNotHasKey('reason', $payload['dependencies'][$dependency]);
        }
    }

    public function test_cluster_temp_capacity_short_circuit_keeps_known_failure_distinct_from_unobserved_checks(): void
    {
        $store = new ClusterOperationalRoleStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile', 'reconcile'));
        $controller->registerReady();
        $tempDir = sys_get_temp_dir() . '/s3-cluster-health-temp-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new \OpsFour\S3Server\Storage\StorageTempBudgetCoordinator($tempDir, 1024, 0);
        $unknown = $tempDir . '/s3fw-put-unaccounted';
        self::assertSame(4, file_put_contents($unknown, 'data'));

        try {
            $response = (new HealthCheckHandler(clusterRoles: $controller, tempBudget: $coordinator))
                ->handleRequest($this->request('GET'));
            $body = $response->getBody()->read();
            self::assertNotNull($body);
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

            self::assertSame(503, $response->getStatus());
            self::assertSame('unsafe_state', $payload['dependencies']['temporary_capacity']['reason']);
            self::assertSame('node', $payload['dependencies']['temporary_capacity']['scope']);
            self::assertSame(['all_mutations'], $payload['dependencies']['temporary_capacity']['affected_scopes']);
            self::assertSame('unavailable', $payload['dependencies']['metadata']['state']);
            self::assertSame('unobserved', $payload['dependencies']['metadata']['observation']);
            self::assertArrayNotHasKey('reason', $payload['dependencies']['metadata']);
        } finally {
            @unlink($unknown);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_config_canonicalizes_roles_and_keeps_standalone_all_in_one(): void
    {
        $cluster = $this->config('webhook, api,reconcile', 'reconcile,api');

        self::assertSame(['api', 'reconcile', 'webhook'], $cluster->clusterRoles);
        self::assertSame(['api', 'reconcile'], $cluster->clusterRequiredRoles);
        self::assertTrue($cluster->hasClusterRole(ClusterRoleSet::API));
        self::assertFalse($cluster->hasClusterRole(ClusterRoleSet::GARBAGE));

        $standalone = new S3ServerConfig(storagePath: '/');
        self::assertSame(ClusterRoleSet::ALL, $standalone->clusterRoles);
        self::assertTrue($standalone->hasClusterRole(ClusterRoleSet::GARBAGE));
    }

    public function test_cluster_membership_diagnostics_share_the_readiness_deadline(): void
    {
        $store = new ClusterOperationalRoleStore();
        $store->snapshotDelaySeconds = 0.05;
        $controller = new ClusterRoleController($store, $this->config('api,reconcile', 'reconcile'));
        $controller->registerReady();

        $response = (new HealthCheckHandler(
            clusterRoles: $controller,
            readinessCheckIntervalSeconds: 0,
            readinessCheckTimeoutSeconds: 0.001,
        ))->handleRequest($this->request('GET'));
        $payload = json_decode((string) $response->getBody()->read(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatus());
        self::assertSame('stopped', $payload['node']['state']);
        self::assertSame('unavailable', $payload['cluster']['collection']);
        self::assertSame('unavailable', $payload['dependencies']['role_coverage']['state']);
        self::assertSame('unobserved', $payload['dependencies']['role_coverage']['observation']);
        self::assertSame(1, $store->membershipSnapshotCalls);
    }

    public function test_local_membership_withdrawal_cannot_report_ready_from_a_still_live_database_row(): void
    {
        $store = new ClusterOperationalRoleStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile', 'reconcile'));
        $controller->registerReady();
        $controller->withdraw();

        $response = (new HealthCheckHandler(clusterRoles: $controller))->handleRequest($this->request('GET'));
        $payload = json_decode((string) $response->getBody()->read(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatus());
        self::assertSame('stopped', $payload['node']['state']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRoles(): iterable
    {
        yield 'blank' => ['api,,reconcile'];
        yield 'duplicate' => ['api,api'];
        yield 'unknown' => ['api,broker'];
    }

    #[DataProvider('invalidRoles')]
    public function test_invalid_cluster_roles_fail_configuration(string $roles): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config($roles);
    }

    public function test_api_only_registration_fails_without_live_reconciliation(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('api'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live reconcile role coverage');
        $controller->registerReady();
    }

    public function test_worker_membership_advertises_only_assigned_roles_and_fails_closed_on_db_error(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('transition'));
        $controller->registerReady();

        self::assertSame(['transition'], $store->roles);
        self::assertTrue($controller->canClaim(ClusterRoleSet::TRANSITION));
        self::assertFalse($controller->canClaim(ClusterRoleSet::RESTORE));
        self::assertSame('current', $controller->diagnostics()['membership']);

        $store->unavailable = true;
        self::assertFalse($controller->canClaim(ClusterRoleSet::TRANSITION));
        self::assertSame('unavailable', $controller->diagnostics()['membership']);
    }

    public function test_server_rejects_role_controller_bound_to_different_assigned_roles(): void
    {
        $controller = new ClusterRoleController(new ClusterRoleMemoryStore(), $this->config('transition'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not bound to this server configuration');
        $controller->assertBoundToConfig($this->config('api,reconcile'));
    }

    public function test_request_coverage_checks_do_not_renew_membership(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile'));
        $controller->registerReady();

        self::assertTrue($controller->hasLiveRole(ClusterRoleSet::RECONCILE));
        self::assertSame('current', $controller->diagnostics()['membership']);
        self::assertSame(0, $store->heartbeats);
        self::assertSame(2, $store->liveMemberChecks);
    }

    public function test_role_controller_rejects_a_different_membership_store(): void
    {
        $controller = new ClusterRoleController(new ClusterRoleMemoryStore(), $this->config('reconcile'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exact PostgreSQL membership store');
        $controller->assertStore(new ClusterRoleMemoryStore());
    }

    public function test_withdrawal_serializes_after_an_in_flight_heartbeat(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('reconcile'));
        $controller->registerReady();
        $store->heartbeatStarted = new DeferredFuture();
        $store->releaseHeartbeat = new DeferredFuture();

        $heartbeat = \Amp\async($controller->heartbeat(...));
        $store->heartbeatStarted->getFuture()->await();
        $withdraw = \Amp\async($controller->withdraw(...));
        $store->releaseHeartbeat->complete();
        self::assertTrue($heartbeat->await());
        $withdraw->await();

        self::assertSame(1, $store->withdrawals);
        self::assertFalse($controller->hasLiveRole(ClusterRoleSet::RECONCILE));
    }

    public function test_membership_can_be_safely_republished_after_admission_recovers(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('reconcile'));
        $controller->registerReady();
        $controller->withdraw();

        self::assertTrue($controller->maintainReady());
        self::assertTrue($controller->hasLiveRole(ClusterRoleSet::RECONCILE));
    }

    public function test_drain_publishes_not_ready_before_final_withdrawal_and_never_reopens(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile'));
        $controller->registerReady();

        $controller->beginDrain();
        $controller->beginDrain();

        self::assertSame(0, $store->withdrawals);
        self::assertTrue($store->draining);
        self::assertFalse($controller->hasLiveRole(ClusterRoleSet::API));
        self::assertFalse($controller->canClaim(ClusterRoleSet::RECONCILE));
        self::assertTrue($controller->maintainReady());
        self::assertFalse($controller->hasLiveRole(ClusterRoleSet::API));
        self::assertSame(2, $store->heartbeats);

        $controller->withdraw();
        self::assertSame(1, $store->withdrawals);
    }

    public function test_drain_fails_closed_without_throwing_when_demotion_fails_or_row_is_absent(): void
    {
        $unavailable = new ClusterRoleMemoryStore();
        $unavailableController = new ClusterRoleController($unavailable, $this->config('reconcile'));
        $unavailableController->registerReady();
        $unavailable->unavailable = true;

        $unavailableController->beginDrain();

        self::assertFalse($unavailableController->canClaim(ClusterRoleSet::RECONCILE));

        $absent = new ClusterRoleMemoryStore();
        $absentController = new ClusterRoleController($absent, $this->config('reconcile'));
        $absentController->registerReady();
        $absent->heartbeatResult = false;

        $absentController->beginDrain();

        self::assertFalse($absentController->canClaim(ClusterRoleSet::RECONCILE));
        self::assertSame(1, $absent->heartbeats);
    }

    public function test_claim_waiting_behind_drain_cannot_republish_ready_membership(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('reconcile'));
        $controller->registerReady();
        $store->heartbeatStarted = new DeferredFuture();
        $store->releaseHeartbeat = new DeferredFuture();

        $heartbeat = \Amp\async($controller->heartbeat(...));
        $store->heartbeatStarted->getFuture()->await();
        $drain = \Amp\async($controller->beginDrain(...));
        $claim = \Amp\async(static fn(): bool => $controller->canClaim(ClusterRoleSet::RECONCILE));
        $store->releaseHeartbeat->complete();

        self::assertTrue($heartbeat->await());
        $drain->await();
        self::assertFalse($claim->await());
        self::assertFalse($controller->hasLiveRole(ClusterRoleSet::RECONCILE));
        self::assertSame(2, $store->heartbeats);
    }

    /** @return iterable<string, array{S3Operation}> */
    public static function reconciliationDependentOperations(): iterable
    {
        yield 'put object' => [S3Operation::PutObject];
        yield 'copy object' => [S3Operation::CopyObject];
        yield 'post object' => [S3Operation::PostObject];
        yield 'upload part' => [S3Operation::UploadPart];
        yield 'upload part copy' => [S3Operation::UploadPartCopy];
        yield 'complete multipart upload' => [S3Operation::CompleteMultipartUpload];
    }

    #[DataProvider('reconciliationDependentOperations')]
    public function test_missing_reconcile_rejects_dependent_operation_before_handler(S3Operation $operation): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile'));
        $controller->registerReady();
        $store->liveRoles = ['api'];
        $calls = 0;
        $handler = new class ($calls) implements RequestHandler {
            public function __construct(private int &$calls) {}
            public function handleRequest(Request $request): Response
            {
                ++$this->calls;
                return new Response(204);
            }
        };
        $gate = new ClusterRoleAdmissionMiddleware($controller);
        $request = $this->request('PUT');
        $request->setAttribute('s3.operation', $operation);

        try {
            $gate->handleRequest($request, $handler);
            self::fail('Mutation reached the handler without live reconciliation.');
        } catch (SlowDownException) {
            self::assertSame(0, $calls);
        }
    }

    public function test_missing_reconcile_keeps_bucket_configuration_mutations_and_reads_available(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController($store, $this->config('api,reconcile'));
        $controller->registerReady();
        $store->liveRoles = ['api'];
        $calls = 0;
        $handler = new class ($calls) implements RequestHandler {
            public function __construct(private int &$calls) {}
            public function handleRequest(Request $request): Response
            {
                ++$this->calls;
                return new Response(204);
            }
        };
        $gate = new ClusterRoleAdmissionMiddleware($controller);

        $bucketMutation = $this->request('PUT');
        $bucketMutation->setAttribute('s3.operation', S3Operation::PutBucketLifecycle);
        self::assertSame(204, $gate->handleRequest($bucketMutation, $handler)->getStatus());

        $read = $this->request('GET');
        $read->setAttribute('s3.operation', S3Operation::GetObject);
        self::assertSame(204, $gate->handleRequest($read, $handler)->getStatus());
        self::assertSame(2, $calls);
    }

    public function test_missing_non_safety_required_role_degrades_health_without_removing_readiness(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController(
            $store,
            $this->config('api,reconcile', 'reconcile,webhook'),
        );
        $controller->registerReady();
        $store->liveRoles = ['api', 'reconcile'];

        $response = (new HealthCheckHandler(clusterRoles: $controller))->handleRequest($this->request('GET'));
        $payload = json_decode((string) $response->getBody()->read(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatus());
        self::assertSame('degraded', $payload['status'] ?? null);
        self::assertSame(['webhook'], $payload['cluster']['missing_roles'] ?? null);
        self::assertSame('degraded', $payload['dependencies']['role_coverage']['state'] ?? null);
    }

    public function test_missing_required_reconcile_role_removes_health_readiness(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController(
            $store,
            $this->config('api,reconcile', 'reconcile,webhook'),
        );
        $controller->registerReady();
        $store->liveRoles = ['api', 'webhook'];

        $response = (new HealthCheckHandler(clusterRoles: $controller))->handleRequest($this->request('GET'));
        $payload = json_decode((string) $response->getBody()->read(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatus());
        self::assertSame('unavailable', $payload['dependencies']['role_coverage']['state'] ?? null);
        self::assertSame('degraded', $payload['status'] ?? null);
        self::assertSame(['reconcile'], $payload['cluster']['missing_roles'] ?? null);
    }

    public function test_api_health_requires_live_reconcile_even_when_policy_omits_it(): void
    {
        $store = new ClusterRoleMemoryStore();
        $controller = new ClusterRoleController(
            $store,
            $this->config('api,reconcile', 'webhook'),
        );
        $controller->registerReady();
        $store->liveRoles = ['api', 'webhook'];

        $response = (new HealthCheckHandler(clusterRoles: $controller))->handleRequest($this->request('GET'));

        self::assertSame(503, $response->getStatus());
    }

    public function test_unexpected_durable_role_loop_exit_withdraws_membership_permanently(): void
    {
        $store = new ClusterRoleMemoryStore();
        $config = $this->config('transition');
        $controller = new ClusterRoleController($store, $config);
        $controller->registerReady();
        $server = new S3Server($config);
        $this->bindRoleControllerForTest($server, $controller);
        $cancellation = new DeferredCancellation();

        $exit = new \ReflectionMethod($server, 'handleAssignedRoleLoopExit');
        $exit->invoke($server, 'physical_role_worker', $cancellation->getCancellation());

        self::assertSame(1, $store->withdrawals);
        self::assertFalse($controller->heartbeat());
        self::assertTrue((new \ReflectionProperty($server, 'durableRoleLoopFailed'))->getValue($server));
    }

    public function test_normal_durable_role_loop_cancellation_does_not_report_failure(): void
    {
        $store = new ClusterRoleMemoryStore();
        $config = $this->config('transition');
        $controller = new ClusterRoleController($store, $config);
        $controller->registerReady();
        $server = new S3Server($config);
        $this->bindRoleControllerForTest($server, $controller);
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();

        (new \ReflectionMethod($server, 'handleAssignedRoleLoopExit'))
            ->invoke($server, 'physical_role_worker', $cancellation->getCancellation());

        self::assertSame(0, $store->withdrawals);
        self::assertFalse((new \ReflectionProperty($server, 'durableRoleLoopFailed'))->getValue($server));
    }

    public function test_api_only_runtime_prepares_no_durable_workers(): void
    {
        $components = $this->backgroundComponents('api');

        foreach ($components as $name => $component) {
            self::assertTrue($component === null || $component === [], $name);
        }
    }

    public function test_worker_only_runtime_exposes_only_probe_and_metrics_surfaces(): void
    {
        $server = new S3Server($this->config('transition'));
        $surfaces = (new \ReflectionMethod($server, 'runtimeHttpSurfaces'))->invoke($server);
        $calls = 0;
        $apiHandler = new class ($calls) implements RequestHandler {
            public function __construct(private int &$calls) {}
            public function handleRequest(Request $request): Response
            {
                ++$this->calls;
                return new Response(200);
            }
        };
        $fallback = (new \ReflectionMethod($server, 'roleAwareS3Fallback'))->invoke($server, $apiHandler);

        self::assertSame(['live', 'health', 'metrics'], $surfaces);
        self::assertInstanceOf(RequestHandler::class, $fallback);
        self::assertSame(404, $fallback->handleRequest($this->request('GET'))->getStatus());
        self::assertSame(0, $calls);
    }

    public function test_mixed_runtime_prepares_only_assigned_components(): void
    {
        $components = $this->backgroundComponents('api,transition,reconcile');

        self::assertNull($components['lifecycleRunner']);
        self::assertNull($components['notificationProcessor']);
        self::assertSame([], $components['objectCollectors']);
        self::assertNotNull($components['transitionExecutor']);
        self::assertNull($components['restoreExecutor']);
        self::assertNull($components['restoreGarbageCollector']);
        self::assertNull($components['storageGarbageCollector']);
        self::assertNotNull($components['multipartIntentCollector']);
    }

    private function config(string $roles, ?string $requiredRoles = null): S3ServerConfig
    {
        return new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            clusterRoles: $roles,
            clusterRequiredRoles: $requiredRoles,
        );
    }

    /** @return array<string, array{ready: int, draining: int}> */
    private function zeroRoleCounts(): array
    {
        $counts = [];
        foreach (ClusterRoleSet::ALL as $role) {
            $counts[$role] = ['ready' => 0, 'draining' => 0];
        }

        return $counts;
    }

    /** @return array<string, object|array<array-key, object>|null> */
    private function backgroundComponents(string $roles): array
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $server = new S3Server($this->config($roles), $metadata, new InMemoryBackend());

        /** @var array<string, object|array<array-key, object>|null> $components */
        $components = (new \ReflectionMethod($server, 'prepareBackgroundComponents'))->invoke($server);

        return $components;
    }

    private function bindRoleControllerForTest(S3Server $server, ClusterRoleController $controller): void
    {
        (new \ReflectionProperty($server, 'clusterRoles'))->setValue($server, $controller);
    }

    /** @param non-empty-string $method */
    private function request(string $method): Request
    {
        return new Request(new ClusterRoleClient(), $method, Http::new('http://localhost/bucket/key'));
    }
}

class ClusterRoleMemoryStore implements ClusterMembershipStore
{
    /** @var list<string> */
    public array $roles = [];
    /** @var list<string>|null */
    public ?array $liveRoles = null;
    public bool $unavailable = false;
    public bool $heartbeatResult = true;
    public int $withdrawals = 0;
    public int $heartbeats = 0;
    public int $liveMemberChecks = 0;
    /** @var DeferredFuture<void>|null */
    public ?DeferredFuture $heartbeatStarted = null;
    /** @var DeferredFuture<void>|null */
    public ?DeferredFuture $releaseHeartbeat = null;
    private bool $ready = false;
    public bool $draining = false;

    public function registerClusterMember(string $clusterId, string $nodeId, string $incarnationId, array $roles, bool $ready, bool $draining, float $ttlSeconds): void
    {
        $this->roles = $roles;
        $this->ready = $ready;
        $this->draining = $draining;
    }

    public function heartbeatClusterMember(string $clusterId, string $nodeId, string $incarnationId, bool $ready, bool $draining, float $ttlSeconds): bool
    {
        ++$this->heartbeats;
        if ($this->unavailable) {
            throw new \RuntimeException('database unavailable');
        }
        if ($this->heartbeatStarted !== null) {
            $started = $this->heartbeatStarted;
            $this->heartbeatStarted = null;
            $started->complete();
        }
        $this->releaseHeartbeat?->getFuture()->await();
        if (! $this->heartbeatResult) {
            return false;
        }
        $this->ready = $ready;
        $this->draining = $draining;
        return true;
    }

    public function withdrawClusterMember(string $clusterId, string $nodeId, string $incarnationId): void
    {
        ++$this->withdrawals;
        $this->ready = false;
        $this->draining = false;
    }

    public function isClusterMemberLive(string $clusterId, string $nodeId, string $incarnationId): bool
    {
        ++$this->liveMemberChecks;

        return $this->ready;
    }

    public function liveClusterRoles(string $clusterId): array
    {
        if ($this->unavailable) {
            throw new \RuntimeException('database unavailable');
        }
        return $this->ready ? ($this->liveRoles ?? $this->roles) : [];
    }
}

final class ClusterOperationalRoleStore extends ClusterRoleMemoryStore implements ClusterOperationalSnapshotStore
{
    public bool $snapshotUnavailable = false;

    public float $snapshotDelaySeconds = 0.0;

    public int $membershipSnapshotCalls = 0;

    public int $operationalSnapshotCalls = 0;

    public function clusterMembershipSnapshot(string $clusterId, string $nodeId, string $incarnationId): ClusterMembershipSnapshot
    {
        ++$this->membershipSnapshotCalls;
        if ($this->snapshotDelaySeconds > 0.0) {
            \Amp\delay($this->snapshotDelaySeconds);
        }
        if ($this->snapshotUnavailable) {
            throw new \RuntimeException('database secret must not escape');
        }

        return new ClusterMembershipSnapshot(1000.0, 'current', 990.0, 10.0, $this->roleCounts());
    }

    public function clusterOperationalSnapshot(string $clusterId, string $nodeId, string $incarnationId): ClusterOperationalSnapshot
    {
        ++$this->operationalSnapshotCalls;
        if ($this->snapshotUnavailable) {
            throw new \RuntimeException('database secret must not escape');
        }
        $work = [];
        foreach (ClusterWorkSnapshot::ALL as $queue) {
            $work[$queue] = new ClusterWorkSnapshot($queue, $queue === 'lifecycle' ? null : 0, null, 0, 0, null, 0, 0, 0, 0);
        }

        return new ClusterOperationalSnapshot(1000.0, 'current', 990.0, 10.0, $this->roleCounts(), $work);
    }

    /** @return array<string, array{ready: int, draining: int}> */
    private function roleCounts(): array
    {
        $roles = [];
        foreach (ClusterRoleSet::ALL as $role) {
            $roles[$role] = [
                'ready' => in_array($role, [ClusterRoleSet::API, ClusterRoleSet::RECONCILE], true) ? 1 : 0,
                'draining' => 0,
            ];
        }

        return $roles;
    }
}

final class ClusterRoleClient implements Client
{
    public function getId(): int
    {
        return 1;
    }
    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }
    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }
    public function isEncrypted(): bool
    {
        return false;
    }
    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return null;
    }
    public function onClose(\Closure $onClose): void {}
    public function close(): void {}
    public function isClosed(): bool
    {
        return false;
    }
}
