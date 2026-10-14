<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class AdminQuotaRouterTest extends TestCase
{
    public function test_percent_encoded_slash_reaches_quota_handler_as_one_owner_id(): void
    {
        $root = sys_get_temp_dir() . '/s3-admin-quota-router-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o700));
        $database = $root . '/metadata.sqlite';
        $storageRoot = $root . '/objects';
        self::assertTrue(mkdir($storageRoot, 0o700));
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $port = $this->unusedPort();
        $server = new S3Server(
            new S3ServerConfig(host: '127.0.0.1', port: $port, storagePath: $root, perClientRateLimit: 0),
            $metadata,
            new FilesystemBackend($storageRoot),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setAdminQuotaApiHandler(new AdminQuotaApiHandler($metadata, 'admin-secret'));

        try {
            $server->start();
            $request = new Request("http://127.0.0.1:{$port}/.admin/quotas/tenant%2Fmember", 'PUT');
            $request->setHeader('authorization', 'Bearer admin-secret');
            $request->setHeader('content-type', 'application/json');
            $request->setBody(json_encode([
                'maxBucketsPerOwner' => 2,
                'maxObjectsPerBucket' => 0,
                'maxBytesPerBucket' => 0,
                'maxBytesPerOwner' => 0,
                'maxMultipartUploadsPerBucket' => 0,
                'maxMultipartUploadsPerOwner' => 0,
                'maxMultipartBytesPerBucket' => 0,
                'maxMultipartBytesPerOwner' => 0,
            ], JSON_THROW_ON_ERROR));

            $response = HttpClientBuilder::buildDefault()->request($request);

            self::assertSame(200, $response->getStatus());
            self::assertSame('tenant/member', json_decode(buffer($response->getBody()), true, flags: JSON_THROW_ON_ERROR)['ownerId']);
            self::assertSame(2, $metadata->getAccountQuota('tenant/member')?->maxBucketsPerOwner);
        } finally {
            $server->stop();
            @unlink($database);
            @unlink($database . '-wal');
            @unlink($database . '-shm');
            @rmdir($storageRoot);
            @rmdir($root);
        }
    }

    private function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}
