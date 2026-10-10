<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Storage\AwsS3SameKeySealWriter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3SameKeySealWriterTest extends TestCase
{
    public function test_existing_key_is_replaced_only_with_matching_etag_and_verified_marker(): void
    {
        $requests = [];
        $client = self::client([self::putSuccess(), self::matchingHead()], $requests);

        (new AwsS3SameKeySealWriter($client, 'backing'))->sealMatching('generation/attempt', 'attempt-1', '"old"');

        self::assertCount(2, $requests);
        self::assertSame(['PUT', 'HEAD'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        self::assertSame('/backing/generation/attempt', $requests[0]->getUri()->getPath());
        self::assertSame('"old"', $requests[0]->getHeaderLine('If-Match'));
        self::assertSame('', $requests[0]->getHeaderLine('If-None-Match'));
        self::assertSame('v1', $requests[0]->getHeaderLine('x-amz-meta-opsfour-write-seal'));
        self::assertSame('attempt-1', $requests[0]->getHeaderLine('x-amz-meta-opsfour-attempt-id'));
        self::assertSame("opsfour-s3-write-seal:v1:attempt-1\n", (string) $requests[0]->getBody());
        self::assertFalse($requests[0]->hasHeader('x-amz-acl'));
    }

    public function test_absent_key_is_sealed_with_create_only_condition(): void
    {
        $requests = [];
        $client = self::client([self::putSuccess(), self::matchingHead()], $requests);

        (new AwsS3SameKeySealWriter($client, 'backing'))->sealMissing('generation/attempt', 'attempt-1');

        self::assertCount(2, $requests);
        self::assertSame('*', $requests[0]->getHeaderLine('If-None-Match'));
        self::assertSame('', $requests[0]->getHeaderLine('If-Match'));
    }

    public function test_conflicts_fail_without_retry_or_delete(): void
    {
        foreach ([409 => 'ConditionalRequestConflict', 412 => 'PreconditionFailed'] as $status => $code) {
            $requests = [];
            $client = self::client([self::error($status, $code)], $requests);

            try {
                (new AwsS3SameKeySealWriter($client, 'backing'))->sealMatching('generation/attempt', 'attempt-1', '"old"');
                self::fail("AWS {$status} must fail closed.");
            } catch (\Aws\Exception\AwsException $exception) {
                self::assertSame($code, $exception->getAwsErrorCode());
            }

            self::assertCount(1, $requests);
            self::assertSame('PUT', $requests[0]->getMethod());
        }
    }

    public function test_unverifiable_etag_on_success_response_is_ambiguous_without_head_or_delete(): void
    {
        foreach ([[], ['ETag' => 'invalid'], ['ETag' => '" "']] as $headers) {
            $requests = [];
            $client = self::client([new Response(200, $headers)], $requests);

            try {
                (new AwsS3SameKeySealWriter($client, 'backing'))->sealMissing('generation/attempt', 'attempt-1');
                self::fail('An invalid ETag cannot prove the seal.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('no verifiable ETag', $exception->getMessage());
            }

            self::assertCount(1, $requests);
            self::assertSame('PUT', $requests[0]->getMethod());
        }
    }

    public function test_head_with_wrong_marker_or_attempt_or_etag_is_ambiguous(): void
    {
        $badHeads = [
            new Response(200, ['ETag' => '"seal"']),
            new Response(200, ['ETag' => '"seal"', 'x-amz-meta-opsfour-write-seal' => 'v1', 'x-amz-meta-opsfour-attempt-id' => 'other']),
            new Response(200, ['ETag' => '"other"', 'x-amz-meta-opsfour-write-seal' => 'v1', 'x-amz-meta-opsfour-attempt-id' => 'attempt-1']),
        ];

        foreach ($badHeads as $badHead) {
            $requests = [];
            $client = self::client([self::putSuccess(), $badHead], $requests);

            try {
                (new AwsS3SameKeySealWriter($client, 'backing'))->sealMissing('generation/attempt', 'attempt-1');
                self::fail('A mismatched HEAD cannot prove the seal.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('could not be verified', $exception->getMessage());
            }

            self::assertCount(2, $requests);
            self::assertSame(['PUT', 'HEAD'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        }
    }

    public function test_head_failure_after_put_is_not_retried_or_deleted(): void
    {
        $requests = [];
        $client = self::client([self::putSuccess(), self::error(503, 'ServiceUnavailable')], $requests);

        try {
            (new AwsS3SameKeySealWriter($client, 'backing'))->sealMissing('generation/attempt', 'attempt-1');
            self::fail('A failed HEAD leaves the outcome ambiguous.');
        } catch (\Aws\Exception\AwsException $exception) {
            self::assertSame('ServiceUnavailable', $exception->getAwsErrorCode());
        }

        self::assertCount(2, $requests);
        self::assertSame(['PUT', 'HEAD'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
    }

    public function test_transport_timeout_never_retries_or_deletes(): void
    {
        $requests = [];
        $failure = new ConnectException('simulated timeout', new Request('PUT', 'https://s3.mock.invalid/backing/generation/attempt'));
        $client = self::client([$failure], $requests);

        try {
            (new AwsS3SameKeySealWriter($client, 'backing'))->sealMissing('generation/attempt', 'attempt-1');
            self::fail('The timeout outcome is ambiguous.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('simulated timeout', $exception->getMessage());
        }

        self::assertCount(1, $requests);
        self::assertSame('PUT', $requests[0]->getMethod());
    }

    public function test_wildcard_or_malformed_etag_is_rejected_before_io(): void
    {
        $requests = [];
        $client = self::client([], $requests);
        foreach (['*', '"*"', 'old', '"old","other"', '"old"\r\n'] as $etag) {
            try {
                (new AwsS3SameKeySealWriter($client, 'backing'))->sealMatching('generation/attempt', 'attempt-1', $etag);
                self::fail('Only exact ETags are safe for replacement.');
            } catch (\InvalidArgumentException) {
                // Invalid preconditions must never reach the provider.
            }
        }

        self::assertCount(0, $requests);
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<RequestInterface> $requests
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
                $requests[] = $request;

                return $handler($request, $options);
            },
        ]);
    }

    private static function putSuccess(): Response
    {
        return new Response(200, ['ETag' => '"seal"']);
    }

    private static function matchingHead(): Response
    {
        return new Response(200, [
            'ETag' => '"seal"',
            'x-amz-meta-opsfour-write-seal' => 'v1',
            'x-amz-meta-opsfour-attempt-id' => 'attempt-1',
        ]);
    }

    private static function error(int $status, string $code): Response
    {
        return new Response($status, [], '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>rejected</Message></Error>');
    }
}
