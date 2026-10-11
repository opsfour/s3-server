<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Handler\Bucket\GetObjectLockConfigHandler;
use OpsFour\S3Server\Handler\Object\GetObjectLegalHoldHandler;
use OpsFour\S3Server\Handler\Object\GetObjectRetentionHandler;
use OpsFour\S3Server\Handler\Object\PutObjectLegalHoldHandler;
use OpsFour\S3Server\Handler\Object\PutObjectRetentionHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectLockDisabledConfigurationTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-object-lock-disabled-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectLockConfig('bucket', ['objectLockEnabled' => 'Disabled']);
        $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'owner',
            1,
            '"etag"',
            'application/octet-stream',
            'path',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_object_lock_subresources_reject_a_disabled_stored_configuration(): void
    {
        $retainUntil = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');

        foreach ([
            'get bucket configuration' => [new GetObjectLockConfigHandler($this->metadata), 'GET', '?object-lock', ''],
            'get retention' => [new GetObjectRetentionHandler($this->metadata), 'GET', '/key?retention', ''],
            'put retention' => [
                new PutObjectRetentionHandler($this->metadata),
                'PUT',
                '/key?retention',
                '<Retention><Mode>GOVERNANCE</Mode><RetainUntilDate>' . $retainUntil . '</RetainUntilDate></Retention>',
            ],
            'get legal hold' => [new GetObjectLegalHoldHandler($this->metadata), 'GET', '/key?legal-hold', ''],
            'put legal hold' => [
                new PutObjectLegalHoldHandler($this->metadata),
                'PUT',
                '/key?legal-hold',
                '<LegalHold><Status>ON</Status></LegalHold>',
            ],
        ] as $label => [$handler, $method, $suffix, $body]) {
            try {
                $handler->handleRequest($this->request($method, $suffix, $body));
                self::fail("{$label} accepted a disabled stored Object Lock configuration.");
            } catch (InvalidRequestException) {
                self::addToAssertionCount(1);
            }
        }

        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertNull($this->metadata->getObjectRetention('bucket', 'key', $object->versionId));
        self::assertNull($this->metadata->getObjectLegalHold('bucket', 'key', $object->versionId));
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $suffix, string $body): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1/bucket' . $suffix),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
