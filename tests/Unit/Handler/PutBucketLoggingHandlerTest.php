<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidTargetBucketForLoggingException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Handler\Bucket\PutBucketLoggingHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use PHPUnit\Framework\TestCase;

final class PutBucketLoggingHandlerTest extends TestCase
{
    public function test_target_owner_is_checked_against_source_owner(): void
    {
        $path = sys_get_temp_dir() . '/s3-logging-owner-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('source-owner', 'source', 'us-east-1');
        $metadata->createBucket('source-owner', 'owner-target', 'us-east-1');
        $metadata->createBucket('requester', 'requester-target', 'us-east-1');
        $handler = new PutBucketLoggingHandler($metadata);

        try {
            self::assertSame(200, $handler->handleRequest($this->request('owner-target'))->getStatus());
            $logging = $metadata->getBucketLogging('source');
            self::assertNotNull($logging);
            self::assertSame('owner-target', $logging['targetBucket']);

            try {
                $handler->handleRequest($this->request('requester-target'));
                self::fail('A target owned by another account was accepted.');
            } catch (InvalidTargetBucketForLoggingException) {
            }
            self::assertSame($logging, $metadata->getBucketLogging('source'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_invalid_targets_return_aws_compatible_wire_errors(): void
    {
        $path = sys_get_temp_dir() . '/s3-logging-target-errors-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('source-owner', 'source', 'us-east-1');
        $metadata->createBucket('another-owner', 'foreign-target', 'us-east-1');
        $metadata->createBucket('source-owner', 'remote-target', 'eu-west-1');
        $handler = new PutBucketLoggingHandler($metadata);

        try {
            foreach (['missing-target', 'foreign-target'] as $target) {
                $response = (new ErrorHandlingMiddleware())->handleRequest($this->request($target), $handler);

                self::assertSame(400, $response->getStatus());
                self::assertStringContainsString(
                    '<Code>InvalidTargetBucketForLogging</Code>',
                    \Amp\ByteStream\buffer($response->getBody()),
                );
            }

            $response = (new ErrorHandlingMiddleware())->handleRequest($this->request('remote-target'), $handler);

            self::assertSame(403, $response->getStatus());
            self::assertStringContainsString(
                '<Code>CrossLocationLoggingProhibited</Code>',
                \Amp\ByteStream\buffer($response->getBody()),
            );
            self::assertNull($metadata->getBucketLogging('source'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_unknown_xml_attributes_are_rejected_before_mutation(): void
    {
        $path = sys_get_temp_dir() . '/s3-logging-attribute-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'source', 'us-east-1');
        $handler = new PutBucketLoggingHandler($metadata);
        $body = '<BucketLoggingStatus source="client"/>';
        $request = new Request(new LoggingHandlerTestClient(), 'PUT', Http::new('http://127.0.0.1/source?logging'), [], $body);
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('ownerId', 'owner');

        try {
            $this->expectException(MalformedXmlException::class);
            $handler->handleRequest($request);
        } finally {
            self::assertNull($metadata->getBucketLogging('source'));
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    private function request(string $target): Request
    {
        $body = '<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . $target . '</TargetBucket>'
            . '<TargetPrefix>access/</TargetPrefix></LoggingEnabled></BucketLoggingStatus>';
        $request = new Request(new LoggingHandlerTestClient(), 'PUT', Http::new('http://127.0.0.1/source?logging'), [], $body);
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('ownerId', 'requester');

        return $request;
    }
}

final class LoggingHandlerTestClient implements Client
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
