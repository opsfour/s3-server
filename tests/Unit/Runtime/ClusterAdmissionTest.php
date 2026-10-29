<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Auth\ClusterAuthenticationIdentityProvider;
use OpsFour\S3Server\Encryption\ClusterEncryptionIdentity;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Contracts\AuthenticationMiddleware;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Middleware\ClusterAdmissionMiddleware;
use OpsFour\S3Server\Runtime\ClusterAdmissionController;
use OpsFour\S3Server\Runtime\ClusterAdmissionResult;
use OpsFour\S3Server\Runtime\ClusterAdmissionStore;
use OpsFour\S3Server\Runtime\ClusterCapabilityFingerprint;
use OpsFour\S3Server\Runtime\ClusterWorkerIdentity;
use OpsFour\S3Server\Runtime\MetadataDriverStartupGuard;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ClusterSafeStorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ClusterAdmissionTest extends TestCase
{
    public function test_first_and_compatible_joins_share_one_contract_while_variable_ids_do_not_affect_equivalence(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $first = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $second = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-b'), 0.0);

        $first->admitInitial();
        $second->admitInitial();

        self::assertTrue($first->isAdmitted());
        self::assertTrue($second->isAdmitted());
        self::assertSame(1, $store->initializations);
        self::assertSame('matched', $first->diagnostics()['admission']);
    }

    public function test_concurrent_first_joins_converge_on_one_contract(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $store->delayInitialization = true;
        $controllers = [
            new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0),
            new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-b'), 0.0),
        ];

        \Amp\Future\await(array_map(
            static fn(ClusterAdmissionController $controller) => \Amp\async($controller->admitInitial(...)),
            $controllers,
        ));

        self::assertSame(1, $store->initializations);
        self::assertTrue($controllers[0]->isAdmitted());
        self::assertTrue($controllers[1]->isAdmitted());
    }

    public function test_mismatch_is_bounded_to_one_category_and_never_replaces_the_contract(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        (new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0))->admitInitial();
        $mismatch = new ClusterAdmissionController(
            $store,
            $this->fingerprint('cluster-a', 'node-b', strictBucketNaming: false),
            0.0,
        );

        $mismatch->admitInitial();

        self::assertFalse($mismatch->isAdmitted());
        self::assertSame('compatibility', $mismatch->category());
        $diagnostics = $mismatch->diagnostics();
        self::assertArrayHasKey('category', $diagnostics);
        self::assertSame('compatibility', $diagnostics['category'] ?? null);
        self::assertSame(1, $store->initializations);
    }

    public function test_runtime_outage_closes_admission_and_only_a_complete_match_reopens_it(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $store->unavailable = true;

        self::assertFalse($controller->revalidate(true));
        self::assertSame('database', $controller->category());

        $store->unavailable = false;
        self::assertTrue($controller->revalidate(true));
        self::assertNull($controller->category());
    }

    public function test_overlapping_revalidations_share_one_authoritative_comparison(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $store->compareCalls = 0;
        $store->comparisonDelaySeconds = 0.01;

        $results = \Amp\Future\await([
            \Amp\async(fn(): bool => $controller->revalidate(true)),
            \Amp\async(fn(): bool => $controller->revalidate(true)),
        ]);

        self::assertSame([true, true], $results);
        self::assertSame(1, $store->compareCalls);
        self::assertTrue($controller->isAdmitted());
    }

    public function test_repeated_timeouts_share_one_stale_flight_before_a_fresh_comparison_recovers(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController(
            $store,
            $this->fingerprint('cluster-a', 'node-a'),
            revalidationIntervalSeconds: 0.0,
            comparisonTimeoutSeconds: 0.005,
        );
        $controller->admitInitial();
        $store->compareCalls = 0;
        $store->comparisonDelaySeconds = 0.03;

        self::assertFalse($controller->revalidate(true));
        self::assertFalse($controller->revalidate(true));
        self::assertSame(1, $store->compareCalls, 'Timed-out callers must retain and share the running comparison.');
        \Amp\delay(0.04);
        self::assertFalse($controller->isAdmitted());

        $store->comparisonDelaySeconds = 0.0;
        self::assertTrue($controller->revalidate(true));
        self::assertSame(2, $store->compareCalls);
    }

    public function test_cancelled_comparison_closes_and_never_late_reopens_admission(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $store->comparisonDelaySeconds = 0.02;
        $deferred = new \Amp\DeferredCancellation();
        $future = \Amp\async(fn(): bool => $controller->revalidate(true, $deferred->getCancellation()));
        \Amp\delay(0.001);
        $deferred->cancel();

        try {
            $future->await();
            self::fail('Cancelled cluster comparison returned normally.');
        } catch (\Amp\CancelledException) {
        }
        self::assertFalse($controller->isAdmitted());
        \Amp\delay(0.03);
        self::assertFalse($controller->isAdmitted());
    }

    public function test_live_fingerprint_factory_detects_runtime_compatibility_drift(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $strict = true;
        $factory = function () use (&$strict): ClusterCapabilityFingerprint {
            return $this->fingerprint(
                'cluster-a',
                'node-a',
                strictBucketNaming: $strict,
            );
        };
        $controller = new ClusterAdmissionController(
            $store,
            $factory(),
            revalidationIntervalSeconds: 0.0,
            fingerprintFactory: $factory,
        );
        $controller->admitInitial();
        $strict = false;

        self::assertFalse($controller->revalidate(true));
        self::assertSame('compatibility', $controller->category());
    }

    public function test_unqualified_storage_is_rejected_before_runtime_can_listen(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicit qualified cluster capability');

        ClusterCapabilityFingerprint::fromRuntime(
            new S3ServerConfig(storagePath: '/', clusterId: 'cluster-a', clusterNodeId: 'node-a', clusterDatabaseId: str_repeat('d', 32)),
            StorageTierRegistry::single(new InMemoryBackend()),
            null,
            'public',
            str_repeat('d', 32),
            new ClusterAdmissionAuthenticationIdentity(),
        );
    }

    public function test_non_postgres_cluster_profile_is_rejected_before_runtime_can_listen(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cluster mode requires PostgreSQL metadata.');

        MetadataDriverStartupGuard::clusterPostgresStore(null, 'sqlite', '');
    }

    public function test_wrong_operator_database_pin_is_rejected_before_runtime_can_listen(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match the live PostgreSQL metadata database');

        ClusterCapabilityFingerprint::fromRuntime(
            new S3ServerConfig(
                storagePath: '/',
                clusterId: 'cluster-a',
                clusterNodeId: 'node-a',
                clusterDatabaseId: str_repeat('e', 32),
            ),
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
            null,
            'public',
            str_repeat('d', 32),
            new ClusterAdmissionAuthenticationIdentity(),
        );
    }

    public function test_missing_authentication_identity_is_rejected_before_runtime_can_listen(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authentication and owner-mapping identity');

        ClusterCapabilityFingerprint::fromRuntime(
            new S3ServerConfig(
                storagePath: '/',
                clusterId: 'cluster-a',
                clusterNodeId: 'node-a',
                clusterDatabaseId: str_repeat('d', 32),
            ),
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
            null,
            'public',
            str_repeat('d', 32),
        );
    }

    public function test_cluster_postgres_store_is_bound_to_the_exact_configured_dsn(): void
    {
        $store = PostgresMetadataStore::fromDsn('host=127.0.0.1 user=test db=cluster_a');
        self::assertSame(
            $store,
            MetadataDriverStartupGuard::clusterPostgresStore(
                $store,
                'postgres',
                'host=127.0.0.1 user=test db=cluster_a',
            ),
        );

        try {
            MetadataDriverStartupGuard::clusterPostgresStore(
                $store,
                'postgres',
                'host=127.0.0.1 user=test db=cluster_b',
            );
            self::fail('A PostgreSQL store created for another DSN was admitted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('exact configured DSN', $error->getMessage());
        }
    }

    public function test_tier_digest_is_sorted_bytewise_independent_of_configuration_order(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
        );
        $first = new StorageTierRegistry([
            new StorageTier('z-cold', new ClusterAdmissionStorageBackend()),
            new StorageTier('A-hot', new ClusterAdmissionStorageBackend(), defaultWriteTier: true),
        ]);
        $second = new StorageTierRegistry([
            new StorageTier('A-hot', new ClusterAdmissionStorageBackend(), defaultWriteTier: true),
            new StorageTier('z-cold', new ClusterAdmissionStorageBackend()),
        ]);

        self::assertSame(
            ClusterCapabilityFingerprint::fromRuntime($config, $first, null, 'public', str_repeat('d', 32), new ClusterAdmissionAuthenticationIdentity())->digest('tiers'),
            ClusterCapabilityFingerprint::fromRuntime($config, $second, null, 'public', str_repeat('d', 32), new ClusterAdmissionAuthenticationIdentity())->digest('tiers'),
        );
    }

    public function test_database_identity_and_exact_compatibility_values_affect_their_categories(): void
    {
        $base = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            baseDomain: 's3.example.test',
            websiteHostPattern: '*.website.example.test',
        );
        $changed = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('e', 32),
            baseDomain: 'objects.example.test',
            websiteHostPattern: '*.alternate.example.test',
            quota: new QuotaConfig(1, 2, 3, 4, 5, 6, 7, 8),
            multipartMaxAgeSeconds: 123,
            lifecycleLockTtlSeconds: 456,
            notificationWorkerEnabled: false,
        );
        $tiers = StorageTierRegistry::single(new ClusterAdmissionStorageBackend());
        $baseFingerprint = ClusterCapabilityFingerprint::fromRuntime(
            $base,
            $tiers,
            null,
            'public',
            str_repeat('d', 32),
            new ClusterAdmissionAuthenticationIdentity(),
        );
        $changedFingerprint = ClusterCapabilityFingerprint::fromRuntime(
            $changed,
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
            null,
            'public',
            str_repeat('e', 32),
            new ClusterAdmissionAuthenticationIdentity(),
        );
        $notificationDisabledFingerprint = ClusterCapabilityFingerprint::fromRuntime(
            $base->with(['notificationWorkerEnabled' => false]),
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
            null,
            'public',
            str_repeat('d', 32),
            new ClusterAdmissionAuthenticationIdentity(),
        );

        self::assertNotSame($baseFingerprint->digest('database'), $changedFingerprint->digest('database'));
        self::assertNotSame($baseFingerprint->digest('compatibility'), $changedFingerprint->digest('compatibility'));
        self::assertNotSame(
            $baseFingerprint->digest('compatibility'),
            $notificationDisabledFingerprint->digest('compatibility'),
        );
    }

    public function test_host_local_spool_budget_is_excluded_while_shared_size_limits_diverge(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
        );
        $tiers = StorageTierRegistry::single(new ClusterAdmissionStorageBackend());
        $digest = static fn(S3ServerConfig $candidate): string => ClusterCapabilityFingerprint::fromRuntime(
            $candidate,
            $tiers,
            null,
            'public',
            str_repeat('d', 32),
            new ClusterAdmissionAuthenticationIdentity(),
        )->digest('compatibility');

        self::assertSame($digest($config), $digest($config->with([
            'postSpoolBudgetBytes' => $config->postSpoolBudgetBytes + 1,
        ])));
        self::assertNotSame($digest($config), $digest($config->with([
            'requestBodySizeLimit' => $config->requestBodySizeLimit + 1,
        ])));
        self::assertNotSame($digest($config), $digest($config->with([
            'maxEncryptedObjectSize' => $config->maxEncryptedObjectSize + 1,
        ])));
        self::assertNotSame($digest($config), $digest($config->with([
            'maxSelectObjectSize' => $config->maxSelectObjectSize + 1,
        ])));
        self::assertNotSame($digest($config), $digest($config->with([
            'perClientRateLimit' => $config->perClientRateLimit + 1,
        ])));
        self::assertNotSame($digest($config), $digest($config->with([
            'trustedProxyCidrs' => ['10.0.0.0/8'],
        ])));
        self::assertSame(
            $digest($config->with(['trustedProxyCidrs' => ['10.0.0.0/8', '192.0.2.0/24']])),
            $digest($config->with(['trustedProxyCidrs' => ['192.0.2.0/24', '10.0.0.0/8', '10.0.0.0/8']])),
        );
    }

    public function test_custom_storage_and_encryption_identities_are_non_empty_and_bounded(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
        );
        foreach (['', str_repeat('x', 1025)] as $identity) {
            try {
                ClusterCapabilityFingerprint::fromRuntime(
                    $config,
                    StorageTierRegistry::single(new ClusterAdmissionStorageBackend($identity)),
                    null,
                    'public',
                    str_repeat('d', 32),
                    new ClusterAdmissionAuthenticationIdentity(),
                );
                self::fail('An invalid storage capability identity was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('storage capability identity', $error->getMessage());
            }
        }

        foreach (['', str_repeat('x', 1025)] as $identity) {
            try {
                ClusterCapabilityFingerprint::fromRuntime(
                    $config,
                    StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
                    new ClusterAdmissionEncryptionService($identity),
                    'public',
                    str_repeat('d', 32),
                    new ClusterAdmissionAuthenticationIdentity(),
                );
                self::fail('An invalid encryption capability identity was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('encryption capability identity', $error->getMessage());
            }
        }
    }

    public function test_explicit_provider_generic_cluster_storage_capability_passes_structural_preflight(): void
    {
        self::assertSame([], StorageWriteFenceStartupGuard::assertReady(
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
            false,
            'postgres',
        ));
    }

    public function test_encryption_secrets_and_key_ids_do_not_enter_the_persisted_digest_contract(): void
    {
        $secretKey = str_repeat('S', 32);
        $secretKeyId = 'customer-sensitive-key-id';
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(keysJson: json_encode([
            $secretKeyId => base64_encode($secretKey),
        ], JSON_THROW_ON_ERROR)));
        $fingerprint = $this->fingerprint('cluster-a', 'node-a', encryption: $encryption);
        $serializedDigests = json_encode($fingerprint->digests(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($secretKey, $serializedDigests);
        self::assertStringNotContainsString(base64_encode($secretKey), $serializedDigests);
        self::assertStringNotContainsString($secretKeyId, $serializedDigests);
        self::assertNotSame(
            $fingerprint->digest('encryption'),
            $this->fingerprint(
                'cluster-a',
                'node-b',
                encryption: new EncryptionService(new ConfigMasterKeyProvider(base64_encode(str_repeat('T', 32)))),
            )->digest('encryption'),
        );
    }

    public function test_closed_admission_stops_lifecycle_claims(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $store->unavailable = true;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('listAllBuckets');
        $runner = new LifecycleRunner(
            new LifecycleExecutor($metadata, new InMemoryBackend()),
            0.001,
            clusterAdmission: $controller,
        );

        try {
            $runner->start();
            $callbackId = (new \ReflectionProperty($runner, 'callbackId'))->getValue($runner);
            self::assertIsString($callbackId);
            EventLoop::reference($callbackId);
            \Amp\delay(0.02);
            self::assertTrue($runner->isRunning());
        } finally {
            $runner->stop();
        }
    }

    public function test_data_plane_gate_rejects_without_invoking_the_handler_and_health_is_bounded(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        // New S3/admin claims must force a live comparison even while the
        // normal health revalidation interval remains fresh.
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 60.0);
        $controller->admitInitial();
        $store->unavailable = true;
        $calls = new ClusterAdmissionCallState();
        $handler = new class ($calls) implements RequestHandler {
            public function __construct(private readonly ClusterAdmissionCallState $calls) {}
            public function handleRequest(Request $request): Response
            {
                $this->calls->called = true;
                return new Response(200);
            }
        };

        try {
            (new ClusterAdmissionMiddleware($controller))->handleRequest($this->request(), $handler);
            self::fail('Expected closed cluster admission.');
        } catch (SlowDownException) {
        }
        self::assertFalse($calls->called);

        $health = (new HealthCheckHandler(clusterAdmission: $controller))->handleRequest($this->request());
        self::assertSame(503, $health->getStatus());
        $payload = json_decode((string) $health->getBody()->read(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['cluster'] ?? null);
        self::assertSame('degraded', $payload['status']);
        self::assertSame('unavailable', $payload['cluster']['admission']);
        self::assertSame('cluster-a', $payload['cluster']['cluster_id']);
        self::assertSame('node-a', $payload['cluster']['node_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $payload['cluster']['incarnation_id']);
        self::assertSame(ClusterCapabilityFingerprint::PROTOCOL, $payload['cluster']['protocol']);
        self::assertSame([ClusterCapabilityFingerprint::ROLES], $payload['cluster']['roles']);
        self::assertArrayNotHasKey('category', $payload['cluster']);
    }

    public function test_healthy_cluster_readiness_reports_matched_diagnostics(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();

        $response = (new HealthCheckHandler(clusterAdmission: $controller))->handleRequest($this->request());
        self::assertSame(200, $response->getStatus());
        $payload = json_decode((string) $response->getBody()->read(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertSame('matched', $payload['cluster']['admission'] ?? null);
        self::assertArrayNotHasKey('category', $payload['cluster']);
    }

    public function test_cluster_enabled_server_without_controller_fails_before_listener_assembly(): void
    {
        $server = new S3Server(new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            perClientRateLimit: 0,
        ));
        $server->addMiddleware(new ClusterAdmissionCustomAuthenticationMiddleware());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without an admitted cluster controller');
        $server->start();
    }

    public function test_cluster_enabled_server_rejects_custom_auth_without_cluster_identity(): void
    {
        $server = new S3Server(new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            perClientRateLimit: 0,
        ));
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $server->setClusterAdmissionController($controller);
        $server->addMiddleware(new ClusterAdmissionCustomAuthenticationMiddleware());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('authentication credential provider that exposes an explicit cluster identity');
        $server->start();
    }

    public function test_programmatic_cluster_controller_must_match_the_server_identity(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            perClientRateLimit: 0,
        );

        foreach ([
            $this->fingerprint('cluster-b', 'node-a'),
            $this->fingerprint('cluster-a', 'node-b'),
            $this->fingerprint('cluster-a', 'node-a', databaseIdentity: str_repeat('e', 32)),
        ] as $fingerprint) {
            $controller = new ClusterAdmissionController(new ClusterAdmissionMemoryStore(), $fingerprint, 0.0);
            try {
                (new S3Server($config))->setClusterAdmissionController($controller);
                self::fail('A cluster controller for another server identity was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('not bound to this server configuration', $error->getMessage());
            }
        }
    }

    public function test_programmatic_cluster_fencing_is_exact_config_bound_and_cannot_be_replaced(): void
    {
        $dsn = 'host=127.0.0.1 user=test db=cluster_fencing';
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            metadataDriver: 'postgres',
            metadataDsn: $dsn,
            perClientRateLimit: 0,
        );
        $metadata = PostgresMetadataStore::fromDsn($dsn);
        $server = new S3Server($config, $metadata);
        try {
            $server->setClusterWorkFencing(
                PostgresMetadataStore::fromDsn('host=127.0.0.1 user=test db=other_cluster'),
                ClusterWorkerIdentity::fromConfig($config),
            );
            self::fail('A fencing store for another database was accepted.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('exact configured PostgreSQL metadata store', $error->getMessage());
        }

        $otherIdentityConfig = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-b',
            clusterDatabaseId: str_repeat('d', 32),
            metadataDriver: 'postgres',
            metadataDsn: $dsn,
            perClientRateLimit: 0,
        );
        try {
            $server->setClusterWorkFencing($metadata, ClusterWorkerIdentity::fromConfig($otherIdentityConfig));
            self::fail('A worker identity from another node configuration was accepted.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('not bound to this server configuration', $error->getMessage());
        }

        $identity = ClusterWorkerIdentity::fromConfig($config);
        $server->setClusterWorkFencing($metadata, $identity);
        try {
            $server->setClusterWorkFencing($metadata, ClusterWorkerIdentity::fromConfig($config));
            self::fail('A second fencing configuration replaced live server state.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('already configured', $error->getMessage());
        }
        self::assertSame($metadata, (new \ReflectionProperty($server, 'clusterWorkFencing'))->getValue($server));
        self::assertSame($identity, (new \ReflectionProperty($server, 'clusterWorkerIdentity'))->getValue($server));
    }

    public function test_cluster_handler_storage_must_use_the_exact_fingerprinted_registry(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            perClientRateLimit: 0,
        );
        $backend = new ClusterAdmissionStorageBackend();
        $tiers = StorageTierRegistry::single($backend);
        $server = new S3Server($config, storage: $backend);
        $server->setStorageTierRegistry($tiers);
        $server->getHandlerRegistry()->registerStorage($backend, $tiers);
        $guard = new \ReflectionMethod($server, 'assertClusterHandlerStorageBindings');

        self::assertNull($guard->invoke($server, $tiers));

        $server->getHandlerRegistry()->registerStorage(
            new ClusterAdmissionStorageBackend('different-storage-v1'),
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend('different-storage-v1')),
        );
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must use the server configured storage tier registry');
        $guard->invoke($server, $tiers);
    }

    public function test_closed_gate_blocks_admin_reads_and_writes_before_the_handler(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $store->unavailable = true;
        $calls = new ClusterAdmissionCallState();
        $handler = new class ($calls) implements RequestHandler {
            public function __construct(private readonly ClusterAdmissionCallState $calls) {}
            public function handleRequest(Request $request): Response
            {
                $this->calls->called = true;
                return new Response(200);
            }
        };
        $gate = new ClusterAdmissionMiddleware($controller);

        foreach (['GET', 'PUT'] as $method) {
            try {
                $gate->handleRequest($this->request($method), $handler);
                self::fail('Closed cluster admission accepted an admin request.');
            } catch (SlowDownException) {
            }
            self::assertFalse($calls->called);
        }
    }

    public function test_management_stack_wires_cluster_gate_before_rate_limiting(): void
    {
        $store = new ClusterAdmissionMemoryStore();
        $controller = new ClusterAdmissionController($store, $this->fingerprint('cluster-a', 'node-a'), 0.0);
        $controller->admitInitial();
        $store->unavailable = true;
        $calls = new ClusterAdmissionCallState();
        $handler = new class ($calls) implements RequestHandler {
            public function __construct(private readonly ClusterAdmissionCallState $calls) {}
            public function handleRequest(Request $request): Response
            {
                $this->calls->called = true;
                return new Response(200);
            }
        };
        $server = new S3Server(new S3ServerConfig(storagePath: '/', perClientRateLimit: 0));
        $server->setClusterAdmissionController($controller);
        $managementHandler = new \ReflectionMethod($server, 'managementHandler');
        $gated = $managementHandler->invoke($server, $handler, false, false, true);
        self::assertInstanceOf(RequestHandler::class, $gated);

        foreach (['GET', 'PUT'] as $method) {
            self::assertSame(503, $gated->handleRequest($this->request($method))->getStatus());
            self::assertFalse($calls->called);
        }
    }

    private function fingerprint(
        string $clusterId,
        string $nodeId,
        bool $strictBucketNaming = true,
        ?EncryptionServiceInterface $encryption = null,
        string $databaseIdentity = 'dddddddddddddddddddddddddddddddd',
    ): ClusterCapabilityFingerprint {
        return ClusterCapabilityFingerprint::fromRuntime(
            new S3ServerConfig(
                storagePath: '/',
                clusterId: $clusterId,
                clusterNodeId: $nodeId,
                clusterDatabaseId: $databaseIdentity,
                strictBucketNaming: $strictBucketNaming,
            ),
            StorageTierRegistry::single(new ClusterAdmissionStorageBackend()),
            $encryption,
            'public',
            $databaseIdentity,
            new ClusterAdmissionAuthenticationIdentity(),
        );
    }

    /** @param non-empty-string $method */
    private function request(string $method = 'PUT'): Request
    {
        return new Request(new ClusterAdmissionClient(), $method, Http::new('http://localhost/bucket/key'));
    }
}

final class ClusterAdmissionMemoryStore implements ClusterAdmissionStore
{
    /** @var array<string, string>|null */
    private ?array $digests = null;
    private ?string $clusterId = null;
    public int $initializations = 0;
    public bool $unavailable = false;
    public bool $delayInitialization = false;
    public float $comparisonDelaySeconds = 0.0;
    public int $compareCalls = 0;

    public function clusterDatabaseSchema(): string
    {
        return 'public';
    }

    public function clusterDatabaseIdentity(): string
    {
        return str_repeat('d', 32);
    }

    public function initializeOrCompareClusterAdmission(ClusterCapabilityFingerprint $fingerprint): ClusterAdmissionResult
    {
        if ($this->delayInitialization) {
            \Amp\delay(0.001);
        }
        if ($this->digests === null) {
            $this->digests = $fingerprint->digests();
            $this->clusterId = $fingerprint->clusterId;
            ++$this->initializations;
        }

        return $this->compareClusterAdmission($fingerprint);
    }

    public function compareClusterAdmission(ClusterCapabilityFingerprint $fingerprint): ClusterAdmissionResult
    {
        ++$this->compareCalls;
        if ($this->comparisonDelaySeconds > 0.0) {
            \Amp\delay($this->comparisonDelaySeconds);
        }
        if ($this->unavailable) {
            throw new \RuntimeException('database unavailable secret=private');
        }
        if ($this->clusterId !== $fingerprint->clusterId) {
            return ClusterAdmissionResult::mismatch('cluster', 'database-id');
        }
        foreach ($fingerprint->digests() as $category => $digest) {
            if (! hash_equals((string) ($this->digests[$category] ?? ''), $digest)) {
                return ClusterAdmissionResult::mismatch($category, 'database-id');
            }
        }

        return ClusterAdmissionResult::matched('database-id');
    }
}

final readonly class ClusterAdmissionAuthenticationIdentity implements ClusterAuthenticationIdentityProvider
{
    public function clusterAuthenticationIdentity(): string
    {
        return 'database-credentials-v1:pgsql:' . str_repeat('c', 32);
    }
}

final class ClusterAdmissionCallState
{
    public bool $called = false;
}

final readonly class ClusterAdmissionCustomAuthenticationMiddleware implements AuthenticationMiddleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        return $requestHandler->handleRequest($request);
    }
}

