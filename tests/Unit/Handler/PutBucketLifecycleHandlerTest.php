<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Handler\Bucket\PutBucketLifecycleHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class PutBucketLifecycleHandlerTest extends TestCase
{
    public function test_invalid_lifecycle_replacement_keeps_existing_rules(): void
    {
        $path = sys_get_temp_dir() . '/s3-lifecycle-target-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', new InMemoryBackend(), defaultWriteTier: true),
            new StorageTier('GLACIER', new InMemoryBackend(), restoreRequired: true),
        ]);
        $handler = new PutBucketLifecycleHandler($metadata, $tiers);

        try {
            $accepted = '<LifecycleConfiguration><Rule><ID>retain</ID><Status>Enabled</Status><Prefix>data/</Prefix>'
                . '<Transition><Days>1</Days><StorageClass>GLACIER</StorageClass></Transition>'
                . '</Rule></LifecycleConfiguration>';
            self::assertSame(200, $handler->handleRequest($this->request($accepted))->getStatus());
            self::assertSame('GLACIER', $metadata->getBucketLifecycle('bucket')[0]['transitions'][0]['storageClass'] ?? null);

            $rejected = str_replace('GLACIER', 'UNCONFIGURED', $accepted);
            try {
                $handler->handleRequest($this->request($rejected));
                self::fail('An unavailable tier was accepted.');
            } catch (InvalidRequestException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }
            self::assertSame('retain', $metadata->getBucketLifecycle('bucket')[0]['id']);
            self::assertSame('GLACIER', $metadata->getBucketLifecycle('bucket')[0]['transitions'][0]['storageClass'] ?? null);

            $rule = '<Rule><ID>%s</ID><Status>Enabled</Status><Prefix>data/</Prefix>'
                . '<Expiration><Days>1</Days></Expiration></Rule>';
            $duplicate = '<LifecycleConfiguration>' . sprintf($rule, 'same') . sprintf($rule, 'same') . '</LifecycleConfiguration>';
            $tooLong = '<LifecycleConfiguration>' . sprintf($rule, str_repeat('x', 256)) . '</LifecycleConfiguration>';
            $empty = '<LifecycleConfiguration>' . sprintf($rule, '') . '</LifecycleConfiguration>';
            $nested = '<LifecycleConfiguration>' . sprintf($rule, 'same<Unexpected/>') . '</LifecycleConfiguration>';
            $nestedPrefix = '<LifecycleConfiguration><Rule><ID>nested-prefix</ID><Status>Enabled</Status>'
                . '<Prefix><Unexpected>data/</Unexpected></Prefix><Expiration><Days>1</Days></Expiration>'
                . '</Rule></LifecycleConfiguration>';
            $duplicateTag = '<LifecycleConfiguration><Rule><ID>duplicate-tag</ID><Status>Enabled</Status>'
                . '<Filter><And><Tag><Key>env</Key><Value>prod</Value></Tag>'
                . '<Tag><Key>env</Key><Value>dev</Value></Tag></And></Filter>'
                . '<Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>';
            foreach ([$duplicate, $tooLong, $empty, $nested, $nestedPrefix, $duplicateTag] as $invalid) {
                try {
                    $handler->handleRequest($this->request($invalid));
                    self::fail('An invalid rule ID was accepted.');
                } catch (MalformedXmlException $exception) {
                    self::assertSame(400, $exception->getHttpStatus());
                }
                self::assertSame('retain', $metadata->getBucketLifecycle('bucket')[0]['id']);
                self::assertSame('GLACIER', $metadata->getBucketLifecycle('bucket')[0]['transitions'][0]['storageClass'] ?? null);
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    private function request(string $body): Request
    {
        $request = new Request(new LifecycleHandlerTestClient(), 'PUT', Http::new('http://127.0.0.1/bucket?lifecycle'), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}

final class LifecycleHandlerTestClient implements Client
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
