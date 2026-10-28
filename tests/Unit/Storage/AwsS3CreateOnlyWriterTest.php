<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Storage\AwsS3CreateOnlyWriteOutcome;
use OpsFour\S3Server\Storage\AwsS3CreateOnlyWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptArmException;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3CreateOnlyWriterTest extends TestCase
{
    private const int PART_SIZE = 5 * 1024 * 1024;
    private const string ATTEMPT_ID = '0123456789abcdef0123456789abcdef';

    public function test_small_put_is_owner_bound_marked_create_only_and_returns_verified_etag(): void
    {
        $requests = [];
        $writer = $this->writer([new Response(200, ['ETag' => '"new"'])], $requests);

        $outcome = $writer->publish(self::intent(), 'logical/key', 'hello');

        self::assertSame(AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS, $outcome->state);
        self::assertSame('"new"', $outcome->etag);
        self::assertCount(1, $requests);
        self::assertSame('PUT', $requests[0]->getMethod());
        self::assertSame('/backing/' . self::intent()->storagePath, $requests[0]->getUri()->getPath());
        self::assertSame('*', $requests[0]->getHeaderLine('If-None-Match'));
        self::assertSame('123456789012', $requests[0]->getHeaderLine('x-amz-expected-bucket-owner'));
        self::assertSame('v2', $requests[0]->getHeaderLine('x-amz-meta-opsfour-write-attempt'));
        self::assertSame(self::ATTEMPT_ID, $requests[0]->getHeaderLine('x-amz-meta-opsfour-attempt-id'));
        self::assertAttemptEvidenceHeaders($requests[0]);
        self::assertFalse($requests[0]->hasHeader('x-amz-acl'));
    }

    public function test_multipart_completion_is_owner_bound_marked_and_create_only(): void
    {
        $requests = [];
        $writer = $this->writer(self::multipartResponses(new Response(200, [], self::completedXml())), $requests, self::PART_SIZE);

        $outcome = $writer->publish(self::intent(), 'logical/key', str_repeat('x', self::PART_SIZE));

        self::assertSame(AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS, $outcome->state);
        self::assertSame('"complete"', $outcome->etag);
        self::assertCount(3, $requests);
        self::assertSame(['POST', 'PUT', 'POST'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        self::assertSame(['', '', '*'], array_map(static fn(RequestInterface $request): string => $request->getHeaderLine('If-None-Match'), $requests));
        foreach ($requests as $request) {
            self::assertSame('123456789012', $request->getHeaderLine('x-amz-expected-bucket-owner'));
            self::assertNotSame('DELETE', $request->getMethod());
        }
        self::assertSame('v2', $requests[0]->getHeaderLine('x-amz-meta-opsfour-write-attempt'));
        self::assertSame(self::ATTEMPT_ID, $requests[0]->getHeaderLine('x-amz-meta-opsfour-attempt-id'));
        self::assertAttemptEvidenceHeaders($requests[0]);
        self::assertFalse($requests[0]->hasHeader('x-amz-acl'));
    }

    public function test_exact_put_and_complete_collisions_are_closed_without_retry_or_cleanup(): void
    {
        foreach ([
            [[self::error(412, 'PreconditionFailed')], 16 * 1024 * 1024, 'x', 1],
            [self::multipartResponses(self::error(409, 'ConditionalRequestConflict')), self::PART_SIZE, str_repeat('x', self::PART_SIZE), 3],
        ] as [$responses, $threshold, $body, $count]) {
            $requests = [];
            $outcome = $this->writer($responses, $requests, $threshold)
                ->publish(self::intent(), 'logical/key', $body);

            self::assertSame(AwsS3CreateOnlyWriteOutcome::PRECONDITION_CONFLICT, $outcome->state);
            self::assertNull($outcome->etag);
            self::assertCount($count, $requests);
            foreach ($requests as $request) {
                self::assertNotSame('DELETE', $request->getMethod());
            }
        }
    }

    public function test_connection_error_with_partial_conflict_response_remains_ambiguous(): void
    {
        $requests = [];
        $error = new AwsException('secret partial conflict', new Command('PutObject'), [
            'code' => 'PreconditionFailed',
            'response' => self::error(412, 'PreconditionFailed'),
            'connection_error' => true,
        ]);

        $outcome = $this->writer([$error], $requests)
            ->publish(self::intent(), 'logical/key', 'hello');

        self::assertSame(AwsS3CreateOnlyWriteOutcome::AMBIGUOUS, $outcome->state);
        self::assertCount(1, $requests);
    }

    public function test_explicit_terminal_provider_refusal_is_a_closed_rejection(): void
    {
        $requests = [];
        $outcome = $this->writer([self::error(403, 'AccessDenied')], $requests)
            ->publish(self::intent(), 'logical/key', 'hello');

        self::assertSame(AwsS3CreateOnlyWriteOutcome::CONFIRMED_REJECTION, $outcome->state);
        self::assertNull($outcome->etag);
        self::assertCount(1, $requests);
    }

    public function test_only_allowlisted_status_400_codes_are_closed_rejections(): void
    {
        foreach ([
            ['InvalidRequest', AwsS3CreateOnlyWriteOutcome::CONFIRMED_REJECTION],
            ['RequestTimeout', AwsS3CreateOnlyWriteOutcome::AMBIGUOUS],
        ] as [$code, $expected]) {
            $requests = [];
            $outcome = $this->writer([self::error(400, $code)], $requests)
                ->publish(self::intent(), 'logical/key', 'hello');

            self::assertSame($expected, $outcome->state);
            self::assertCount(1, $requests);
        }
    }

    public function test_transport_retryable_and_malformed_put_results_are_ambiguous_without_diagnostics(): void
    {
        foreach ([
            [new ConnectException('secret timeout detail', new Request('PUT', 'https://s3.mock.invalid/private'))],
            [self::error(503, 'ServiceUnavailable')],
            [new Response(200, ['ETag' => 'unquoted'])],
        ] as $responses) {
            $requests = [];
            $outcome = $this->writer($responses, $requests)
                ->publish(self::intent(), 'logical/key', 'hello');

            self::assertSame(AwsS3CreateOnlyWriteOutcome::AMBIGUOUS, $outcome->state);
            self::assertNull($outcome->etag);
            self::assertSame(['state', 'etag'], array_keys(get_object_vars($outcome)));
            self::assertCount(1, $requests);
        }
    }

    public function test_hostile_or_unverified_multipart_success_is_ambiguous_without_abort_or_delete(): void
    {
        $path = self::intent()->storagePath;
        $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
        $bodies = [
            '<?xml version="1.0"?><Error><Code>InternalError</Code><Bucket>backing</Bucket><Key>' . $path . '</Key><ETag>"complete"</ETag></Error>',
            '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="' . $namespace . '"><Bucket>backing</Bucket><Key>other</Key><ETag>"complete"</ETag></CompleteMultipartUploadResult>',
            '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="' . $namespace . '"><Bucket>backing</Bucket><Key>' . $path . '</Key><ETag>invalid</ETag></CompleteMultipartUploadResult>',
            '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="' . $namespace . '"><Bucket>backing</Bucket><Key>' . $path . '</Key><ETag>"complete"</ETag><Error><Code>InternalError</Code></Error></CompleteMultipartUploadResult>',
            '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="urn:not-aws"><Bucket>backing</Bucket><Key>' . $path . '</Key><ETag>"complete"</ETag></CompleteMultipartUploadResult>',
            '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="' . $namespace . '"><Bucket>backing</Bucket><Key>' . $path . '</Key><ETag>"complete"</ETag><ETag>"other"</ETag></CompleteMultipartUploadResult>',
            '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="' . $namespace . '">' . str_repeat(' ', 70 * 1024)
                . '<Bucket>backing</Bucket><Key>' . $path . '</Key><ETag>"complete"</ETag></CompleteMultipartUploadResult>',
        ];
        foreach ($bodies as $body) {
            $requests = [];
            $outcome = $this->writer(self::multipartResponses(new Response(200, [], $body)), $requests, self::PART_SIZE)
                ->publish(self::intent(), 'logical/key', str_repeat('x', self::PART_SIZE));

            self::assertSame(AwsS3CreateOnlyWriteOutcome::AMBIGUOUS, $outcome->state);
            self::assertCount(3, $requests);
            self::assertNotContains('DELETE', array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        }
    }

    public function test_multipart_failures_before_and_during_final_dispatch_are_ambiguous_without_retry_or_cleanup(): void
    {
        $completeTimeout = new ConnectException(
            'secret completion timeout',
            new Request('POST', 'https://s3.mock.invalid/private'),
        );
        $successful = self::multipartResponses(new Response(200, [], self::completedXml()));
        $partFailure = $successful;
        $partFailure[1] = self::error(503, 'ServiceUnavailable');

        foreach ([
            [[self::error(503, 'ServiceUnavailable')], 1],
            [array_values($partFailure), 2],
            [self::multipartResponses($completeTimeout), 3],
        ] as [$responses, $count]) {
            $requests = [];
            $outcome = $this->writer($responses, $requests, self::PART_SIZE)
                ->publish(self::intent(), 'logical/key', str_repeat('x', self::PART_SIZE));

            self::assertSame(AwsS3CreateOnlyWriteOutcome::AMBIGUOUS, $outcome->state);
            self::assertNull($outcome->etag);
            self::assertCount($count, $requests);
            self::assertNotContains('DELETE', array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        }
    }

    public function test_unarmed_or_substituted_intent_is_rejected_before_provider_io(): void
    {
        foreach ([[self::intent(false), 'logical/key'], [self::intent(), 'another/key']] as [$intent, $logicalKey]) {
            $requests = [];
            $writer = $this->writer([new Response(200, ['ETag' => '"unexpected"'])], $requests);

            try {
                $writer->publish($intent, $logicalKey, 'body');
                self::fail('The wire primitive accepted an unarmed or substituted intent.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $requests);
            }
        }
    }

    public function test_missing_or_mismatched_authoritative_row_is_rejected_before_provider_io(): void
    {
        $armed = self::intent();
        $mismatched = self::intent(dispatchId: str_repeat('e', 32));
        foreach ([null, $mismatched] as $authoritative) {
            $requests = [];
            $store = new WriterObjectWriteIntentStore($authoritative);
            $writer = $this->writer(
                [new Response(200, ['ETag' => '"unexpected"'])],
                $requests,
                intents: $store,
            );

            try {
                $writer->publish($armed, 'logical/key', 'body');
                self::fail('Provider I/O accepted a fabricated armed intent.');
            } catch (AwsS3WriteAttemptArmException) {
                self::assertSame([], $requests);
            }
        }
    }

    public function test_metadata_failure_before_first_request_is_sanitized_as_arm_failure(): void
    {
        $requests = [];
        $writer = $this->writer(
            [new Response(200, ['ETag' => '"unexpected"'])],
            $requests,
            intents: new WriterObjectWriteIntentStore(self::intent(), true),
        );

        try {
            $writer->publish(self::intent(), 'logical/key', 'body');
            self::fail('Raw metadata failure escaped before provider I/O.');
        } catch (AwsS3WriteAttemptArmException $error) {
            self::assertSame('AWS write attempt could not be durably armed.', $error->getMessage());
            self::assertSame([], $requests);
        }
    }

    public function test_multipart_stops_before_the_next_request_when_authority_is_lost_after_any_step(): void
    {
        foreach ([1 => 1, 2 => 3, 3 => 3] as $forgetAfterRequest => $expectedRequests) {
            $requests = [];
            $seen = 0;
            $store = new WriterObjectWriteIntentStore(self::intent());
            $writer = $this->writer(
                self::multipartResponses(new Response(200, [], self::completedXml()), 2),
                $requests,
                self::PART_SIZE,
                intents: $store,
                afterRequest: static function () use ($store, &$seen, $forgetAfterRequest): void {
                    if (++$seen === $forgetAfterRequest) {
                        $store->forget();
                    }
                },
            );

            $outcome = $writer->publish(self::intent(), 'logical/key', str_repeat('x', self::PART_SIZE * 2));

            self::assertSame(AwsS3CreateOnlyWriteOutcome::AMBIGUOUS, $outcome->state);
            self::assertCount($expectedRequests, $requests);
            self::assertNotContains('DELETE', array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        }
    }

    public function test_multipart_without_raw_response_observation_remains_ambiguous(): void
    {
        $requests = [];
        $writer = $this->writer(
            self::multipartResponses(new Response(200, [], self::completedXml())),
            $requests,
            self::PART_SIZE,
            false,
        );

        $outcome = $writer->publish(self::intent(), 'logical/key', str_repeat('x', self::PART_SIZE));

        self::assertSame(AwsS3CreateOnlyWriteOutcome::AMBIGUOUS, $outcome->state);
        self::assertCount(3, $requests);
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<RequestInterface> $requests
     */
    private function writer(
        array $responses,
        array &$requests,
        int $threshold = 16 * 1024 * 1024,
        bool $observeStats = true,
        ?ObjectWriteIntentStore $intents = null,
        ?\Closure $afterRequest = null,
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
            'http_handler' => static function (RequestInterface $request, array $options) use ($handler, &$requests, $observeStats, $afterRequest) {
                $requests[] = $request;
                $afterRequest?->__invoke();
                if (! $observeStats) {
                    unset($options['on_stats']);
                }

                return $handler($request, $options);
            },
        ]);

        return new AwsS3CreateOnlyWriter(
            $client,
            self::profile(),
            $intents ?? new WriterObjectWriteIntentStore(self::intent()),
            $threshold,
        );
    }

    private static function profile(): AwsS3ProviderProfile
    {
        return new AwsS3ProviderProfile('backing', 'us-east-1', '123456789012', 'objects', '.opsfour/write-attempts');
    }

    private static function assertAttemptEvidenceHeaders(RequestInterface $request): void
    {
        $intent = self::intent();
        self::assertSame($intent->dispatchId, $request->getHeaderLine('x-amz-meta-opsfour-dispatch-id'));
        self::assertSame(hash('sha256', $intent->ownerId), $request->getHeaderLine('x-amz-meta-opsfour-owner-digest'));
        self::assertSame((string) $intent->expectedSize, $request->getHeaderLine('x-amz-meta-opsfour-content-size'));
        self::assertSame($intent->expectedSha256, $request->getHeaderLine('x-amz-meta-opsfour-content-sha256'));
    }

    private static function intent(bool $armed = true, ?string $dispatchId = null): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: self::ATTEMPT_ID,
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
                self::ATTEMPT_ID,
            ),
            purpose: 'put_object',
            leaseUntil: $armed ? 100.0 : 0.0,
            dispatchId: $armed ? ($dispatchId ?? str_repeat('d', 32)) : null,
            expectedSize: $armed ? 4 : null,
            expectedSha256: $armed ? hash('sha256', 'body') : null,
            sealReserved: $armed,
        );
    }

    /** @return list<Response|\Throwable> */
    private static function multipartResponses(Response|\Throwable $complete, int $parts = 1): array
    {
        $path = self::intent()->storagePath;

        return array_merge([
            new Response(200, [], '<?xml version="1.0"?><InitiateMultipartUploadResult><Bucket>backing</Bucket><Key>' . $path . '</Key><UploadId>upload-1</UploadId></InitiateMultipartUploadResult>'),
        ], array_map(
            static fn(int $part): Response => new Response(200, ['ETag' => '"part-' . $part . '"']),
            range(1, $parts),
        ), [$complete]);
    }

    private static function completedXml(): string
    {
        return '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>backing</Bucket><Key>'
            . self::intent()->storagePath . '</Key><ETag>"complete"</ETag></CompleteMultipartUploadResult>';
    }

    private static function error(int $status, string $code): Response
    {
        return new Response($status, [], '<?xml version="1.0"?><Error><Code>' . $code . '</Code><Message>secret provider diagnostic</Message></Error>');
    }
}

/** @internal Authoritative row seam for low-level writer tests. */
final class WriterObjectWriteIntentStore implements ObjectWriteIntentStore
{
    public function __construct(private ?ObjectWriteIntent $intent, private bool $throwOnRenewal = false) {}

    public function forget(): void
    {
        $this->intent = null;
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        return $this->intent?->attemptId === $attemptId ? $this->intent : null;
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        throw new \LogicException('Not supported.');
    }
    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        throw new \LogicException('Not supported.');
    }
    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds, ?int $expectedSize = null, ?string $expectedSha256 = null): ?ObjectWriteIntent
    {
        throw new \LogicException('Not supported.');
    }
    public function renewArmedObjectWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        if ($this->throwOnRenewal) {
            throw new \RuntimeException('secret metadata diagnostic');
        }
        if ($this->intent === null
            || $this->intent->attemptId !== $attemptId
            || $this->intent->dispatchId !== $dispatchId
            || $this->intent->leaseUntil !== $expectedLeaseUntil
            || $this->intent->state !== 'active') {
            return null;
        }

        $this->intent = new ObjectWriteIntent(
            attemptId: $this->intent->attemptId,
            bucket: $this->intent->bucket,
            bucketGeneration: $this->intent->bucketGeneration,
            ownerId: $this->intent->ownerId,
            storageTier: $this->intent->storageTier,
            storagePath: $this->intent->storagePath,
            purpose: $this->intent->purpose,
            state: $this->intent->state,
            leaseUntil: $this->intent->leaseUntil + max(1.0, $leaseSeconds),
            claimGeneration: $this->intent->claimGeneration,
            dispatchId: $this->intent->dispatchId,
            expectedSize: $this->intent->expectedSize,
            expectedSha256: $this->intent->expectedSha256,
            sealReserved: $this->intent->sealReserved,
        );

        return $this->intent;
    }
    public function expireArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        if ($this->intent?->attemptId !== $attemptId || $this->intent->dispatchId !== $dispatchId
            || $this->intent->state !== 'active') {
            return false;
        }
        return true;
    }
    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        throw new \LogicException('Not supported.');
    }
    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        throw new \LogicException('Not supported.');
    }
    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        throw new \LogicException('Not supported.');
    }
    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        throw new \LogicException('Not supported.');
    }
    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        throw new \LogicException('Not supported.');
    }
    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        throw new \LogicException('Not supported.');
    }
    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        throw new \LogicException('Not supported.');
    }
    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        throw new \LogicException('Not supported.');
    }
    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        throw new \LogicException('Not supported.');
    }
}
