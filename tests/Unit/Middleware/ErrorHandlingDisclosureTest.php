<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class ErrorHandlingDisclosureTest extends TestCase
{
    public function test_internal_exception_details_are_not_sent_to_s3_client(): void
    {
        $secret = 'postgres://user:password@internal-db.example/private';
        $exception = new InternalErrorException('Failed to read ' . $secret);
        $exception->setExtraHeaders(['x-debug-backend' => $secret]);
        $response = $this->dispatch($exception);
        $body = ByteStream\buffer($response->getBody());

        self::assertSame(500, $response->getStatus());
        self::assertStringContainsString('<Code>InternalError</Code>', $body);
        self::assertStringContainsString('We encountered an internal error. Please try again.', $body);
        self::assertStringNotContainsString($secret, $body);
        self::assertNull($response->getHeader('x-debug-backend'));
    }

    public function test_client_validation_error_keeps_its_useful_message(): void
    {
        $response = $this->dispatch(new InvalidArgumentException('max-keys must be an integer.'));
        $body = ByteStream\buffer($response->getBody());

        self::assertSame(400, $response->getStatus());
        self::assertStringContainsString('max-keys must be an integer.', $body);
    }

    public function test_internal_exception_details_are_not_written_to_logs(): void
    {
        $secret = 'database-password-private-test-token';
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        };

        foreach ([new InternalErrorException($secret), new \RuntimeException($secret)] as $exception) {
            self::assertSame(500, $this->dispatch($exception, $logger)->getStatus());
        }

        self::assertCount(2, $logger->records);
        foreach ($logger->records as $record) {
            self::assertStringNotContainsString($secret, $record['message'] . serialize($record['context']));
        }
        self::assertSame(InternalErrorException::class, $logger->records[0]['context']['exception']);
        self::assertSame('disclosure-test', $logger->records[0]['context']['requestId']);
        self::assertSame(\RuntimeException::class, $logger->records[1]['context']['exception']);
    }

    public function test_throwing_logger_cannot_replace_the_s3_error_response(): void
    {
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };

        $response = $this->dispatch(new InternalErrorException('storage failed'), $logger);

        self::assertSame(500, $response->getStatus());
        self::assertStringContainsString(
            '<Code>InternalError</Code>',
            ByteStream\buffer($response->getBody()),
        );
    }

    private function dispatch(\Throwable $exception, ?LoggerInterface $logger = null): Response
    {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://localhost/bucket/key'));
        $request->setAttribute('requestId', 'disclosure-test');

        return (new ErrorHandlingMiddleware($logger))->handleRequest($request, new class ($exception) implements RequestHandler {
            public function __construct(private readonly \Throwable $exception) {}

            public function handleRequest(Request $request): Response
            {
                throw $this->exception;
            }
        });
    }
}
