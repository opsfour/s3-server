<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use Amp\NullCancellation;
use Amp\Sync\Channel;
use Aws\S3\S3Client;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Parallel\AwsS3PayloadReadTask;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3PayloadReadTaskTest extends TestCase
{
    private AwsS3ProviderProfile $profile;
    private string $generation;
    private string $attempt;
    private string $path;

    protected function setUp(): void
    {
        $this->profile = new AwsS3ProviderProfile(
            'backing-private',
            'us-east-1',
            '123456789012',
            'objects',
            '.opsfour/write-attempts',
        );
        $this->generation = str_repeat('a', 32);
        $this->attempt = str_repeat('b', 32);
        $this->path = AwsS3WriteAttemptPath::forProfileObject(
            $this->profile,
            'bucket',
            'key',
            $this->generation,
            'STANDARD',
            $this->attempt,
        );
    }

    public function test_full_read_sends_one_bounded_chunk_per_ack_then_explicit_eof(): void
    {
        $payload = str_repeat('x', 65_536) . 'tail';
        $client = $this->client(new Response(200, $this->headers($payload), $payload));
        $sent = [];
        $channel = $this->channel($sent, ['next', 'next']);

        $this->task(strlen($payload))->runWithClient($client, $channel, new NullCancellation());

        self::assertSame([str_repeat('x', 65_536), 'tail', null], $sent);
    }

    public function test_range_read_requires_exact_206_framing_and_uses_one_range_request(): void
    {
        $requests = [];
        $payload = '2345';
        $client = $this->client(new Response(206, $this->headers(
            $payload,
            ['Content-Range' => 'bytes 2-5/10', 'x-amz-meta-opsfour-content-size' => '10'],
        ), $payload), $requests);
        $sent = [];
        $channel = $this->channel($sent, ['next']);

        $this->task(10, 2, 4)->runWithClient($client, $channel, new NullCancellation());

        self::assertCount(1, $requests);
        self::assertSame('bytes=2-5', $requests[0]->getHeaderLine('Range'));
        self::assertSame([$payload, null], $sent);
    }

    public function test_zero_byte_read_is_verified_before_explicit_eof(): void
    {
        $client = $this->client(new Response(200, $this->headers(''), ''));
        $sent = [];
        $channel = $this->channel($sent, []);

        $this->task(0)->runWithClient($client, $channel, new NullCancellation());

        self::assertSame([null], $sent);
    }

    public function test_invalid_marker_fails_before_any_chunk_is_sent(): void
    {
        $client = $this->client(new Response(200, $this->headers('body', [
            'x-amz-meta-opsfour-write-attempt' => 'v1',
        ]), 'body'));
        $sent = [];

        $failure = null;
        try {
            $this->task(4)->runWithClient($client, $this->channel($sent, []), new NullCancellation());
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure, 'Invalid AWS evidence was accepted.');
        self::assertStringContainsString('evidence', $failure->getMessage());
        self::assertSame([], $sent);
    }

    public function test_short_and_excess_bodies_fail_instead_of_clean_eof(): void
    {
        foreach ([
            ['declared' => 5, 'body' => 'tiny'],
            ['declared' => 4, 'body' => 'extra'],
        ] as $case) {
            $headers = $this->headers($case['body'], [
                'Content-Length' => (string) $case['declared'],
                'x-amz-meta-opsfour-content-size' => (string) $case['declared'],
            ]);
            $client = $this->client(new Response(200, $headers, $case['body']));
            $sent = [];
            $failure = null;
            try {
                $this->task($case['declared'])->runWithClient(
                    $client,
                    $this->channel($sent, ['next']),
                    new NullCancellation(),
                );
            } catch (\RuntimeException $error) {
                $failure = $error;
            }
            self::assertNotNull($failure, 'Malformed provider body was accepted.');
            self::assertNotSame([null], $sent);
        }
    }

    public function test_production_error_boundary_excludes_provider_details(): void
    {
        $failure = null;

        try {
            (new \ReflectionMethod(AwsS3PayloadReadTask::class, 'runSafely'))->invoke(
                $this->task(4),
                new NullCancellation(),
                static fn(): null => throw new \RuntimeException('provider-secret-sentinel'),
            );
        } catch (\RuntimeException $error) {
            $failure = $error;
        }

        self::assertNotNull($failure);
        self::assertSame('AWS payload read worker failed.', $failure->getMessage());
        self::assertStringNotContainsString('provider-secret-sentinel', $failure->getMessage());
    }

    public function test_non_next_ack_stops_without_reading_or_publishing_more_chunks(): void
    {
        $payload = str_repeat('x', 70_000);
        $client = $this->client(new Response(200, $this->headers($payload), $payload));
        $sent = [];

        $this->task(strlen($payload))->runWithClient(
            $client,
            $this->channel($sent, ['cancel']),
            new NullCancellation(),
        );

        self::assertCount(1, $sent);
        self::assertIsString($sent[0]);
        self::assertSame(65_536, strlen($sent[0]));
    }

    public function test_multipart_part_accepts_the_provider_size_within_the_admitted_window(): void
    {
        $requests = [];
        $payload = 'part-body';
        $client = $this->client(new Response(200, $this->headers($payload), $payload), $requests);
        $sent = [];

        $this->multipartTask(4, 16)->runWithClient(
            $client,
            $this->channel($sent, ['next']),
            new NullCancellation(),
        );

        self::assertCount(1, $requests);
        self::assertFalse($requests[0]->hasHeader('Range'));
        self::assertSame([$payload, null], $sent);
    }

    public function test_multipart_part_rejects_marker_and_size_evidence_before_sending_bytes(): void
    {
        foreach ([
            'marker' => [
                'payload' => 'part-body',
                'minimum' => 4,
                'maximum' => 16,
                'headers' => ['x-amz-meta-opsfour-write-attempt' => 'v1'],
            ],
            'below minimum' => [
                'payload' => 'abc',
                'minimum' => 4,
                'maximum' => 16,
                'headers' => [],
            ],
            'above maximum' => [
                'payload' => str_repeat('x', 17),
                'minimum' => 4,
                'maximum' => 16,
                'headers' => [],
            ],
            'logical size marker' => [
                'payload' => 'part-body',
                'minimum' => 4,
                'maximum' => 16,
                'headers' => ['x-amz-meta-opsfour-content-size' => '8'],
            ],
        ] as $label => $case) {
            $client = $this->client(new Response(
                200,
                $this->headers($case['payload'], $case['headers']),
                $case['payload'],
            ));
            $sent = [];

            $failure = null;
            try {
                $this->multipartTask($case['minimum'], $case['maximum'])->runWithClient(
                    $client,
                    $this->channel($sent, []),
                    new NullCancellation(),
                );
            } catch (\RuntimeException $error) {
                $failure = $error;
            }

            self::assertNotNull($failure, "Invalid multipart {$label} evidence was accepted.");
            self::assertSame([], $sent, "Multipart {$label} evidence released payload bytes.");
        }
    }

    /** @param list<RequestInterface> $requests */
    private function client(Response $response, array &$requests = []): S3Client
    {
        $mock = new MockHandler([$response]);
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create($mock)]));
        return (new AwsS3ProviderValidator(
            static function (RequestInterface $request, array $options) use ($handler, &$requests): PromiseInterface {
                $requests[] = $request;
                return $handler($request, $options);
            },
        ))->createClient($this->profile);
    }

    /**
     * @param array<string, string> $replace
     * @return array<string, string>
     */
    private function headers(string $payload, array $replace = []): array
    {
        return array_replace([
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $this->attempt,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
        ], $replace);
    }

    /**
     * @param list<string|null> $sent
     * @param list<string> $acks
     * @return Channel<string, string|null>
     */
    private function channel(array &$sent, array $acks): Channel
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('send')->willReturnCallback(static function (mixed $value) use (&$sent): void {
            $sent[] = $value;
        });
        $channel->method('receive')->willReturnCallback(static function () use (&$acks): string {
            return array_shift($acks) ?? throw new \RuntimeException('Unexpected acknowledgement request.');
        });

        return $channel;
    }

    private function task(int $size, ?int $offset = null, ?int $length = null): AwsS3PayloadReadTask
    {
        return new AwsS3PayloadReadTask(
            $this->profile,
            AwsS3PayloadReadTask::COMMITTED,
            'bucket',
            $this->generation,
            'key',
            $this->path,
            'STANDARD',
            'owner',
            $size,
            $size,
            $offset,
            $length,
        );
    }

    private function multipartTask(int $minimumSize, int $maximumSize): AwsS3PayloadReadTask
    {
        return new AwsS3PayloadReadTask(
            $this->profile,
            AwsS3PayloadReadTask::MULTIPART_PART,
            'bucket',
            $this->generation,
            'key',
            $this->path,
            'STANDARD',
            'owner',
            $minimumSize,
            $maximumSize,
        );
    }
}
