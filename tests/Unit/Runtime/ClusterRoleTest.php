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
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\ClusterMembershipStore;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\ClusterRoleSet;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClusterRoleTest extends TestCase
{
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

final class ClusterRoleMemoryStore implements ClusterMembershipStore
{
    /** @var list<string> */
    public array $roles = [];
    /** @var list<string>|null */
    public ?array $liveRoles = null;
    public bool $unavailable = false;
    public int $withdrawals = 0;
    public int $heartbeats = 0;
    public int $liveMemberChecks = 0;
    /** @var DeferredFuture<void>|null */
    public ?DeferredFuture $heartbeatStarted = null;
    /** @var DeferredFuture<void>|null */
    public ?DeferredFuture $releaseHeartbeat = null;
    private bool $ready = false;

    public function registerClusterMember(string $clusterId, string $nodeId, string $incarnationId, array $roles, bool $ready, float $ttlSeconds): void
    {
        $this->roles = $roles;
        $this->ready = $ready;
    }

    public function heartbeatClusterMember(string $clusterId, string $nodeId, string $incarnationId, bool $ready, float $ttlSeconds): bool
    {
        ++$this->heartbeats;
        if ($this->unavailable) {
            throw new \RuntimeException('database unavailable');
        }
        $this->heartbeatStarted?->complete();
        $this->releaseHeartbeat?->getFuture()->await();
        $this->ready = $ready;
        return true;
    }

    public function withdrawClusterMember(string $clusterId, string $nodeId, string $incarnationId): void
    {
        ++$this->withdrawals;
        $this->ready = false;
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
