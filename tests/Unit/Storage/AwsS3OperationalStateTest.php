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
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\S3Server;
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
    public function test_healthy_fence_complete_profile_remains_distinct_from_production_qualification(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);

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

        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state);
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

        $snapshot = $state->snapshot(true);

        self::assertFalse($snapshot['provider_admitted']);
        self::assertFalse($snapshot['mutation_ready']);
        self::assertSame('credentials', $snapshot['reason']);
        self::assertSame(7, $snapshot['open']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials');
        $state->assertMutationReady();
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

        self::assertSame('collector', $state->snapshot(true)['reason']);
        self::assertFalse($state->mutationReady());

        $state->markIntegrityConflict();
        self::assertSame('integrity', $state->snapshot(true)['reason']);
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
        self::assertSame('provider', $state->snapshot(true)['reason']);
        self::assertFalse($state->mutationReady());

        [$capacityBinding, $capacityValidator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $summary->values['open'] = 1_000_000;
        $capacityState = new AwsS3OperationalState(
            $capacityBinding,
            $capacityValidator,
            self::inventory(),
            $summary,
            'AWS',
            0.0,
        );
        self::assertSame('capacity', $capacityState->snapshot(true)['reason']);
        self::assertFalse($capacityState->mutationReady());
        self::assertTrue($capacityState->recoveryReady());
    }

    public function test_successful_summary_refresh_does_not_erase_collector_failure(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            new AwsS3OperationalSummaryFixture(),
            'AWS',
            0.0,
        );
        $state->markCollectorFailure();

        $snapshot = $state->snapshot(true);

        self::assertSame('collector', $snapshot['reason']);
        self::assertSame('failed', $snapshot['reconciliation_state']);
        self::assertFalse($snapshot['mutation_ready']);
        self::assertTrue($snapshot['recovery_ready']);
    }

    public function test_health_handler_accepts_healthy_aws_and_skips_closed_generic_backend_probe(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ]);
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            new AwsS3OperationalSummaryFixture(),
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state);
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
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            new AwsS3OperationalSummaryFixture(),
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state);
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

    public function test_closed_mutation_admission_stops_reservation_before_body_or_provider_dispatch(): void
    {
        $requests = [];
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
        ], $requests);
        $summary = new AwsS3OperationalSummaryFixture();
        $summary->values['oldest_age_seconds'] = 900.0;
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 'AWS', 30.0);
        self::assertFalse($state->snapshot(true)['mutation_ready']);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state);
        $base = sys_get_temp_dir() . '/s3-aws-admission-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        self::assertTrue(mkdir($base . '/scratch', 0700));
        $metadata = new SqliteMetadataStore($base . '/metadata.sqlite');
        $metadata->initialize();
        $writer = $backend->createForegroundObjectWriter($metadata, $base . '/scratch', 'AWS');
        self::assertIsArray($requests);
        $providerRequests = count($requests);
        try {
            try {
                $writer->reserve(new BucketInfo('bucket', 'owner', 'us-east-1', generation: 'generation'), 'key', 'owner');
                self::fail('Closed mutation admission accepted a reservation.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('collector', $error->getMessage());
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

    public function test_precondition_conflict_records_bounded_outcome_and_closes_integrity(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            ...self::healthyResponses(),
            new Response(412, [], '<Error><Code>PreconditionFailed</Code></Error>'),
        ]);
        $state = new AwsS3OperationalState(
            $binding,
            $validator,
            self::inventory(),
            new AwsS3OperationalSummaryFixture(),
            'AWS',
            30.0,
        );
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $backend->attachOperationalState($state);
        $base = sys_get_temp_dir() . '/s3-aws-conflict-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        self::assertTrue(mkdir($base . '/scratch', 0700));
        $metadata = new SqliteMetadataStore($base . '/metadata.sqlite');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $metrics = new MetricsCollector();
        $writer = $backend->createForegroundObjectWriter($metadata, $base . '/scratch', 'AWS', metrics: $metrics);
        $stage = null;
        try {
            $reservation = $writer->reserve($bucket, 'key', 'owner');
            $stage = $writer->stage($reservation, new ReadableBuffer('body'));
            try {
                $writer->dispatch($reservation, 'key', $stage);
                self::fail('AWS precondition conflict was accepted.');
            } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            }
            self::assertSame('integrity', $state->snapshot()['reason']);
            self::assertSame('integrity_conflict', $state->snapshot()['reconciliation_state']);
            self::assertStringContainsString(
                's3_server_aws_publication_outcomes_total{status="precondition_conflict"} 1',
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

final class AwsS3OperationalSummaryFixture implements AwsS3OperationalSummaryReader
{
    /**
     * @var array{
     *   open: int, ambiguous: int, seal_reserved: int, sealed: int, multipart: int, part: int,
     *   cleanup: int, failures: int, oldest_age_seconds: float,
     *   max_owner_open: int, max_owner_sealed: int, max_owner_part: int
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
        'max_owner_part' => 0,
    ];

    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        if ($storageTier !== 'AWS') {
            throw new \RuntimeException('Unexpected AWS tier.');
        }
        return $this->values;
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
