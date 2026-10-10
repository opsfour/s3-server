<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Routing\HandlerRegistry;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Runtime\S3ServerRuntime;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EfsCandidateConfigurationSealTest extends TestCase
{
    public function test_registry_seal_rejects_unregistered_handler_replacement_and_storage_after_freeze_rollback(): void
    {
        $registry = new HandlerRegistry();
        $handler = self::handler();
        $registry->register(S3Operation::PutObject, $handler);
        $registry->sealForQualifiedEfsCandidate();

        $token = $registry->freeze();
        $registry->rollbackFreeze($token);
        self::assertSame($handler, $registry->get(S3Operation::PutObject));

        foreach ([
            static fn() => $registry->register(S3Operation::PutObject, self::handler()),
            static fn() => $registry->registerStorage(new InMemoryBackend(), StorageTierRegistry::single(new InMemoryBackend())),
            static fn() => $registry->sealForQualifiedEfsCandidate(),
        ] as $mutate) {
            try {
                $mutate();
                self::fail('An EFS-sealed handler registry was changed.');
            } catch (\LogicException) {
                self::assertSame($handler, $registry->get(S3Operation::PutObject));
            }
        }
        self::assertSame([], $registry->storageRegistrations());
    }

    public function test_server_seal_rejects_all_public_storage_and_request_pipeline_mutators(): void
    {
        $server = self::server();
        $metadata = new SqliteMetadataStore(':memory:');
        $notifications = new NotificationDispatcher($metadata);
        $collector = (new ReflectionClass(LocalObjectWriteIntentCollector::class))->newInstanceWithoutConstructor();
        $writer = (new ReflectionClass(LocalFencedObjectWriter::class))->newInstanceWithoutConstructor();
        $leaseFactory = $this->createMock(LocalObjectWriteLeaseGuardFactory::class);
        $workerPool = $this->createMock(WorkerPool::class);
        $evidence = new class implements EfsHostEvidence {
            public function assertFresh(): void {}
        };

        $server->sealConfigurationForQualifiedEfsCandidate();
        foreach ([
            static fn() => $server->setStorageTierRegistry(StorageTierRegistry::single(new InMemoryBackend())),
            static fn() => $server->setNotificationDispatcher($notifications),
            static fn() => $server->setLocalObjectWriteIntentCollector($collector),
            static fn() => $server->setLocalObjectWriteTestWriter($writer, $leaseFactory),
            static fn() => $server->setEncryptionService(null),
            static fn() => $server->setAdminCredentialApiHandler(self::handler()),
            static fn() => $server->setAdminQuotaApiHandler(self::handler()),
            static fn() => $server->setEfsApiAdmissionForTests($evidence),
            static fn() => $server->addMiddleware(self::middleware()),
            static fn() => $server->addWorkerPool($workerPool),
            static fn() => $server->sealConfigurationForQualifiedEfsCandidate(),
        ] as $mutate) {
            try {
                $mutate();
                self::fail('An EFS-sealed server was changed.');
            } catch (\LogicException) {
                self::assertTrue($server->isConfigurationSealedForQualifiedEfsCandidate());
            }
        }
    }

    public function test_runtime_resource_attachment_rejects_after_server_seal(): void
    {
        $server = self::server();
        $runtime = new S3ServerRuntime($server, new NotificationDispatcher(new SqliteMetadataStore(':memory:')));
        $writer = (new ReflectionClass(LocalFencedObjectWriter::class))->newInstanceWithoutConstructor();
        $renewer = (new ReflectionClass(DedicatedAmpObjectWriteLeaseRenewer::class))->newInstanceWithoutConstructor();
        $server->sealConfigurationForQualifiedEfsCandidate();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EFS candidate seal');
        $runtime->attachLocalObjectWriteTestResources($writer, $renewer);
    }

    public function test_server_seal_survives_failed_start_without_opening_a_listener(): void
    {
        $server = self::server();
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setStorageTierRegistry(StorageTierRegistry::single(new InMemoryBackend()));
        $server->sealConfigurationForQualifiedEfsCandidate();

        try {
            $server->start();
            self::fail('An incomplete EFS candidate was admitted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not yet admitted', $error->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EFS candidate seal');
        $server->getHandlerRegistry()->register(S3Operation::PutObject, self::handler());
    }

    public function test_sealed_candidate_refuses_start_before_opening_a_listener(): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);

        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->sealConfigurationForQualifiedEfsCandidate();

        try {
            try {
                $server->start();
                self::fail('An incomplete EFS candidate started serving.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('not yet admitted', $error->getMessage());
            }
            self::assertTrue($server->isConfigurationSealedForQualifiedEfsCandidate());
            $listener = stream_socket_server('tcp://127.0.0.1:' . $port);
            self::assertIsResource($listener);
            fclose($listener);
        } finally {
            $server->stop();
        }
    }

    private static function server(): S3Server
    {
        return new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
    }

    private static function handler(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        };
    }

    private static function middleware(): Middleware
    {
        return new class implements Middleware {
            public function handleRequest(Request $request, RequestHandler $requestHandler): Response
            {
                return $requestHandler->handleRequest($request);
            }
        };
    }
}
