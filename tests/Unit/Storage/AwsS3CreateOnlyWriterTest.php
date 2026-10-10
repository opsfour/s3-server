<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\S3\S3Client;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Storage\AwsS3CreateOnlyWriter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3CreateOnlyWriterTest extends TestCase
{
    private const int PART_SIZE = 5 * 1024 * 1024;

    public function test_small_put_is_create_only_without_acl(): void
    {
        $requests = [];
        $client = self::client([new Response(200, ['ETag' => '"new"'])], $requests);

        (new AwsS3CreateOnlyWriter($client, 'backing'))->publish('generation/attempt', 'hello');

        self::assertCount(1, $requests);
        self::assertSame('PUT', $requests[0]->getMethod());
        self::assertSame('/backing/generation/attempt', $requests[0]->getUri()->getPath());
        self::assertSame('*', $requests[0]->getHeaderLine('If-None-Match'));
        self::assertFalse($requests[0]->hasHeader('x-amz-acl'));
    }

    public function test_multipart_complete_is_create_only_and_other_commands_are_not_conditional(): void
    {
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], self::completedXml())), $requests);

        (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));

        self::assertCount(3, $requests);
        self::assertSame(['POST', 'PUT', 'POST'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        self::assertSame(['', '', '*'], array_map(static fn(RequestInterface $request): string => $request->getHeaderLine('If-None-Match'), $requests));
        self::assertSame('uploads', $requests[0]->getUri()->getQuery());
        self::assertStringContainsString('uploadId=upload-1', $requests[2]->getUri()->getQuery());
        self::assertFalse($requests[0]->hasHeader('x-amz-acl'));
    }

    public function test_put_precondition_failure_is_propagated_without_retry_or_delete(): void
    {
        $requests = [];
        $client = self::client([self::error(412, 'PreconditionFailed')], $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing'))->publish('generation/attempt', 'hello');
            self::fail('The conditional PutObject must fail.');
        } catch (\Aws\Exception\AwsException $exception) {
            self::assertSame('PreconditionFailed', $exception->getAwsErrorCode());
        }

        self::assertCount(1, $requests);
        self::assertSame('PUT', $requests[0]->getMethod());
    }

    public function test_put_response_without_exact_etag_is_ambiguous(): void
    {
        foreach (['  ', 'unquoted', '"*"', '"two","values"'] as $etag) {
            $requests = [];
            $client = self::client([new Response(200, ['ETag' => $etag])], $requests);

            try {
                (new AwsS3CreateOnlyWriter($client, 'backing'))->publish('generation/attempt', 'hello');
                self::fail('An invalid ETag cannot prove publication.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('invalid completion result', $exception->getMessage());
            }

            self::assertCount(1, $requests);
        }
    }

    public function test_multipart_result_without_bucket_or_key_is_ambiguous(): void
    {
        foreach ([
            '<Bucket>backing</Bucket>',
            '<Key>generation/attempt</Key>',
        ] as $incompleteFields) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult>'
                . $incompleteFields . '<ETag>"complete"</ETag></CompleteMultipartUploadResult>';
            $requests = [];
            $client = self::client(self::multipartResponses(new Response(200, [], $xml)), $requests);

            try {
                (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
                self::fail('A completion response without both Bucket and Key cannot prove publication.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('invalid completion result', $exception->getMessage());
            }

            self::assertCount(3, $requests);
        }
    }

    public function test_multipart_result_with_malformed_etag_is_ambiguous(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult>'
            . '<Bucket>backing</Bucket><Key>generation/attempt</Key><ETag>invalid</ETag></CompleteMultipartUploadResult>';
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], $xml)), $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('A malformed multipart ETag cannot prove publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid completion result', $exception->getMessage());
        }

        self::assertCount(3, $requests);
    }

    public function test_multipart_conflict_is_propagated_without_retry_abort_or_delete(): void
    {
        $requests = [];
        $client = self::client(self::multipartResponses(self::error(409, 'ConditionalRequestConflict')), $requests);

        $this->expectFailureAfterRequests($client, $requests, 3);
    }

    public function test_multipart_embedded_200_error_is_propagated_without_retry_abort_or_delete(): void
    {
        $embeddedError = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>ambiguous completion</Message></Error>';
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], $embeddedError)), $requests);

        $this->expectFailureAfterRequests($client, $requests, 3);
    }

    public function test_multipart_200_error_after_long_whitespace_is_not_accepted_as_success(): void
    {
        $hiddenError = '<?xml version="1.0" encoding="UTF-8"?>' . str_repeat(' ', 128) . '<Error><Code>InternalError</Code><Message>ambiguous completion</Message></Error>';
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], $hiddenError)), $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('An ambiguous Complete result must not be treated as published.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('completion result', $exception->getMessage());
        }

        self::assertCount(3, $requests);
        self::assertSame('*', $requests[2]->getHeaderLine('If-None-Match'));
    }

    public function test_multipart_200_error_with_plausible_success_fields_is_not_accepted(): void
    {
        $hiddenError = '<?xml version="1.0" encoding="UTF-8"?>' . str_repeat(' ', 128)
            . '<Error><Code>InternalError</Code><Message>ambiguous completion</Message><Bucket>backing</Bucket><Key>generation/attempt</Key><ETag>"complete"</ETag><Location>https://s3.mock.invalid/backing/generation/attempt</Location></Error>';
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], $hiddenError)), $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('An Error document with success-shaped fields must not be accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid completion result', $exception->getMessage());
        }

        self::assertCount(3, $requests);
        self::assertSame('*', $requests[2]->getHeaderLine('If-None-Match'));
    }

    public function test_multipart_success_root_containing_error_is_not_accepted(): void
    {
        $mixedBody = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult><Bucket>backing</Bucket><Key>generation/attempt</Key><ETag>"complete"</ETag><Error><Code>InternalError</Code></Error></CompleteMultipartUploadResult>';
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], $mixedBody)), $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('A Complete result containing an Error element must not be accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid completion result', $exception->getMessage());
        }

        self::assertCount(3, $requests);
    }

    public function test_multipart_result_without_raw_response_observation_remains_ambiguous(): void
    {
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], self::completedXml())), $requests, false);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('A Complete response without raw-body observation must not be accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid completion result', $exception->getMessage());
        }

        self::assertCount(3, $requests);
        self::assertSame(['POST', 'PUT', 'POST'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
    }

    public function test_multipart_result_for_another_key_is_not_accepted(): void
    {
        $wrongKey = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult><Bucket>backing</Bucket><Key>another/attempt</Key><ETag>"complete"</ETag></CompleteMultipartUploadResult>';
        $requests = [];
        $client = self::client(self::multipartResponses(new Response(200, [], $wrongKey)), $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('A Complete result for another key must not be published.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid completion result', $exception->getMessage());
        }

        self::assertCount(3, $requests);
        self::assertSame(['POST', 'PUT', 'POST'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
    }

    public function test_multipart_complete_transport_failure_is_propagated_without_retry_abort_or_delete(): void
    {
        $failure = new ConnectException('simulated complete timeout', new Request('POST', 'https://s3.mock.invalid/backing/generation/attempt'));
        $requests = [];
        $client = self::client(self::multipartResponses($failure), $requests);

        $this->expectFailureAfterRequests($client, $requests, 3);
    }

    public function test_put_transport_failure_is_propagated_without_retry_or_delete(): void
    {
        $failure = new ConnectException('simulated timeout', new Request('PUT', 'https://s3.mock.invalid/backing/generation/attempt'));
        $requests = [];
        $client = self::client([$failure], $requests);

        try {
            (new AwsS3CreateOnlyWriter($client, 'backing'))->publish('generation/attempt', 'hello');
            self::fail('The transport failure must propagate.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('simulated timeout', $exception->getMessage());
        }

        self::assertCount(1, $requests);
        self::assertSame('PUT', $requests[0]->getMethod());
    }

    public function test_initiate_failure_is_not_retried(): void
    {
        $requests = [];
        $client = self::client([self::error(503, 'ServiceUnavailable')], $requests);

        $this->expectFailureAfterRequests($client, $requests, 1);
    }

    public function test_part_failure_is_not_retried(): void
    {
        $responses = self::multipartResponses(new Response(200, [], self::completedXml()));
        $responses[1] = self::error(503, 'ServiceUnavailable');
        $requests = [];
        $client = self::client(array_values($responses), $requests);

        $this->expectFailureAfterRequests($client, $requests, 2);
    }

    /** @param list<RequestInterface> $requests */
    private function expectFailureAfterRequests(S3Client $client, array &$requests, int $expectedCount): void
    {
        try {
            (new AwsS3CreateOnlyWriter($client, 'backing', self::PART_SIZE))->publish('generation/attempt', str_repeat('x', self::PART_SIZE));
            self::fail('The multipart operation must fail.');
        } catch (\Aws\S3\Exception\S3MultipartUploadException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertCount($expectedCount, $requests);
        foreach ($requests as $request) {
            self::assertNotSame('DELETE', $request->getMethod());
        }
        if ($expectedCount === 3) {
            self::assertSame('*', $requests[2]->getHeaderLine('If-None-Match'));
        }
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<RequestInterface> $requests
     */
    private static function client(array $responses, array &$requests, bool $observeStats = true): S3Client
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
            'http_handler' => static function (RequestInterface $request, array $options) use ($handler, &$requests, $observeStats) {
                $requests[] = $request;
                if (! $observeStats) {
                    unset($options['on_stats']);
                }

                return $handler($request, $options);
            },
        ]);
    }

    /** @return list<Response|\Throwable> */
    private static function multipartResponses(Response|\Throwable $complete): array
    {
        return [
            new Response(200, [], '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult><Bucket>backing</Bucket><Key>generation/attempt</Key><UploadId>upload-1</UploadId></InitiateMultipartUploadResult>'),
            new Response(200, ['ETag' => '"part-1"']),
            $complete,
        ];
    }

    private static function completedXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult><Bucket>backing</Bucket><Key>generation/attempt</Key><ETag>"complete"</ETag></CompleteMultipartUploadResult>';
    }

    private static function error(int $status, string $code): Response
    {
        return new Response($status, [], '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>rejected</Message></Error>');
    }
}
