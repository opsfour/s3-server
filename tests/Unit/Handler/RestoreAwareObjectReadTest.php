<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\WebsiteHostingMiddleware;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class RestoreAwareObjectReadTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $hot;

    private InMemoryBackend $cold;

    private StorageTierRegistry $tiers;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-restore-read-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');

        $this->hot = new InMemoryBackend();
        $this->cold = new InMemoryBackend();
        $this->hot->createBucket('bucket');
        $this->cold->createBucket('bucket');
        $this->tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_get_cold_object_without_restore_throws_invalid_object_state(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);

        $this->expectException(InvalidObjectStateException::class);

        $this->getHandler()->handleRequest($this->request('GET', '/bucket/archive.bin'));
    }

    public function test_get_object_during_transition_reads_existing_source_placement(): void
    {
        $hotWrite = $this->hot->putObject('bucket', 'moving.bin', new ReadableBuffer('still-hot'));
        $this->putMetadata('moving.bin', $hotWrite->path, $hotWrite->size, $hotWrite->md5Hex);
        $this->metadata->updateObjectPlacement(
            bucket: 'bucket',
            key: 'moving.bin',
            versionId: null,
            storageClass: 'STANDARD',
            storageTier: 'STANDARD',
            storagePath: $hotWrite->path,
            transitionStatus: 'processing',
            transitionTargetTier: 'GLACIER',
        );

        $response = $this->getHandler()->handleRequest($this->request('GET', '/bucket/moving.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('still-hot', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_get_cold_object_reads_valid_restored_hot_copy(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $hotWrite = $this->hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: null,
            restoreStatus: 'restored',
            restoredStoragePath: $hotWrite->path,
            restoreExpiresAt: new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        $response = $this->getHandler()->handleRequest($this->request('GET', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('restored-data', \Amp\ByteStream\buffer($response->getBody()));
        self::assertStringContainsString('ongoing-request="false"', $response->getHeader('x-amz-restore') ?? '');
    }

    public function test_private_bucket_website_configuration_does_not_publish_object(): void
    {
        $write = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('private'));
        $this->putMetadata('index.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $response = $middleware->handleRequest(
            $this->websiteRequest('GET', '/', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(403, $response->getStatus());
        self::assertStringContainsString('AccessDenied', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_website_endpoint_serves_public_acl_object_from_configured_tier(): void
    {
        $publicTier = new InMemoryBackend();
        $publicTier->createBucket('bucket');
        $write = $publicTier->putObject('bucket', 'index.html', new ReadableBuffer('<h1>published</h1>'));
        $this->putMetadata('index.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'index.html', null, 'PUBLIC', 'PUBLIC', $write->path);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $this->grantPublicRead('index.html');
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->hot, defaultWriteTier: true),
            new StorageTier('PUBLIC', $publicTier),
        ]);
        $middleware = new WebsiteHostingMiddleware($this->metadata, $tiers, '*.s3-website.test');

        $response = $middleware->handleRequest(
            new Request(
                new RestoreReadTestClient(),
                'GET',
                Http::new('http://bucket.s3-website.test/'),
                ['host' => 'bucket.s3-website.test'],
            ),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(200, $response->getStatus());
        self::assertSame('<h1>published</h1>', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_website_endpoint_only_activates_for_matching_host_and_safe_methods(): void
    {
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $wrongHost = $middleware->handleRequest(
            $this->websiteRequest('GET', '/', 'bucket.api.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        $unsafeMethod = $middleware->handleRequest(
            $this->websiteRequest('POST', '/', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(599, $wrongHost->getStatus());
        self::assertSame(599, $unsafeMethod->getStatus());
    }

    public function test_disabled_website_returns_no_configuration_and_head_has_no_body(): void
    {
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');
        $disabled = $middleware->handleRequest(
            $this->websiteRequest('GET', '/', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        self::assertSame(404, $disabled->getStatus());
        self::assertStringContainsString('NoSuchWebsiteConfiguration', \Amp\ByteStream\buffer($disabled->getBody()));

        $write = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('published'));
        $this->putMetadata('index.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $this->allowPublicReadByPolicy();
        $head = $middleware->handleRequest(
            $this->websiteRequest('HEAD', '/', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(200, $head->getStatus());
        self::assertSame('9', $head->getHeader('content-length'));
        self::assertSame('', \Amp\ByteStream\buffer($head->getBody()));
    }

    public function test_website_error_document_is_served_but_encrypted_error_document_is_rejected(): void
    {
        $write = $this->hot->putObject('bucket', 'error.html', new ReadableBuffer('not found'));
        $this->putMetadata('error.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html', 'error.html');
        $this->allowPublicReadByPolicy();
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $response = $middleware->handleRequest(
            $this->websiteRequest('GET', '/missing', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        self::assertSame(404, $response->getStatus());
        self::assertSame('not found', \Amp\ByteStream\buffer($response->getBody()));

        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'error.html',
            ownerId: 'owner',
            size: $write->size,
            etag: '"' . $write->md5Hex . '"',
            contentType: 'text/html',
            storagePath: $write->path,
            userMetadata: ['__sse-algorithm' => 'AES256'],
        );
        $encrypted = $middleware->handleRequest(
            $this->websiteRequest('GET', '/missing', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(403, $encrypted->getStatus());
        self::assertStringContainsString('AccessDenied', \Amp\ByteStream\buffer($encrypted->getBody()));
    }

    public function test_website_redirect_sanitizes_scheme_host_and_header_characters(): void
    {
        $this->metadata->putBucketWebsite(
            'bucket',
            'index.html',
            redirectAllHost: "example.com\r\nX-Injected: yes",
            redirectAllProtocol: 'javascript',
        );
        $this->allowPublicReadByPolicy();
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $response = $middleware->handleRequest(
            $this->websiteRequest('GET', '/docs', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(500, $response->getStatus());
        self::assertNull($response->getHeader('location'));
        self::assertStringContainsString('InvalidWebsiteConfiguration', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_website_endpoint_requires_restore_for_cold_tier_and_then_reads_hot_copy(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'index.html', new ReadableBuffer('cold-data'));
        $hotWrite = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('restored-website'));
        $this->putMetadata('index.html', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'index.html', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $this->grantPublicRead('index.html');
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');
        $request = new Request(
            new RestoreReadTestClient(),
            'GET',
            Http::new('http://bucket.s3-website.test/'),
            ['host' => 'bucket.s3-website.test'],
        );

        $notRestored = $middleware->handleRequest($request, new RestoreWebsiteTerminalHandler());
        self::assertSame(403, $notRestored->getStatus());
        self::assertStringContainsString('InvalidObjectState', \Amp\ByteStream\buffer($notRestored->getBody()));

        $this->metadata->updateObjectRestoreState(
            'bucket',
            'index.html',
            null,
            'restored',
            $hotWrite->path,
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );
        $restored = $middleware->handleRequest($request, new RestoreWebsiteTerminalHandler());
        self::assertSame(200, $restored->getStatus());
        self::assertSame('restored-website', \Amp\ByteStream\buffer($restored->getBody()));
    }

    public function test_website_public_access_block_disables_acl_and_policy_publication(): void
    {
        $write = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('published'));
        $this->putMetadata('index.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $this->grantPublicRead('index.html');
        $this->metadata->putPublicAccessBlock('bucket', false, true, false, false);
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');
        $request = $this->websiteRequest('GET', '/', 'bucket.s3-website.test');

        $ignoredAcl = $middleware->handleRequest($request, new RestoreWebsiteTerminalHandler());
        self::assertSame(403, $ignoredAcl->getStatus());

        $this->allowPublicReadByPolicy();
        $this->metadata->putPublicAccessBlock('bucket', false, false, false, true);
        $restrictedPolicy = $middleware->handleRequest($request, new RestoreWebsiteTerminalHandler());
        self::assertSame(403, $restrictedPolicy->getStatus());
    }

    public function test_website_explicit_policy_deny_overrides_public_object_acl(): void
    {
        $write = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('published'));
        $this->putMetadata('index.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $this->grantPublicRead('index.html');
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/index.html',
            ]],
        ], JSON_THROW_ON_ERROR));
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $response = $middleware->handleRequest(
            $this->websiteRequest('GET', '/', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(403, $response->getStatus());
        self::assertStringContainsString('AccessDenied', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_delete_removes_tier_object_and_temporary_restored_copy(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $hotWrite = $this->hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $hotWrite->path,
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        $response = (new DeleteObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('DELETE', '/bucket/archive.bin'));

        self::assertSame(204, $response->getStatus());
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin'));
        $this->assertStoragePathMissing($this->cold, $coldWrite->path);
        $this->assertStoragePathMissing($this->hot, $hotWrite->path);
    }

    public function test_copy_reads_source_from_its_physical_tier_and_writes_default_tier(): void
    {
        $warm = new InMemoryBackend();
        $warm->createBucket('bucket');
        $source = $warm->putObject('bucket', 'source.bin', new ReadableBuffer('tier-source'));
        $this->putMetadata('source.bin', $source->path, $source->size, $source->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'source.bin', null, 'WARM', 'WARM', $source->path);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->hot, defaultWriteTier: true),
            new StorageTier('WARM', $warm),
        ]);
        $request = new Request(
            new RestoreReadTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/copied.bin'),
            ['x-amz-copy-source' => '/bucket/source.bin'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'copied.bin');
        $request->setAttribute('ownerId', 'owner');

        $response = (new CopyObjectHandler($this->metadata, $this->hot, storageTiers: $tiers))
            ->handleRequest($request);

        self::assertSame(200, $response->getStatus());
        $copied = $this->metadata->getObjectMetadata('bucket', 'copied.bin');
        self::assertNotNull($copied);
        self::assertSame('STANDARD', $copied->storageTier);
        self::assertSame('tier-source', \Amp\ByteStream\buffer($this->hot->getObjectByPath($copied->systemMetadata['storagePath'])));
    }

    public function test_copy_uses_native_copy_when_source_and_destination_share_backend(): void
    {
        $source = $this->hot->putObject('bucket', 'source.bin', new ReadableBuffer('native-copy'));
        $this->putMetadata('source.bin', $source->path, $source->size, $source->md5Hex);
        $storage = new CallbackStorageBackend($this->hot);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $storage, defaultWriteTier: true),
        ]);
        $request = new Request(
            new RestoreReadTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/copied.bin'),
            ['x-amz-copy-source' => '/bucket/source.bin'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'copied.bin');
        $request->setAttribute('ownerId', 'owner');

        $response = (new CopyObjectHandler($this->metadata, $storage, storageTiers: $tiers))
            ->handleRequest($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame(1, $storage->copyCalls);
        self::assertSame(0, $storage->putCalls);
    }

    public function test_copy_cannot_read_private_source_from_another_account(): void
    {
        $this->metadata->createBucket('foreign-owner', 'foreign-bucket', 'us-east-1');
        $this->hot->createBucket('foreign-bucket');
        $source = $this->hot->putObject('foreign-bucket', 'private.bin', new ReadableBuffer('private'));
        $this->metadata->putObjectMetadata(
            'foreign-bucket',
            'private.bin',
            'foreign-owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $request = new Request(
            new RestoreReadTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/copied.bin'),
            ['x-amz-copy-source' => '/foreign-bucket/private.bin'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'copied.bin');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(AccessDeniedException::class);
        (new CopyObjectHandler($this->metadata, $this->hot, storageTiers: $this->tiers))
            ->handleRequest($request);
    }

    public function test_head_cold_object_reports_pending_restore_header(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $response = (new HeadObjectHandler($this->metadata))->handleRequest($this->request('HEAD', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('ongoing-request="true"', $response->getHeader('x-amz-restore'));
        self::assertSame('GLACIER', $response->getHeader('x-amz-storage-class'));
    }

    public function test_get_object_attributes_requires_matching_sse_customer_key(): void
    {
        $rawKey = str_repeat('k', 32);
        $keyMd5 = base64_encode(md5($rawKey, true));
        $write = $this->hot->putObject('bucket', 'encrypted.bin', new ReadableBuffer('ciphertext'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'encrypted.bin',
            'owner',
            $write->size,
            '"' . $write->md5Hex . '"',
            'application/octet-stream',
            $write->path,
            userMetadata: [
                '__sse-algorithm' => 'SSE-C',
                '__sse-customer-key-md5' => $keyMd5,
            ],
        );

        $this->expectException(\OpsFour\S3Server\Exception\InvalidArgumentException::class);
        (new GetObjectAttributesHandler($this->metadata))
            ->handleRequest($this->request('GET', '/bucket/encrypted.bin?attributes'));
    }

    public function test_restore_object_enqueues_restore_job_and_marks_object_pending(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);

        $body = '<RestoreRequest><Days>3</Days><GlacierJobParameters><Tier>Standard</Tier></GlacierJobParameters></RestoreRequest>';
        $response = (new RestoreObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?restore', $body));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        $jobs = $this->metadata->dequeueRestoreJobs(1);

        self::assertSame(202, $response->getStatus());
        self::assertNotNull($object);
        self::assertSame('pending', $object->restoreStatus);
        self::assertCount(1, $jobs);
        self::assertSame('archive.bin', $jobs[0]['key']);
        self::assertSame(3, $jobs[0]['restoreDays']);
    }

    public function test_restore_object_rejects_duplicate_pending_restore(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $this->expectException(OperationAbortedException::class);

        (new RestoreObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?restore', '<RestoreRequest><Days>3</Days></RestoreRequest>'));
    }

    private function getHandler(): GetObjectHandler
    {
        return new GetObjectHandler($this->metadata, $this->hot, storageTiers: $this->tiers);
    }

    private function putMetadata(string $key, string $path, int $size, string $md5): void
    {
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: $key,
            ownerId: 'owner',
            size: $size,
            etag: '"' . $md5 . '"',
            contentType: 'application/octet-stream',
            storagePath: $path,
        );
    }

    /**
     * @param non-empty-string $method
     */
    private function request(string $method, string $path, string $body = ''): Request
    {
        $request = new Request(
            new RestoreReadTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $urlPath = parse_url($path, PHP_URL_PATH);
        $request->setAttribute('s3.key', ltrim(substr((string) $urlPath, strlen('/bucket/')), '/'));
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    /**
     * @param non-empty-string $method
     */
    private function websiteRequest(string $method, string $path, string $host): Request
    {
        return new Request(
            new RestoreReadTestClient(),
            $method,
            Http::new('http://' . $host . $path),
            ['host' => $host],
        );
    }

    private function grantPublicRead(string $key): void
    {
        $this->metadata->putAcl('object', "bucket/{$key}", 'owner', [
            [
                'granteeType' => 'CanonicalUser',
                'granteeId' => 'owner',
                'permission' => 'FULL_CONTROL',
            ],
            [
                'granteeType' => 'Group',
                'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers',
                'permission' => 'READ',
            ],
        ]);
    }

    private function allowPublicReadByPolicy(): void
    {
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/*',
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertStoragePathMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            $storage->getObjectByPath($path);
            self::fail("Expected storage path to be deleted: {$path}");
        } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
        }
    }
}

final class RestoreReadTestClient implements Client
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

final class RestoreWebsiteTerminalHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return new Response(599);
    }
}
