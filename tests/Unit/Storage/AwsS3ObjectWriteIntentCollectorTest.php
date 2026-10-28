<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\AwsS3SameKeySealWriter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3ObjectWriteIntentCollectorTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-aws-intent-');
        self::assertIsString($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_collector_installs_verified_seal_and_releases_only_open_capacity(): void
    {
        $store = $this->store();
        $armed = $this->dueArmedIntent($store);
        self::assertSame(['object' => 1, 'object_seal' => 1, 'part' => 0], $store->getWriteIntentCapacityCounts());
        $requests = [];
        $client = self::client([new Response(404), self::error(404, 'NoSuchKey'), new Response(200, ['ETag' => '"seal"']), self::matchingGet()], $requests);
        $metrics = new MetricsCollector();

        $collector = new AwsS3ObjectWriteIntentCollector(
            $store,
            'AWS',
            new AwsS3SameKeySealWriter($client, 'backing', '123456789012'),
            metrics: $metrics,
        );

        self::assertSame(1, $collector->collect());
        self::assertSame('sealed', $store->getObjectWriteIntent($armed->attemptId)?->state);
        self::assertSame(['object' => 0, 'object_seal' => 1, 'part' => 0], $store->getWriteIntentCapacityCounts());
        self::assertSame(['HEAD', 'GET', 'PUT', 'GET'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        self::assertStringContainsString('s3_server_aws_write_reconciliations_total{status="sealed"} 1', $metrics->renderPrometheus());
    }

    public function test_integrity_conflict_retains_open_and_seal_capacity(): void
    {
        $store = $this->store();
        $armed = $this->dueArmedIntent($store);
        $requests = [];
        $client = self::client([new Response(200, ['ETag' => '"foreign"', 'Content-Length' => '4'])], $requests);
        $metrics = new MetricsCollector();
        $collector = new AwsS3ObjectWriteIntentCollector(
            $store,
            'AWS',
            new AwsS3SameKeySealWriter($client, 'backing', '123456789012'),
            metrics: $metrics,
        );

        self::assertSame(0, $collector->collect());
        self::assertSame('reconciling', $store->getObjectWriteIntent($armed->attemptId)?->state);
        self::assertSame(['object' => 1, 'object_seal' => 1, 'part' => 0], $store->getWriteIntentCapacityCounts());
        self::assertSame(['HEAD'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_aws_write_reconciliations_total{status="integrity_conflict"} 1', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_write_reconciliations_total\{[^}]*owner/m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_write_reconciliations_total\{[^}]*(?:key|path)=/m', $rendered);
    }

    public function test_missing_attempt_followed_by_conditional_conflict_remains_fully_retained_without_retry_or_delete(): void
    {
        $store = $this->store();
        $metrics = new MetricsCollector();
        foreach ([409 => 'ConditionalRequestConflict', 412 => 'PreconditionFailed'] as $status => $code) {
            $attemptId = str_repeat($status === 409 ? '2' : '3', 32);
            $armed = $this->dueArmedIntent($store, $attemptId);
            $requests = [];
            $client = self::client([new Response(404), self::error(404, 'NoSuchKey'), self::error($status, $code)], $requests);
            $collector = new AwsS3ObjectWriteIntentCollector(
                $store,
                'AWS',
                new AwsS3SameKeySealWriter($client, 'backing', '123456789012'),
                metrics: $metrics,
            );

            self::assertSame(0, $collector->collect());
            self::assertSame('reconciling', $store->getObjectWriteIntent($armed->attemptId)?->state);
            $retained = $status === 409 ? 1 : 2;
            self::assertSame(
                ['object' => $retained, 'object_seal' => $retained, 'part' => 0],
                $store->getWriteIntentCapacityCounts(),
            );
            self::assertSame(
                ['HEAD', 'GET', 'PUT'],
                array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests),
            );
        }
        self::assertStringContainsString('s3_server_aws_write_reconciliations_total{status="retry"} 2', $metrics->renderPrometheus());
    }

    public function test_stale_claim_is_rejected_before_provider_io(): void
    {
        $first = $this->store();
        $armed = $this->dueArmedIntent($first);
        $oldClaim = $first->claimDueObjectWriteIntents('AWS', 1, 60.0)[0];
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $expire = $pdo->prepare('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?');
        $expire->execute([$armed->attemptId]);
        $second = $this->store();
        $newClaim = $second->claimDueObjectWriteIntents('AWS', 1, 60.0)[0];
        self::assertSame($oldClaim->claimGeneration + 1, $newClaim->claimGeneration);
        $requests = [];
        $reconciler = new AwsS3ObjectWriteIntentReconciler(
            $first,
            'AWS',
            new AwsS3SameKeySealWriter(self::client([], $requests), 'backing', '123456789012'),
        );

        try {
            $reconciler->reconcile($oldClaim);
            self::fail('A stale collector generation must not inspect or finalize AWS state.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no longer current', $error->getMessage());
        }
        self::assertSame([], $requests);
        self::assertSame('reconciling', $first->getObjectWriteIntent($armed->attemptId)?->state);
    }

    private function store(): SqliteMetadataStore
    {
        $store = new SqliteMetadataStore($this->databasePath);
        $store->initialize();

        return $store;
    }

    private function dueArmedIntent(SqliteMetadataStore $store, ?string $attemptId = null): ObjectWriteIntent
    {
        $attemptId ??= str_repeat('1', 32);
        $reserved = $store->reserveObjectWriteIntent(new ObjectWriteIntent(
            attemptId: $attemptId,
            bucket: 'logical',
            bucketGeneration: str_repeat('a', 32),
            ownerId: 'owner',
            storageTier: 'AWS',
            storagePath: 'generation/' . $attemptId,
            purpose: 'put_object',
            sealReserved: true,
        ), 60.0);
        $armed = $store->armObjectWriteIntent(
            $reserved->attemptId,
            $reserved->leaseUntil,
            str_repeat('d', 32),
            60.0,
            4,
            hash('sha256', 'body'),
        );
        self::assertNotNull($armed);
        self::assertTrue($store->abandonObjectWriteIntent($armed->attemptId));

        return $armed;
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<RequestInterface>    $requests
     */
    private static function client(array $responses, array &$requests): S3Client
    {
        $requests = [];
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.mock.invalid',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'retries' => 3,
            'http_handler' => static function (RequestInterface $request, array $options) use ($handler, &$requests) {
                self::assertSame('123456789012', $request->getHeaderLine('x-amz-expected-bucket-owner'));
                $requests[] = $request;

                return $handler($request, $options);
            },
        ]);
    }

    private static function matchingGet(): Response
    {
        $attemptId = str_repeat('1', 32);
        $body = "opsfour-s3-write-seal:v1:{$attemptId}\n";

        return new Response(206, [
            'ETag' => '"seal"',
            'Content-Length' => (string) strlen($body),
            'Content-Range' => 'bytes 0-' . (strlen($body) - 1) . '/' . strlen($body),
            'x-amz-meta-opsfour-write-seal' => 'v1',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
        ], $body);
    }

    private static function error(int $status, string $code): Response
    {
        return new Response($status, [], '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>rejected</Message></Error>');
    }
}
