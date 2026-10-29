<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client as ServerClient;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Handler\Bucket\CreateBucketHandler;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3OperationalStateTest extends TestCase
{
    public function test_cluster_identity_remains_closed_without_authoritative_production_qualification(): void
    {
        [$binding] = self::binding(self::healthyResponses());
        $unqualified = new AwsS3StorageBackend($binding->admission, $binding);

        try {
            $unqualified->clusterStorageIdentity();
            self::fail('Unqualified AWS profile must not participate in cluster admission.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not cluster-qualified', $error->getMessage());
        }

        self::assertFalse($unqualified->awsS3ProviderAdmission()->productionQualified());
    }

    public function test_create_bucket_handler_publishes_only_after_committed_attachment_without_provider_mutation(): void
    {
        $requests = [];
        [$binding, $validator] = self::binding(self::healthyResponses(), $requests);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $providerRequestCount = count($requests ?? []);
        $databasePath = sys_get_temp_dir() . '/s3-aws-create-bucket-' . bin2hex(random_bytes(8)) . '.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $handler = new CreateBucketHandler(
            $metadata,
            $backend,
            new S3ServerConfig(storagePath: sys_get_temp_dir()),
        );
        $request = static function (): Request {
            $request = new Request(
                new AwsS3OperationalStateTestClient(),
                'PUT',
                Http::new('http://127.0.0.1/logical-bucket'),
            );
            $request->setAttribute('s3.bucket', 'logical-bucket');
            $request->setAttribute('ownerId', 'owner');

            return $request;
        };

        try {
            try {
                $handler->handleRequest($request());
                self::fail('A provisional AWS attachment must not publish a logical bucket.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('write fence is complete', $error->getMessage());
            }
            self::assertNull($metadata->getBucketOwner('logical-bucket'));

            $backend->commitOperationalStateAttachment($state, 'AWS', $summary);
            self::assertSame(200, $handler->handleRequest($request())->getStatus());
            self::assertSame('owner', $metadata->getBucketOwner('logical-bucket'));
            self::assertSame(200, $handler->handleRequest($request())->getStatus());
            self::assertSame('owner', $metadata->getBucketOwner('logical-bucket'));
            self::assertCount($providerRequestCount, $requests ?? []);
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-shm');
            @unlink($databasePath . '-wal');
        }
    }

    public function test_healthy_fence_complete_profile_remains_distinct_from_production_qualification(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);

        $state->assertStartupReady();
        $snapshot = $state->snapshot();

        self::assertTrue($snapshot['configuration_valid']);
        self::assertTrue($snapshot['provider_admitted']);
        self::assertTrue($snapshot['fence_complete']);
        self::assertTrue($snapshot['owner_check_passed']);
        self::assertSame('disabled', $snapshot['versioning_state']);
        self::assertTrue($snapshot['writer_coverage_complete']);
        self::assertSame('clear', $snapshot['drift_state']);
        self::assertSame('healthy', $snapshot['reconciliation_state']);
        self::assertTrue($snapshot['mutation_ready']);
        self::assertFalse($snapshot['production_qualified']);
        self::assertSame('healthy', $snapshot['reason']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $snapshot['fingerprint']);
        self::assertStringNotContainsString('profile-private', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('123456789012', json_encode($snapshot, JSON_THROW_ON_ERROR));

        self::assertTrue($backend->awsS3ProviderAdmission()->fenceComplete());
        self::assertFalse($backend->awsS3ProviderAdmission()->productionQualified());
    }

    public function test_expired_credentials_close_readiness_and_mutation_without_losing_durable_summary(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            new Response(403, [], '<Error><Code>ExpiredToken</Code><Message>private credential text</Message></Error>'),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $summary->values['open'] = 7;
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 0.0);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);

        $state->refresh(true);
        $snapshot = $state->snapshot();

        self::assertFalse($snapshot['provider_admitted']);
        self::assertFalse($snapshot['mutation_ready']);
        self::assertSame('credentials', $snapshot['reason']);
        self::assertSame(7, $snapshot['open']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials');
        $state->assertMutationReady();
    }

    public function test_failed_startup_probe_remains_retryable_until_provider_admission_succeeds(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            new Response(403, [], '<Error><Code>ExpiredToken</Code></Error>'),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $summary,
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);

        try {
            $state->assertStartupReady();
            self::fail('A failed startup probe was admitted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not fence-complete', $error->getMessage());
        }

        $state->assertStartupReady();
        self::assertTrue($state->snapshot()['provider_admitted']);
    }

    public function test_stale_recovery_backlog_and_integrity_conflict_use_fixed_closed_reasons(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $summary->values['oldest_age_seconds'] = 900.0;
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);

        $state->refresh(true);
        self::assertSame('collector', $state->snapshot()['reason']);
        self::assertFalse($state->mutationReady());
        self::assertTrue($state->recoveryReady());

        $state->markIntegrityConflict();
        self::assertSame('integrity', $state->snapshot()['reason']);
        self::assertFalse($state->mutationReady());
        self::assertTrue($state->recoveryReady());
        $state->assertRecoveryReady();
    }

    public function test_provider_drift_and_global_capacity_close_with_distinct_reasons(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status></VersioningConfiguration>'),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 0.0);
        $state->refresh(true);
        self::assertSame('provider', $state->snapshot()['reason']);
        self::assertFalse($state->mutationReady());

        [$capacityBinding, $capacityValidator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary->values['global_open'] = 1_000_000;
        $capacityState = new AwsS3OperationalState(
            $capacityBinding,
            $capacityValidator,
            self::inventory(),
            $summary,
            'AWS',
            0.0,
        );
        $capacityState->refresh(true);
        self::assertSame('capacity', $capacityState->snapshot()['reason']);
        self::assertFalse($capacityState->mutationReady());
        self::assertTrue($capacityState->recoveryReady());
    }

    public function test_successful_summary_refresh_does_not_erase_collector_failure(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $summary,
            'AWS',
            0.0,
        );
        $state->markCollectorFailure();
        $state->markCollectorFailure('storage_garbage');

        $state->refresh(true);
        $snapshot = $state->snapshot();

        self::assertSame('collector', $snapshot['reason']);
        self::assertSame('failed', $snapshot['reconciliation_state']);
        self::assertFalse($snapshot['mutation_ready']);
        self::assertTrue($snapshot['recovery_ready']);
        $state->markCollectorHealthy();
        self::assertSame('collector', $state->snapshot()['reason']);
        $state->markCollectorHealthy('storage_garbage');
        self::assertSame('healthy', $state->snapshot()['reason']);
    }

    public function test_durable_ambiguous_or_failed_rows_close_publication_after_restart(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $summary->values['ambiguous'] = 1;
        $firstProcess = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);
        $firstProcess->assertStartupReady();
        self::assertSame('integrity', $firstProcess->snapshot()['reason']);
        self::assertFalse($firstProcess->snapshot()['mutation_ready']);

        [$restartedBinding, $restartedValidator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $restarted = new AwsS3OperationalState(
            $restartedBinding,
            $restartedValidator,
            self::inventory(),
            $summary,
            'AWS',
            30.0,
        );
        $restarted->assertStartupReady();
        self::assertSame('integrity', $restarted->snapshot()['reason']);
        self::assertFalse($restarted->snapshot()['mutation_ready']);

        $summary->values['ambiguous'] = 0;
        $summary->values['failures'] = 1;
        $restarted->refresh(true);
        self::assertSame('collector', $restarted->snapshot()['reason']);
        self::assertFalse($restarted->snapshot()['mutation_ready']);
        self::assertTrue($restarted->snapshot()['recovery_ready']);
    }

    public function test_retained_multipart_backlog_without_actionable_age_or_failure_stays_ready(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $summary->values['multipart'] = 1;
        $summary->values['global_multipart'] = 1;
        $summary->values['max_owner_multipart'] = 1;
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 0.0);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);

        $state->refresh(true);

        self::assertSame(1, $state->snapshot()['multipart']);
        self::assertSame(0.0, $state->snapshot()['oldest_age_seconds']);
        self::assertSame('healthy', $state->snapshot()['reason']);
        self::assertTrue($state->mutationReady());
    }

    public function test_operational_state_rejects_equal_profile_values_from_another_binding_or_tier(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $summary,
            'AWS',
        );
        [$otherBinding] = self::binding(self::healthyResponses());

        self::assertFalse($state->isBoundTo($otherBinding, 'AWS', $summary));
        self::assertFalse($state->isBoundTo($binding, 'ARCHIVE', $summary));
        self::assertFalse($state->isBoundTo($binding, 'AWS', new AwsS3OperationalSummaryFixture()));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('another validated client');
        $state->assertExecutionBinding($otherBinding, 'AWS', $summary);
    }

    public function test_cached_readiness_status_cannot_stay_200_after_mutation_closes(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $summary,
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);
        $handler = new HealthCheckHandler(
            readinessCheckIntervalSeconds: 30,
            awsOperationalState: $state,
        );
        self::assertSame(200, $handler->handleRequest(self::healthRequest())->getStatus());

        $state->markCollectorFailure();
        $response = $handler->handleRequest(self::healthRequest());
        self::assertSame(503, $response->getStatus());
        self::assertStringContainsString('"mutation_ready":false', (string) $response->getBody()->read());
    }

    public function test_health_handler_accepts_healthy_aws_and_skips_closed_generic_backend_probe(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $summary,
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);
        $handler = new HealthCheckHandler(
            storageTiers: StorageTierRegistry::single($backend, 'AWS'),
            readinessCheckIntervalSeconds: 0,
            awsOperationalState: $state,
        );

        $response = $handler->handleRequest(self::healthRequest());
        $json = $response->getBody()->read();
        self::assertIsString($json);
        $body = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatus());
        self::assertSame('ok', $body['status']);
        self::assertSame(true, $body['aws']['owner_check_passed']);
        self::assertSame('disabled', $body['aws']['versioning_state']);
        self::assertSame('clear', $body['aws']['drift_state']);
        self::assertSame('healthy', $body['aws']['reconciliation_state']);
        self::assertFalse($body['aws']['production_qualified']);
    }

    public function test_health_handler_reports_provider_drift_as_503(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status></VersioningConfiguration>'),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $summary,
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $summary);
        $backend->commitOperationalStateAttachment($state, 'AWS', $summary);
        $response = (new HealthCheckHandler(
            storageTiers: StorageTierRegistry::single($backend, 'AWS'),
            readinessCheckIntervalSeconds: 0,
            awsOperationalState: $state,
        ))->handleRequest(self::healthRequest());
        $json = $response->getBody()->read();
        self::assertIsString($json);
        $body = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatus());
        self::assertSame('degraded', $body['status']);
        self::assertSame('enabled', $body['aws']['versioning_state']);
        self::assertSame('detected', $body['aws']['drift_state']);
        self::assertFalse($body['aws']['mutation_ready']);
        self::assertFalse($body['aws']['recovery_ready']);
    }

    public function test_authorized_metrics_scrape_bridges_cached_shared_snapshot_without_provider_io(): void
    {
        $requests = [];
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ], $requests);
        $summary = new AwsS3OperationalSummaryFixture();
        $summary->values['global_multipart'] = 12;
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);
        $state->assertStartupReady();
        $providerRequests = count($requests ?? []);

        $response = (new MetricsHandler(
            new MetricsCollector(),
            bearerToken: 'token',
            awsOperationalState: $state,
        ))->handleRequest(new Request(
            new AwsS3OperationalStateTestClient(),
            'GET',
            Http::new('http://127.0.0.1/.metrics'),
            ['authorization' => 'Bearer token'],
        ));
        $rendered = $response->getBody()->read();

        self::assertIsString($rendered);
        self::assertSame(200, $response->getStatus());
        self::assertSame($providerRequests, count($requests ?? []));
        self::assertStringContainsString(
            's3_server_aws_recovery_global_capacity_items{kind="multipart"} 12',
            $rendered,
        );
    }

    public function test_closed_mutation_admission_stops_reservation_before_body_or_provider_dispatch(): void
    {
        $base = sys_get_temp_dir() . '/s3-aws-admission-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        self::assertTrue(mkdir($base . '/scratch', 0700));
        $metadata = new SqliteMetadataStore($base . '/metadata.sqlite');
        $metadata->initialize();
        $operationalMetadata = new AwsS3OperationalObjectIntentStoreFixture($metadata);
        $operationalMetadata->values['global_open'] = 1_000_000;
        $requests = [];
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ], $requests);
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $operationalMetadata, 'AWS', 30.0);
        $state->refresh(true);
        self::assertFalse($state->snapshot()['mutation_ready']);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $operationalMetadata);
        $backend->commitOperationalStateAttachment($state, 'AWS', $operationalMetadata);
        $writer = $backend->createForegroundObjectWriter($operationalMetadata, $base . '/scratch', 'AWS');
        self::assertIsArray($requests);
        $providerRequests = count($requests);
        try {
            try {
                $writer->reserve(new BucketInfo('bucket', 'owner', 'us-east-1', generation: 'generation'), 'key', 'owner');
                self::fail('Closed mutation admission accepted a reservation.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('capacity', $error->getMessage());
            }
            self::assertSame($providerRequests, count($requests));
            self::assertSame(['object' => 0, 'object_seal' => 0, 'part' => 0], $metadata->getWriteIntentCapacityCounts());
        } finally {
            $writer->shutdown();
            @unlink($base . '/metadata.sqlite');
            @unlink($base . '/metadata.sqlite-shm');
            @unlink($base . '/metadata.sqlite-wal');
            @rmdir($base . '/scratch');
            @rmdir($base);
        }
    }

    public function test_ambiguous_provider_outcome_records_bounded_outcome_and_closes_until_recovery(): void
    {
        $base = sys_get_temp_dir() . '/s3-aws-conflict-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        self::assertTrue(mkdir($base . '/scratch', 0700));
        $metadata = new SqliteMetadataStore($base . '/metadata.sqlite');
        $metadata->initialize();
        $operationalMetadata = new AwsS3OperationalObjectIntentStoreFixture($metadata);
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
            new Response(503, [], '<Error><Code>ServiceUnavailable</Code></Error>'),
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            $operationalMetadata,
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state, 'AWS', $operationalMetadata);
        $backend->commitOperationalStateAttachment($state, 'AWS', $operationalMetadata);
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $metrics = new MetricsCollector();
        $writer = $backend->createForegroundObjectWriter($operationalMetadata, $base . '/scratch', 'AWS', metrics: $metrics);
        $stage = null;
        try {
            $reservation = $writer->reserve($bucket, 'key', 'owner');
            $stage = $writer->stage($reservation, new ReadableBuffer('body'));
            try {
                $writer->dispatch($reservation, 'key', $stage);
                self::fail('An ambiguous AWS provider outcome was accepted.');
            } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            }
            self::assertSame('integrity', $state->snapshot()['reason']);
            self::assertSame('integrity_conflict', $state->snapshot()['reconciliation_state']);
            $intent = (new \ReflectionProperty($reservation, 'intent'))->getValue($reservation);
            self::assertInstanceOf(ObjectWriteIntent::class, $intent);
            $current = $metadata->getObjectWriteIntent($intent->attemptId);
            self::assertNotNull($current);
            self::assertNotNull($current->dispatchId);
            $otherAttempt = str_repeat('f', 32);
            $otherDispatch = str_repeat('e', 32);
            $state->markObjectRecoveryPending($otherAttempt, $otherDispatch);
            $state->refresh(true);
            self::assertSame('integrity', $state->snapshot()['reason'], 'A summary refresh must not erase exact in-process pending tokens.');
            $claim = $operationalMetadata->claimDueObjectWriteIntents('AWS', 1, 300.0)[0] ?? null;
            self::assertNotNull($claim);
            self::assertSame($current->attemptId, $claim->attemptId);
            self::assertTrue($operationalMetadata->sealObjectWriteIntent($claim->attemptId, $claim->claimGeneration));
            $state->refresh(true);
            self::assertSame('integrity', $state->snapshot()['reason'], 'A remote terminal seal may clear only its exact local token.');
            $state->resolveObjectRecoveryPending($otherAttempt, $otherDispatch);
            self::assertSame('healthy', $state->snapshot()['reason']);
            self::assertStringContainsString(
                's3_server_aws_publication_outcomes_total{status="ambiguous"} 1',
                $metrics->renderPrometheus(),
            );
        } finally {
            if ($stage !== null) {
                $writer->release($stage);
            }
            $writer->shutdown();
            @unlink($base . '/metadata.sqlite');
            @unlink($base . '/metadata.sqlite-shm');
            @unlink($base . '/metadata.sqlite-wal');
            @rmdir($base . '/scratch');
            @rmdir($base);
        }
    }

    /**
     * @param list<Response> $responses
     * @param list<RequestInterface>|null $requests
     * @return array{AwsS3ProviderValidatedBinding, AwsS3ProviderValidator}
     */
    private static function binding(array $responses, ?array &$requests = null): array
    {
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        /** @param array<string, mixed> $options */
        $httpHandler = static function (RequestInterface $request, array $options) use ($handler, &$requests) {
            if ($requests !== null) {
                $requests[] = $request;
            }
            return $handler($request, $options);
        };
        $validator = new AwsS3ProviderValidator($httpHandler);
        $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
            'bucket' => 'profile-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/attempts',
        ], $validator);
        self::assertTrue($binding->admission->providerAdmitted);

        return [$binding, $validator];
    }

    /** @return list<Response> */
    private static function healthyResponses(): array
    {
        return [
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ];
    }

    private static function inventory(): StorageMutatorInventory
    {
        return StorageMutatorInventory::forEnabledRuntime([
            ...HandlerRegistrar::storageMutatorRegistrations(),
            ...S3Server::storageMutatorRegistrations(),
            ...AwsS3ObjectWriteIntentReconciler::storageMutatorRegistrations(),
        ]);
    }

    private static function healthRequest(): Request
    {
        return new Request(new AwsS3OperationalStateTestClient(), 'GET', Http::new('http://127.0.0.1/.health'));
    }
}

