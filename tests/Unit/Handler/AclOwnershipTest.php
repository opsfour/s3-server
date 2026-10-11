<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Bucket\PutBucketAclHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAclHandler;
use OpsFour\S3Server\Handler\Object\PutObjectAclHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class AclOwnershipTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-acl-owner-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $this->metadata->putObjectMetadata('bucket', 'key', 'object-owner', 4, '"etag"', 'text/plain', 'path');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_foreign_acl_reader_sees_object_owner_not_requester(): void
    {
        $response = (new GetObjectAclHandler($this->metadata))->handleRequest($this->request('GET'));
        $xml = new \SimpleXMLElement(ByteStream\buffer($response->getBody()));

        self::assertSame('object-owner', (string) $xml->Owner->ID);
        self::assertSame('object-owner', (string) $xml->AccessControlList->Grant[0]->Grantee->ID);
        self::assertSame('FULL_CONTROL', (string) $xml->AccessControlList->Grant[0]->Permission);
    }

    public function test_foreign_acl_writer_cannot_make_private_acl_grant_them_ownership(): void
    {
        $response = (new PutObjectAclHandler($this->metadata))->handleRequest($this->request('PUT', ['x-amz-acl' => 'private']));
        self::assertSame(200, $response->getStatus());

        $grants = $this->metadata->getAcl('object', 'bucket/key?versionId=null');
        self::assertCount(1, $grants);
        self::assertSame('object-owner', $grants[0]['granteeId']);

        $read = (new GetObjectAclHandler($this->metadata))->handleRequest($this->request('GET'));
        $xml = new \SimpleXMLElement(ByteStream\buffer($read->getBody()));
        self::assertSame('object-owner', (string) $xml->Owner->ID);
    }

    public function test_foreign_bucket_acl_writer_cannot_make_private_acl_grant_them_ownership(): void
    {
        $request = new Request(
            new ObjectAclOwnershipTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket?acl'),
            ['x-amz-acl' => 'private'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'acl-reader');

        self::assertSame(200, (new PutBucketAclHandler($this->metadata))->handleRequest($request)->getStatus());
        $grants = $this->metadata->getAcl('bucket', 'bucket');
        self::assertCount(1, $grants);
        self::assertSame('bucket-owner', $grants[0]['granteeId']);
    }

    public function test_updating_numbered_version_acl_does_not_clear_legacy_null_version_acl(): void
    {
        $legacyGrants = [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'legacy-reader',
            'permission' => 'READ',
        ]];
        $this->metadata->putAcl('object', 'bucket/key', 'object-owner', $legacyGrants);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $versionId = $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'version-owner',
            3,
            '"new"',
            'text/plain',
            'new-path',
        );
        $request = new Request(
            new ObjectAclOwnershipTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?acl&versionId=' . rawurlencode($versionId)),
            ['x-amz-acl' => 'private'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'version-owner');
        $request->setAttribute('credential', null);

        self::assertSame(200, (new PutObjectAclHandler($this->metadata))->handleRequest($request)->getStatus());
        self::assertSame($legacyGrants, $this->metadata->getAcl('object', 'bucket/key'));
        self::assertSame('version-owner', $this->metadata->getAcl(
            'object',
            'bucket/key?versionId=' . rawurlencode($versionId),
        )[0]['granteeId']);
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, array<string>|string> $headers
     */
    private function request(string $method, array $headers = []): Request
    {
        $request = new Request(new ObjectAclOwnershipTestClient(), $method, Http::new('http://127.0.0.1/bucket/key?acl'), $headers);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'acl-reader');
        $request->setAttribute('credential', null);

        return $request;
    }
}

final class ObjectAclOwnershipTestClient implements Client
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