final class ClusterAdmissionStorageBackend implements ClusterSafeStorageBackend, StorageBackend
{
    private InMemoryBackend $inner;

    public function __construct(private readonly string $clusterIdentity = 'qualified-test-storage-v1')
    {
        $this->inner = new InMemoryBackend();
    }

    public function clusterStorageIdentity(): string
    {
        return $this->clusterIdentity;
    }
    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        return $this->inner->putObject($bucket, $key, $body);
    }
    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        return $this->inner->getObjectByPath($storagePath, $offset, $length);
    }
    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        $this->inner->deleteObjectByPath($storagePath, $bucket);
    }
    public function createBucket(string $bucket): void
    {
        $this->inner->createBucket($bucket);
    }
    public function deleteBucket(string $bucket): void
    {
        $this->inner->deleteBucket($bucket);
    }
    public function bucketExists(string $bucket): bool
    {
        return $this->inner->bucketExists($bucket);
    }
    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        return $this->inner->putPart($bucket, $key, $uploadId, $partNumber, $data);
    }
    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        return $this->inner->assembleMultipartUpload($bucket, $key, $uploadId, $parts);
    }
    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->inner->abortMultipartUpload($bucket, $key, $uploadId);
    }
    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        return $this->inner->copyObject($srcPath, $dstBucket, $dstKey);
    }
}

final readonly class ClusterAdmissionEncryptionService implements EncryptionServiceInterface, ClusterEncryptionIdentity
{
    public function __construct(private string $identity) {}
    public function clusterEncryptionIdentity(): string
    {
        return $this->identity;
    }
    public function encryptSseS3(string $plaintext): array
    {
        throw new \LogicException('Not used.');
    }
    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
    {
        throw new \LogicException('Not used.');
    }
    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        throw new \LogicException('Not used.');
    }
    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
    {
        throw new \LogicException('Not used.');
    }
}

final class ClusterAdmissionClient implements Client
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
