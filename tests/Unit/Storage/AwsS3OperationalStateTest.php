<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
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
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 0.0);

        $state->assertStartupReady();
        $snapshot = $state->snapshot();

        self::assertTrue($snapshot['configuration_valid']);
        self::assertTrue($snapshot['provider_admitted']);
        self::assertTrue($snapshot['fence_complete']);
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
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 0.0);

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
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 0.0);

        self::assertSame('collector', $state->snapshot(true)['reason']);
        self::assertFalse($state->mutationReady());

        $state->markIntegrityConflict();
        self::assertSame('integrity', $state->snapshot(true)['reason']);
        self::assertFalse($state->mutationReady());
    }

    public function test_provider_drift_and_global_capacity_close_with_distinct_reasons(): void
    {
        [$binding, $validator] = self::binding([
            ...self::healthyResponses(),
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status></VersioningConfiguration>'),
        ]);
        $summary = new AwsS3OperationalSummaryFixture();
        $state = new AwsS3OperationalState($binding, $validator, self::inventory(), $summary, 0.0);
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
            0.0,
        );
        self::assertSame('capacity', $capacityState->snapshot(true)['reason']);
        self::assertFalse($capacityState->mutationReady());
    }

    /**
     * @param list<Response> $responses
     * @return array{AwsS3ProviderValidatedBinding, AwsS3ProviderValidator}
     */
    private static function binding(array $responses): array
    {
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        /** @param array<string, mixed> $options */
        $httpHandler = static fn(RequestInterface $request, array $options) => $handler($request, $options);
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

    public function getAwsS3OperationalSummary(): array
    {
        return $this->values;
    }
}
