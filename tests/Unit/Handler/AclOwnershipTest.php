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
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\InvalidArgumentException;
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

    public function test_oversized_explicit_bucket_acl_does_not_replace_existing_grants(): void
    {
        $existing = [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'existing-reader',
            'permission' => 'READ',
        ]];
        $this->metadata->putAcl('bucket', 'bucket', 'bucket-owner', $existing);
        $readers = [];
        for ($index = 0; $index < 100; ++$index) {
            $readers[] = 'id="reader-' . $index . '"';
        }
        $request = new Request(
            new ObjectAclOwnershipTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket?acl'),
            ['x-amz-grant-read' => implode(',', $readers)],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'bucket-owner');

        try {
            (new PutBucketAclHandler($this->metadata))->handleRequest($request);
            self::fail('An ACL with more than 100 grants was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('InvalidArgument', $error->getErrorCode());
        }

        self::assertSame($existing, $this->metadata->getAcl('bucket', 'bucket'));
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

    public function test_controls_and_acl_replacement_never_rewrite_version_owners(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $firstVersion = $this->metadata->putObjectVersioned(
            'bucket',
            'history',
            'first-owner',
            3,
            '"first"',
            'text/plain',
            'first-path',
        );
        $secondVersion = $this->metadata->putObjectVersioned(
            'bucket',
            'history',
            'second-owner',
            4,
            '"second"',
            'text/plain',
            'second-path',
        );
        $firstResource = 'bucket/history?versionId=' . rawurlencode($firstVersion);
        $secondResource = 'bucket/history?versionId=' . rawurlencode($secondVersion);
        $firstAcl = [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'reader',
            'permission' => 'READ',
        ]];
        $secondAcl = [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'second-owner',
            'permission' => 'FULL_CONTROL',
        ]];
        $this->metadata->putAcl('object', $firstResource, 'first-owner', $firstAcl);
        $this->metadata->putAcl('object', $secondResource, 'second-owner', $secondAcl);

        $this->metadata->putBucketOwnershipControls('bucket', BucketOwnership::BUCKET_OWNER_ENFORCED);
        $masked = (new GetObjectAclHandler($this->metadata))->handleRequest(
            $this->versionRequest('GET', 'history', $firstVersion),
        );
        $maskedXml = new \SimpleXMLElement(ByteStream\buffer($masked->getBody()));
        self::assertSame('bucket-owner', (string) $maskedXml->Owner->ID);
        self::assertSame('bucket-owner', (string) $maskedXml->AccessControlList->Grant[0]->Grantee->ID);

        $this->metadata->putBucketOwnershipControls('bucket', BucketOwnership::BUCKET_OWNER_PREFERRED);
        $revealed = (new GetObjectAclHandler($this->metadata))->handleRequest(
            $this->versionRequest('GET', 'history', $firstVersion),
        );
        $revealedXml = new \SimpleXMLElement(ByteStream\buffer($revealed->getBody()));
        self::assertSame('first-owner', (string) $revealedXml->Owner->ID);
        self::assertSame('reader', (string) $revealedXml->AccessControlList->Grant[0]->Grantee->ID);

        self::assertSame(200, (new PutObjectAclHandler($this->metadata))->handleRequest(
            $this->versionRequest('PUT', 'history', $firstVersion, ['x-amz-acl' => 'private']),
        )->getStatus());
        self::assertSame('first-owner', $this->metadata
            ->getObjectMetadataByVersion('bucket', 'history', $firstVersion)?->ownerId);
        self::assertSame('second-owner', $this->metadata
            ->getObjectMetadataByVersion('bucket', 'history', $secondVersion)?->ownerId);
        self::assertSame('first-owner', $this->metadata->getAcl('object', $firstResource)[0]['granteeId']);
        self::assertSame($secondAcl, $this->metadata->getAcl('object', $secondResource));
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

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, array<string>|string> $headers
     */
    private function versionRequest(string $method, string $key, string $versionId, array $headers = []): Request
    {
        $request = new Request(
            new ObjectAclOwnershipTestClient(),
            $method,
            Http::new('http://127.0.0.1/bucket/' . rawurlencode($key) . '?acl&versionId=' . rawurlencode($versionId)),
            $headers,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', $key);
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
