<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Driver\Client;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Http\RequestBody;
use PHPUnit\Framework\TestCase;

final class RequestBodyTest extends TestCase
{
    public function test_body_at_limit_is_accepted(): void
    {
        self::assertSame('abcd', RequestBody::buffer($this->request('abcd'), 4));
    }

    public function test_body_over_limit_is_rejected(): void
    {
        $this->expectException(EntityTooLargeException::class);
        RequestBody::buffer($this->request('abcde'), 4);
    }

    private function request(string $body): Request
    {
        return new Request(
            new RequestBodyTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket?config'),
            [],
            $body,
        );
    }
}

final class RequestBodyTestClient implements Client
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
