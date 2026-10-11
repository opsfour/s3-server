<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Bucket\ListBucketsHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListBucketsXmlBoundaryTest extends TestCase
{
    #[DataProvider('invalidPrefixes')]
    public function test_list_buckets_rejects_prefix_that_cannot_be_echoed_as_xml(string $encodedPrefix): void
    {
        $metadata = $this->createStub(MetadataStore::class);
        $request = new Request(
            new ListBucketsXmlTestClient(),
            'GET',
            Http::new('http://127.0.0.1/?prefix=' . $encodedPrefix),
        );
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidArgumentException::class);
        (new ListBucketsHandler($metadata))->handleRequest($request);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPrefixes(): iterable
    {
        yield 'invalid UTF-8' => ['%C3%28'];
        yield 'XML control' => ['%01'];
        yield 'XML noncharacter' => ['%EF%BF%BE'];
    }
}

final class ListBucketsXmlTestClient implements Client
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

    public function getTlsInfo(): ?TlsInfo
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
