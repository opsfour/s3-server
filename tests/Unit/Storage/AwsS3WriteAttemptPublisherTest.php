<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\AwsS3CreateOnlyWriteOutcome;
use OpsFour\S3Server\Storage\AwsS3CreateOnlyWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptArmException;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptOutcome;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3WriteAttemptPublisherTest extends TestCase
{
    /** @var list<string> */
    private array $databasePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->databasePaths as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
        parent::tearDown();
    }

    public function test_reservation_and_unique_dispatch_are_committed_before_the_only_provider_request(): void
    {
        $store = $this->store();
        $planned = self::planned();
        $requests = [];
        $writer = $this->writer([new Response(200, ['ETag' => '"published"'])], $requests, $store, static function () use ($store, $planned): void {
            $armed = $store->getObjectWriteIntent($planned->attemptId);
            self::assertNotNull($armed);
            self::assertSame('active', $armed->state);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', (string) $armed->dispatchId);
            self::assertNotSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $armed->dispatchId);
        });

        $outcome = (new AwsS3WriteAttemptPublisher(self::profile(), $store, $writer))
            ->publish($planned, 'logical/key', 'body');

        self::assertSame(AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS, $outcome->state);
        self::assertSame('"published"', $outcome->etag);
        self::assertSame($planned->attemptId, $outcome->attemptId);
        self::assertSame($store->getObjectWriteIntent($planned->attemptId)?->dispatchId, $outcome->dispatchId);
        self::assertCount(1, $requests);
        self::assertNotNull($store->getObjectWriteIntent($planned->attemptId), 'The armed row belongs to the later caller/reconciler.');
    }

    public function test_every_provider_outcome_retains_the_armed_intent_and_returns_only_closed_state(): void
    {
        foreach ([
            [new Response(412, [], self::errorXml('PreconditionFailed')), AwsS3CreateOnlyWriteOutcome::PRECONDITION_CONFLICT],
            [new Response(403, [], self::errorXml('AccessDenied')), AwsS3CreateOnlyWriteOutcome::CONFIRMED_REJECTION],
            [new Response(503, [], self::errorXml('ServiceUnavailable')), AwsS3CreateOnlyWriteOutcome::AMBIGUOUS],
            [new Response(200, ['ETag' => 'unquoted']), AwsS3CreateOnlyWriteOutcome::AMBIGUOUS],
        ] as [$response, $expected]) {
            $store = $this->store();
            $planned = self::planned(bin2hex(random_bytes(16)));
            $requests = [];
            $publisher = new AwsS3WriteAttemptPublisher(
                self::profile(),
                $store,
                $this->writer([$response], $requests, $store),
            );

            $outcome = $publisher->publish($planned, 'logical/key', 'body');

            self::assertSame($expected, $outcome->state);
            self::assertNull($outcome->etag);
            self::assertSame(['attemptId', 'dispatchId', 'state', 'etag'], array_keys(get_object_vars($outcome)));
            self::assertNotNull($store->getObjectWriteIntent($planned->attemptId));
            self::assertCount(1, $requests);
        }
    }

    public function test_path_identity_state_and_prearmed_mismatches_fail_before_metadata_or_aws(): void
    {
        $base = self::planned();
        $cases = [
            [$base, 'another/key'],
            [self::copy($base, storagePath: '.opsfour/other/path'), 'logical/key'],
            [self::copy($base, bucketGeneration: str_repeat('b', 32)), 'logical/key'],
            [self::copy($base, storageTier: 'ARCHIVE'), 'logical/key'],
            [self::copy($base, attemptId: str_repeat('c', 32)), 'logical/key'],
            [self::copy($base, state: 'reconciling'), 'logical/key'],
            [self::copy($base, leaseUntil: 1.0, dispatchId: str_repeat('d', 32)), 'logical/key'],
        ];

        foreach ($cases as [$planned, $logicalKey]) {
            $store = $this->store();
            $requests = [];
            $publisher = new AwsS3WriteAttemptPublisher(
                self::profile(),
                $store,
                $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests, $store),
            );

            try {
                $publisher->publish($planned, $logicalKey, 'body');
                self::fail('A substituted or pre-armed plan must fail.');
            } catch (\InvalidArgumentException) {
                self::assertNull($store->getObjectWriteIntent($planned->attemptId));
                self::assertSame([], $requests);
            }
        }
    }

    public function test_capacity_and_arm_cas_failures_never_contact_aws_or_remove_the_reservation(): void
    {
        foreach ([true, false] as $failReserve) {
            $inner = $this->store();
            $store = new PublisherObjectWriteIntentStore($inner, $failReserve);
            $requests = [];
            $publisher = new AwsS3WriteAttemptPublisher(
                self::profile(),
                $store,
                $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests, $store),
            );
            $planned = self::planned(bin2hex(random_bytes(16)));

            try {
                $publisher->publish($planned, 'logical/key', 'body');
                self::fail('Metadata admission/arm failure must stop before AWS.');
            } catch (SlowDownException|AwsS3WriteAttemptArmException $error) {
                self::assertSame([], $requests);
                self::assertSame(! $failReserve, $inner->getObjectWriteIntent($planned->attemptId) !== null);
                if ($error instanceof AwsS3WriteAttemptArmException) {
                    self::assertSame('AWS write attempt could not be durably armed.', $error->getMessage());
                }
            }
        }
    }

    public function test_capacity_rejection_precedes_seekable_body_evidence_reads(): void
    {
        $inner = $this->store();
        $store = new PublisherObjectWriteIntentStore($inner, true);
        $requests = [];
        $publisher = new AwsS3WriteAttemptPublisher(
            self::profile(),
            $store,
            $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests, $store),
        );
        $body = fopen('php://temp', 'w+b');
        self::assertIsResource($body);
        fwrite($body, 'prefix-body');
        self::assertSame(0, fseek($body, 3));

        try {
            $publisher->publish(self::planned(), 'logical/key', $body);
            self::fail('Capacity exhaustion must reject before reading staged body evidence.');
        } catch (SlowDownException) {
            self::assertSame(3, ftell($body));
            self::assertSame([], $requests);
            self::assertNull($inner->getObjectWriteIntent(self::planned()->attemptId));
        } finally {
            fclose($body);
        }
    }

    public function test_definite_psr_evidence_failures_retire_the_exact_undispatched_reservation(): void
    {
        foreach (['zero_progress', 'rewind'] as $failure) {
            $store = $this->store();
            $requests = [];
            $publisher = new AwsS3WriteAttemptPublisher(
                self::profile(),
                $store,
                $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests, $store),
            );
            $planned = self::planned(bin2hex(random_bytes(16)));
            $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
            $body->method('isSeekable')->willReturn(true);
            $body->method('tell')->willReturn(3);
            if ($failure === 'zero_progress') {
                $body->method('eof')->willReturn(false);
                $body->expects(self::once())->method('read')->willReturn('');
            } else {
                $body->method('eof')->willReturnOnConsecutiveCalls(false, true);
                $body->expects(self::once())->method('read')->willReturn('body');
                $body->expects(self::once())->method('seek')->with(3)->willThrowException(new \RuntimeException('rewind failed'));
            }

            try {
                $publisher->publish($planned, 'logical/key', $body);
                self::fail('Definite evidence failure must prevent dispatch.');
            } catch (\Throwable $error) {
                self::assertStringContainsString($failure === 'zero_progress' ? 'made no progress' : 'rewind failed', $error->getMessage());
            }
            self::assertNull($store->getObjectWriteIntent($planned->attemptId));
            self::assertSame([], $requests);
        }
    }

    public function test_unexpected_metadata_failures_are_sanitized_before_provider_io(): void
    {
        foreach (['reserve', 'arm'] as $throwAt) {
            $inner = $this->store();
            $store = new PublisherObjectWriteIntentStore($inner, false, $throwAt);
            $requests = [];
            $publisher = new AwsS3WriteAttemptPublisher(
                self::profile(),
                $store,
                $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests, $store),
            );
            $planned = self::planned(bin2hex(random_bytes(16)));

            try {
                $publisher->publish($planned, 'logical/key', 'body');
                self::fail('Raw metadata failure escaped the publisher.');
            } catch (AwsS3WriteAttemptArmException $error) {
                self::assertSame('AWS write attempt could not be durably armed.', $error->getMessage());
                self::assertSame([], $requests);
                self::assertSame($throwAt === 'arm', $inner->getObjectWriteIntent($planned->attemptId) !== null);
            }
        }
    }

    public function test_invalid_body_is_rejected_before_reservation_or_provider_io(): void
    {
        $store = $this->store();
        $requests = [];
        $planned = self::planned();
        $publisher = new AwsS3WriteAttemptPublisher(
            self::profile(),
            $store,
            $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests, $store),
        );

        try {
            $publisher->publish($planned, 'logical/key', self::unsupportedBody());
            self::fail('Unsupported body was reserved.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('AWS write-attempt body must be a string, resource, or PSR-7 stream.', $error->getMessage());
            self::assertNull($store->getObjectWriteIntent($planned->attemptId));
            self::assertSame([], $requests);
        }
    }

    public function test_publisher_rejects_writer_bound_to_different_profile_or_intent_store(): void
    {
        $store = $this->store();
        $otherStore = $this->store();
        $wrongProfiles = [
            new AwsS3ProviderProfile('other-backing', 'us-east-1', '123456789012', 'objects', '.opsfour/write-attempts'),
            new AwsS3ProviderProfile('backing', 'eu-west-1', '123456789012', 'objects', '.opsfour/write-attempts'),
            new AwsS3ProviderProfile('backing', 'us-east-1', '999999999999', 'objects', '.opsfour/write-attempts'),
            new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'other-objects', '.opsfour/write-attempts'),
            new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'objects', '.opsfour/other-attempts'),
            new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'objects', '.opsfour/write-attempts', 6, 15, 20),
            new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'objects', '.opsfour/write-attempts', 5, 16, 20),
            new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'objects', '.opsfour/write-attempts', 5, 15, 21),
        ];

        foreach (array_merge(array_map(static fn(AwsS3ProviderProfile $profile): array => [
            $profile, $store, 300.0,
        ], $wrongProfiles), [
            [self::profile(), $otherStore, 300.0],
            [self::profile(), $store, 301.0],
        ]) as [$writerProfile, $writerStore, $writerLeaseSeconds]) {
            $requests = [];
            $writer = $this->writer(
                [new Response(200, ['ETag' => '"unexpected"'])],
                $requests,
                $writerStore,
                profile: $writerProfile,
                leaseSeconds: $writerLeaseSeconds,
            );

            try {
                new AwsS3WriteAttemptPublisher(self::profile(), $store, $writer);
                self::fail('Publisher accepted a divergent writer binding.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(
                    'AWS write-attempt publisher requires one exact profile and intent store binding.',
                    $error->getMessage(),
                );
                self::assertSame([], $requests);
            }
        }
    }

    public function test_outcome_rejects_legacy_dispatch_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AwsS3WriteAttemptOutcome::fromProvider(
            self::planned()->attemptId,
            ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH,
            AwsS3CreateOnlyWriteOutcome::ambiguous(),
        );
    }

    public function test_writer_rejects_lease_without_provider_timeout_margin(): void
    {
        $requests = [];
        try {
            $this->writer(
                [new Response(200, ['ETag' => '"unexpected"'])],
                $requests,
                $this->store(),
                leaseSeconds: 24.0,
            );
            self::fail('Writer accepted a lease shorter than one bounded provider request.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'AWS write-attempt lease must exceed the provider request timeout by at least 5 seconds.',
                $error->getMessage(),
            );
            self::assertSame([], $requests);
        }
    }

    private function store(): SqliteMetadataStore
    {
        $path = tempnam(sys_get_temp_dir(), 's3-aws-publisher-');
        self::assertIsString($path);
        $this->databasePaths[] = $path;
        $store = new SqliteMetadataStore($path);
        $store->initialize();

        return $store;
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<RequestInterface> $requests
     */
    private function writer(
        array $responses,
        array &$requests,
        ObjectWriteIntentStore $intents,
        ?\Closure $beforeRequest = null,
        ?AwsS3ProviderProfile $profile = null,
        float $leaseSeconds = 300.0,
    ): AwsS3CreateOnlyWriter {
        $requests = [];
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.mock.invalid',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'retries' => 3,
            'http_handler' => static function (RequestInterface $request, array $options) use ($handler, &$requests, $beforeRequest) {
                $beforeRequest?->__invoke();
                $requests[] = $request;

                return $handler($request, $options);
            },
        ]);

        return new AwsS3CreateOnlyWriter(
            $client,
            $profile ?? self::profile(),
            $intents,
            leaseSeconds: $leaseSeconds,
        );
    }

    private static function planned(string $attemptId = '0123456789abcdef0123456789abcdef'): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: $attemptId,
            bucket: 'logical-bucket',
            bucketGeneration: str_repeat('a', 32),
            ownerId: 'owner',
            storageTier: 'AWS',
            storagePath: AwsS3WriteAttemptPath::forProfileObject(
                self::profile(),
                'logical-bucket',
                'logical/key',
                str_repeat('a', 32),
                'AWS',
                $attemptId,
            ),
            purpose: 'put_object',
        );
    }

    private static function copy(
        ObjectWriteIntent $intent,
        ?string $attemptId = null,
        ?string $bucketGeneration = null,
        ?string $storageTier = null,
        ?string $storagePath = null,
        ?string $state = null,
        ?float $leaseUntil = null,
        ?string $dispatchId = null,
    ): ObjectWriteIntent {
        return new ObjectWriteIntent(
            attemptId: $attemptId ?? $intent->attemptId,
            bucket: $intent->bucket,
            bucketGeneration: $bucketGeneration ?? $intent->bucketGeneration,
            ownerId: $intent->ownerId,
            storageTier: $storageTier ?? $intent->storageTier,
            storagePath: $storagePath ?? $intent->storagePath,
            purpose: $intent->purpose,
            state: $state ?? $intent->state,
            leaseUntil: $leaseUntil ?? $intent->leaseUntil,
            claimGeneration: $intent->claimGeneration,
            dispatchId: $dispatchId ?? $intent->dispatchId,
        );
    }

    private static function profile(): AwsS3ProviderProfile
    {
        return new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'objects', '.opsfour/write-attempts');
    }

    private static function unsupportedBody(): mixed
    {
        return ['unsupported'];
    }

    private static function errorXml(string $code): string
    {
        return '<?xml version="1.0"?><Error><Code>' . $code . '</Code><Message>secret</Message></Error>';
    }
}

/** @internal Fault seam for metadata admission and arm ordering. */
final readonly class PublisherObjectWriteIntentStore implements ObjectWriteIntentStore
{
    public function __construct(
        private ObjectWriteIntentStore $inner,
        private bool $failReserve,
        private ?string $throwAt = null,
    ) {}

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        if ($this->throwAt === 'reserve') {
            throw new \RuntimeException('secret metadata diagnostic');
        }
        if ($this->failReserve) {
            throw new SlowDownException('Physical write recovery capacity is exhausted.');
        }

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
        if ($this->throwAt === 'arm') {
            throw new \RuntimeException('secret metadata diagnostic');
        }

        return null;
    }
    public function renewArmedObjectWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewArmedObjectWriteIntent($attemptId, $dispatchId, $expectedLeaseUntil, $leaseSeconds);
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
