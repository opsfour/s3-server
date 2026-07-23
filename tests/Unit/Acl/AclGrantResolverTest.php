<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Acl;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AclGrantResolverTest extends TestCase
{
    public function test_unknown_canned_acl_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported canned ACL');

        AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => 'made-up']),
            'owner',
            'object',
            'bucket-owner',
        );
    }

    public function test_canned_acl_and_explicit_grants_are_mutually_exclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        AclGrantResolver::fromHeaders(
            $this->request([
                'x-amz-acl' => 'private',
                'x-amz-grant-read' => 'id="reader"',
            ]),
            'owner',
            'object',
            'bucket-owner',
        );
    }

    /**
     * @param array<non-empty-string, string> $headers
     */
    #[DataProvider('invalidGrantHeaders')]
    public function test_invalid_or_unresolvable_grants_are_rejected(array $headers, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        AclGrantResolver::fromHeaders($this->request($headers), 'owner', 'bucket');
    }

    /**
     * @return iterable<string, array{array<non-empty-string, string>, string}>
     */
    public static function invalidGrantHeaders(): iterable
    {
        yield 'malformed' => [['x-amz-grant-read' => 'reader'], 'Invalid grantee'];
        yield 'email without resolver' => [
            ['x-amz-grant-read' => 'emailAddress="reader@example.test"'],
            'require an account resolver',
        ];
        yield 'unknown group' => [
            ['x-amz-grant-read' => 'uri="https://example.test/group"'],
            'Unsupported S3 ACL group URI',
        ];
    }

    public function test_object_owner_and_log_delivery_canned_acls_expand_completely(): void
    {
        $object = AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => 'bucket-owner-full-control']),
            'writer',
            'object',
            'bucket-owner',
        );
        self::assertNotNull($object);
        self::assertSame(['writer', 'bucket-owner'], array_column($object, 'granteeId'));
        self::assertSame(['FULL_CONTROL', 'FULL_CONTROL'], array_column($object, 'permission'));

        $bucket = AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => 'log-delivery-write']),
            'owner',
            'bucket',
        );
        self::assertNotNull($bucket);
        self::assertSame(['FULL_CONTROL', 'WRITE', 'READ_ACP'], array_column($bucket, 'permission'));
        self::assertSame(AclGrantResolver::LOG_DELIVERY_URI, $bucket[1]['granteeId']);
    }

    public function test_xml_email_grantee_is_rejected_by_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require an account resolver');

        AclGrantResolver::validateGrants([[
            'granteeType' => 'AmazonCustomerByEmail',
            'granteeId' => 'reader@example.test',
            'permission' => 'READ',
        ]]);
    }

    /**
     * @param array<non-empty-string, string> $headers
     */
    private function request(array $headers): Request
    {
        return new Request(
            new AclGrantResolverTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
    }
}

final class AclGrantResolverTestClient implements Client
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

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
