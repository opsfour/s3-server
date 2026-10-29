<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Auth\ClusterAuthenticationIdentityProvider;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\ClusterAdmissionController;
use OpsFour\S3Server\Runtime\ClusterCapabilityFingerprint;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ClusterSafeStorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PostgresClusterAdmissionTest extends TestCase
{
    private PostgresConnectionPool $firstPool;
    private PostgresConnectionPool $secondPool;
    private PostgresConnectionPool $servingPool;
    private PostgresMetadataStore $first;
    private PostgresMetadataStore $second;
    private PostgresMetadataStore $serving;
    private string $servingRole;

    protected function setUp(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN to an isolated s3audit_ PostgreSQL database.');
        }
        $this->firstPool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $database = $this->firstPool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertIsString($database['database_name'] ?? null);
        self::assertStringStartsWith('s3audit_', $database['database_name']);
        $this->secondPool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $this->first = new PostgresMetadataStore($this->firstPool);
        $this->second = new PostgresMetadataStore($this->secondPool);
        $this->first->initialize();
        $this->firstPool->execute('DELETE FROM s3_cluster_admission');
    }

    protected function tearDown(): void
    {
        if (isset($this->servingPool)) {
            $this->servingPool->close();
        }
        if (isset($this->firstPool)) {
            $this->firstPool->execute('DELETE FROM s3_cluster_admission');
            if (isset($this->servingRole)) {
                $role = $this->identifier($this->servingRole);
                $this->firstPool->execute("DROP OWNED BY {$role}");
                $this->firstPool->execute("DROP ROLE {$role}");
            }
            $this->firstPool->close();
        }
        if (isset($this->secondPool)) {
            $this->secondPool->close();
        }
    }

    public function test_migration_pin_concurrent_first_join_live_schema_drift_and_mismatch(): void
    {
        $identity = $this->first->clusterDatabaseIdentity();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $identity);
        self::assertSame($identity, $this->second->clusterDatabaseIdentity());

        $first = new ClusterAdmissionController($this->first, $this->fingerprint($identity, 'node-a'), 0.0);
        $second = new ClusterAdmissionController($this->second, $this->fingerprint($identity, 'node-b'), 0.0);
        \Amp\Future\await([
            \Amp\async($first->admitInitial(...)),
            \Amp\async($second->admitInitial(...)),
        ]);
        self::assertTrue($first->isAdmitted());
        self::assertTrue($second->isAdmitted());
        self::assertSame(1, (int) ($this->firstPool->query('SELECT COUNT(*) AS count FROM s3_cluster_admission')->fetchRow()['count'] ?? 0));

        $mismatch = new ClusterAdmissionController(
            $this->second,
            $this->fingerprint($identity, 'node-c', strict: false),
            0.0,
        );
        $mismatch->admitInitial();
        self::assertFalse($mismatch->isAdmitted());
        self::assertSame('compatibility', $mismatch->category());

        $live = new ClusterAdmissionController(
            $this->first,
            $this->fingerprint($identity, 'node-a'),
            revalidationIntervalSeconds: 0.0,
            fingerprintFactory: function () use ($identity): ClusterCapabilityFingerprint {
                $this->first->assertCurrentSchema();

                return $this->fingerprint($identity, 'node-a');
            },
        );
        $live->admitInitial();
        $this->firstPool->execute('INSERT INTO s3_schema_version (version, description) VALUES (48, $1)', ['drift-test']);
        try {
            self::assertFalse($live->revalidate(true));
            self::assertFalse($live->isAdmitted());
        } finally {
            $this->firstPool->execute('DELETE FROM s3_schema_version WHERE version = 48');
        }
        self::assertTrue($live->revalidate(true));
    }

    public function test_select_insert_only_serving_role_can_bootstrap_but_cannot_mutate_contract(): void
    {
        $identity = $this->first->clusterDatabaseIdentity();
        $this->createServingRole();

        $controller = new ClusterAdmissionController(
            $this->serving,
            $this->fingerprint($identity, 'restricted-node'),
            0.0,
        );
        $controller->admitInitial();

        self::assertTrue($controller->isAdmitted());
        self::assertSame(
            1,
            (int) ($this->firstPool->query('SELECT COUNT(*) AS count FROM s3_cluster_admission')->fetchRow()['count'] ?? 0),
        );
        $this->assertServingStatementDenied(
            "UPDATE s3_cluster_admission SET cluster_id = 'forbidden' WHERE singleton_id = 1",
        );
        $this->assertServingStatementDenied('DELETE FROM s3_cluster_admission WHERE singleton_id = 1');
        $this->assertServingStatementDenied(
            "UPDATE s3_cluster_database_identity SET database_identity = '00000000000000000000000000000000' WHERE singleton_id = 1",
        );
    }

    public function test_runtime_factory_attaches_admission_and_registered_http_routes_fail_closed(): void
    {
        $dsn = trim((string) getenv('S3_TEST_POSTGRES_METADATA_DSN'));
        $metadata = PostgresMetadataStore::fromDsn($dsn);
        $identity = $metadata->clusterDatabaseIdentity();
        $storage = new FunctionalClusterStorage();
        $postgres = PostgresConfig::fromString($dsn);
        $credentialProvider = DatabaseCredentialProvider::fromDsn(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $postgres->getHost(),
                $postgres->getPort(),
                $postgres->getDatabase(),
            ),
            $postgres->getUser(),
            $postgres->getPassword(),
            bootstrap: true,
        );
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);
        $runtime = (new S3ServerRuntimeFactory())->create(
            new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: '/',
                metadataDriver: 'postgres',
                metadataDsn: $dsn,
                clusterId: 'cluster-functional-factory',
                clusterNodeId: 'factory-node',
                clusterDatabaseId: $identity,
                notificationWorkerEnabled: false,
            ),
            $metadata,
            $storage,
            $credentialProvider,
            new NullLogger(),
            new MetricsCollector(),
        );
        $admin = new FunctionalClusterAdminHandler();
        $runtime->server->setAdminCredentialApiHandler($admin);
        $runtime->server->setAdminQuotaApiHandler($admin);
        $originalDigest = null;

        try {
            $property = new \ReflectionProperty($runtime->server, 'clusterAdmission');
            $controller = $property->getValue($runtime->server);
            self::assertInstanceOf(ClusterAdmissionController::class, $controller);
            self::assertTrue($controller->isAdmitted());
            self::assertSame('matched', $controller->diagnostics()['admission']);
            self::assertSame('cluster-functional-factory', $controller->diagnostics()['cluster_id']);

            $runtime->server->start();
            $row = $this->firstPool->query(
                'SELECT compatibility_digest FROM s3_cluster_admission WHERE singleton_id = 1',
            )->fetchRow();
            $originalDigest = $row['compatibility_digest'] ?? null;
            self::assertIsString($originalDigest);
            $this->firstPool->execute(
                "UPDATE s3_cluster_admission SET compatibility_digest = 'forced-mismatch' WHERE singleton_id = 1",
            );

            $client = (new HttpClientBuilder())->retry(0)->build();
            $endpoint = "http://127.0.0.1:{$port}";
            foreach ([
                ['POST', '/.admin/credentials'],
                ['GET', '/.admin/quotas'],
                ['PUT', '/bucket/object'],
            ] as [$method, $path]) {
                $response = $client->request(new ClientRequest($endpoint . $path, $method));
                self::assertSame(503, $response->getStatus(), "{$method} {$path}");
            }
            self::assertSame(0, $admin->calls);
        } finally {
            if (is_string($originalDigest)) {
                $this->firstPool->execute(
                    'UPDATE s3_cluster_admission SET compatibility_digest = $1 WHERE singleton_id = 1',
                    [$originalDigest],
                );
            }
            $runtime->stop();
        }
    }

    private function createServingRole(): void
    {
        $this->servingRole = 's3_cluster_serving_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $role = $this->identifier($this->servingRole);
        $schema = $this->identifier($this->first->clusterDatabaseSchema());
        $passwordLiteral = str_replace("'", "''", $password);

        $this->firstPool->execute("CREATE ROLE {$role} LOGIN PASSWORD '{$passwordLiteral}'");
        $this->firstPool->execute("ALTER ROLE {$role} SET search_path TO {$schema}");
        $this->firstPool->execute("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        $this->firstPool->execute("GRANT SELECT ON {$schema}.s3_cluster_database_identity TO {$role}");
        $this->firstPool->execute("GRANT SELECT, INSERT ON {$schema}.s3_cluster_admission TO {$role}");

        $config = PostgresConfig::fromString(trim((string) getenv('S3_TEST_POSTGRES_METADATA_DSN')))
            ->withUser($this->servingRole)
            ->withPassword($password);
        $this->servingPool = new PostgresConnectionPool($config);
        $this->serving = new PostgresMetadataStore($this->servingPool);
        self::assertSame($this->first->clusterDatabaseIdentity(), $this->serving->clusterDatabaseIdentity());
    }

    private function assertServingStatementDenied(string $sql): void
    {
        try {
            $this->servingPool->execute($sql);
            self::fail('The SELECT/INSERT-only serving role unexpectedly mutated the cluster contract.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('permission denied', $error->getMessage());
        }
    }

    private function identifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function fingerprint(string $identity, string $node, bool $strict = true): ClusterCapabilityFingerprint
    {
        return ClusterCapabilityFingerprint::fromRuntime(
            new S3ServerConfig(
                storagePath: '/',
                metadataDriver: 'postgres',
                clusterId: 'cluster-functional',
                clusterNodeId: $node,
                clusterDatabaseId: $identity,
                strictBucketNaming: $strict,
            ),
            StorageTierRegistry::single(new FunctionalClusterStorage()),
            null,
            $this->first->clusterDatabaseSchema(),
            $identity,
            new FunctionalClusterAuthentication(),
        );
    }
}

final readonly class FunctionalClusterAuthentication implements ClusterAuthenticationIdentityProvider
{
    public function clusterAuthenticationIdentity(): string
    {
        return 'database-credentials-v1:pgsql:' . str_repeat('c', 32);
    }
}

final class FunctionalClusterAdminHandler implements RequestHandler
{
    public int $calls = 0;

    public function handleRequest(Request $request): Response
    {
        ++$this->calls;

        return new Response(200, ['content-type' => 'application/json'], '{}');
    }
}

final class FunctionalClusterStorage implements ClusterSafeStorageBackend, StorageBackend
{
    private InMemoryBackend $inner;

    public function __construct()
    {
        $this->inner = new InMemoryBackend();
    }

    public function clusterStorageIdentity(): string
    {
        return 'functional-qualified-storage-v1';
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