class AwsS3OperationalSummaryFixture implements AwsS3OperationalSummaryReader
{
    /**
     * @var array{
     *   open: int, ambiguous: int, seal_reserved: int, sealed: int, multipart: int, part: int,
     *   cleanup: int, failures: int, oldest_age_seconds: float,
     *   max_owner_open: int, max_owner_sealed: int, max_owner_multipart: int, max_owner_part: int,
     *   global_open: int, global_seal_reserved: int, global_multipart: int, global_part: int, global_cleanup: int
     * }
     */
    public array $values = [
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

    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        if ($storageTier !== 'AWS') {
            throw new \RuntimeException('Unexpected AWS tier.');
        }
        return $this->values;
    }
}

/** Exact operational metadata identity with a real SQLite object-intent implementation. */
final class AwsS3OperationalObjectIntentStoreFixture extends AwsS3OperationalSummaryFixture implements ObjectWriteIntentStore
{
    public function __construct(private readonly ObjectWriteIntentStore $inner) {}

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        return $this->inner->reserveObjectWriteIntent($intent, $leaseSeconds);
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        return $this->inner->getObjectWriteIntent($attemptId);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewObjectWriteIntent($attemptId, $expectedLeaseUntil, $leaseSeconds);
    }

    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds, ?int $expectedSize = null, ?string $expectedSha256 = null): ?ObjectWriteIntent
    {
        return $this->inner->armObjectWriteIntent($attemptId, $expectedLeaseUntil, $dispatchId, $leaseSeconds, $expectedSize, $expectedSha256);
    }

    public function renewArmedObjectWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewArmedObjectWriteIntent($attemptId, $dispatchId, $expectedLeaseUntil, $leaseSeconds);
    }

    public function expireArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->inner->expireArmedObjectWriteIntent($attemptId, $dispatchId);
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        return $this->inner->retireUndispatchedObjectWriteIntent($attemptId);
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->inner->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        return $this->inner->consumeObjectWriteIntent($attemptId);
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->inner->consumeArmedObjectWriteIntent($attemptId, $dispatchId);
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        return $this->inner->abandonObjectWriteIntent($attemptId);
    }

    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        return $this->inner->claimDueObjectWriteIntents($storageTier, $limit, $leaseSeconds);
    }

    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        return $this->inner->retireExpiredUndispatchedObjectWriteIntents($storageTier, $limit);
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewObjectWriteClaim($attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds);
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        return $this->inner->sealObjectWriteIntent($attemptId, $claimGeneration);
    }
}

final class AwsS3OperationalStateTestClient implements ServerClient
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
    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return null;
    }
    public function onClose(\Closure $onClose): void {}
    public function isClosed(): bool
    {
        return false;
    }
    public function close(): void {}
}
