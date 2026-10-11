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
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\RestoreAlreadyInProgressException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\WebsiteHostingMiddleware;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
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
        $hotWrite = $this->hot->putObject('bucket', 'archive.bin', new ReadableBuffer('warm-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: null,
            restoreStatus: 'restored',
            restoredStoragePath: $hotWrite->path,
            restoreExpiresAt: new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
            restoredStorageTier: 'STANDARD',
        );

        $response = $this->getHandler()->handleRequest($this->request('GET', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('warm-data', \Amp\ByteStream\buffer($response->getBody()));
        self::assertStringContainsString('ongoing-request="false"', $response->getHeader('x-amz-restore') ?? '');
    }

    public function test_restored_read_uses_recorded_tier_when_api_default_differs_from_worker(): void
    {
        $other = new InMemoryBackend();
        $other->createBucket('bucket');
        $source = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('source-data'));
        $this->putMetadata('archive.bin', $source->path, $source->size, $source->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $source->path, 1);

        $workerTiers = new StorageTierRegistry([
            new StorageTier('HOT', $this->hot, defaultWriteTier: true),
            new StorageTier('STANDARD', $other),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
        self::assertSame(1, (new RestoreExecutor($this->metadata, $workerTiers))->processNext(1)['completed']);
        $restored = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($restored);
        self::assertSame('HOT', $restored->restoredStorageTier);
        self::assertNotNull($restored->restoredStoragePath);

        // Each backend starts its path counter at one: a wrong-tier read finds
        // a valid path and silently returns different bytes.
        $decoy = $other->putObject('bucket', 'archive.bin', new ReadableBuffer('wrong-bytes'));
        self::assertSame($restored->restoredStoragePath, $decoy->path);
        $apiTiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $other, defaultWriteTier: true),
            new StorageTier('HOT', $this->hot),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
        $response = (new GetObjectHandler($this->metadata, $other, storageTiers: $apiTiers))
            ->handleRequest($this->request('GET', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('source-data', \Amp\ByteStream\buffer($response->getBody()));

        $copyRequest = $this->request('PUT', '/bucket/copied.bin');
        $copyRequest->setHeader('x-amz-copy-source', '/bucket/archive.bin');
        $copyResponse = (new CopyObjectHandler($this->metadata, $other, storageTiers: $apiTiers))
            ->handleRequest($copyRequest);
        self::assertSame(200, $copyResponse->getStatus());
        $copied = $this->metadata->getObjectMetadata('bucket', 'copied.bin');
        self::assertNotNull($copied);
        self::assertSame('source-data', \Amp\ByteStream\buffer($other->getObjectByPath($copied->systemMetadata['storagePath'])));

        $uploadId = str_repeat('a', 32);
        $this->metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'multipart.bin', 'owner', 'STANDARD');
        $partRequest = $this->request('PUT', '/bucket/multipart.bin?partNumber=1&uploadId=' . $uploadId);
        $partRequest->setHeader('x-amz-copy-source', '/bucket/archive.bin');
        $partResponse = (new UploadPartCopyHandler($this->metadata, $other, storageTiers: $apiTiers))
            ->handleRequest($partRequest);
        self::assertSame(200, $partResponse->getStatus());
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);
        self::assertSame('source-data', \Amp\ByteStream\buffer($other->getObjectByPath($parts[0]['storage_path'])));

        $selectXml = '<SelectObjectContentRequest xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Expression>SELECT * FROM s3object</Expression><ExpressionType>SQL</ExpressionType>'
            . '<InputSerialization><CSV/></InputSerialization><OutputSerialization><CSV/></OutputSerialization>'
            . '</SelectObjectContentRequest>';
        $select = (new SelectObjectContentHandler($this->metadata, $other, storageTiers: $apiTiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?select&select-type=2', $selectXml));
        self::assertSame(200, $select->getStatus());
        self::assertStringContainsString('source-data', \Amp\ByteStream\buffer($select->getBody()));

        $this->metadata->putBucketWebsite('bucket', 'archive.bin');
        $this->grantPublicRead('archive.bin');
        $website = (new WebsiteHostingMiddleware($this->metadata, $apiTiers, '*.s3-website.test'))
            ->handleRequest($this->websiteRequest('GET', '/', 'bucket.s3-website.test'), new RestoreWebsiteTerminalHandler());
        self::assertSame(200, $website->getStatus());
        self::assertSame('source-data', \Amp\ByteStream\buffer($website->getBody()));

        (new \PDO('sqlite:' . $this->path))->exec(
            "UPDATE s3_objects SET restored_storage_tier = NULL WHERE bucket = 'bucket' AND key_name = 'archive.bin'",
        );
        $this->expectException(InternalErrorException::class);
        (new GetObjectHandler($this->metadata, $other, storageTiers: $apiTiers))
            ->handleRequest($this->request('GET', '/bucket/archive.bin'));
    }

    public function test_encrypted_restored_read_uses_recorded_tier_across_defaults(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $encrypted = $encryption->encryptSseS3('secret-data');
        $source = $this->cold->putObject('bucket', 'encrypted.bin', new ReadableBuffer($encrypted['ciphertext']));
        $this->metadata->putObjectMetadata(
            'bucket',
            'encrypted.bin',
            'owner',
            strlen('secret-data'),
            '"' . md5('secret-data') . '"',
            'application/octet-stream',
            $source->path,
            userMetadata: [
                '__sse-algorithm' => 'AES256',
                '__sse-key' => $encrypted['encryptedDataKey'],
                '__sse-iv' => $encrypted['iv'],
                '__sse-tag' => $encrypted['tag'],
            ],
        );
        $this->metadata->updateObjectPlacement('bucket', 'encrypted.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->enqueueRestoreJob('bucket', 'encrypted.bin', null, 'GLACIER', $source->path, 1);
        $other = new InMemoryBackend();
        $other->createBucket('bucket');
        $workerTiers = new StorageTierRegistry([
            new StorageTier('HOT', $this->hot, defaultWriteTier: true),
            new StorageTier('STANDARD', $other),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
        self::assertSame(1, (new RestoreExecutor($this->metadata, $workerTiers))->processNext(1)['completed']);
        $restored = $this->metadata->getObjectMetadata('bucket', 'encrypted.bin');
        self::assertNotNull($restored);
        self::assertSame('HOT', $restored->restoredStorageTier);
        $decoy = $other->putObject('bucket', 'encrypted.bin', new ReadableBuffer('wrong-bytes'));
        self::assertSame($restored->restoredStoragePath, $decoy->path);

        $apiTiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $other, defaultWriteTier: true),
            new StorageTier('HOT', $this->hot),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
        $response = (new GetObjectHandler($this->metadata, $other, $encryption, storageTiers: $apiTiers))
            ->handleRequest($this->request('GET', '/bucket/encrypted.bin'));
        self::assertSame(200, $response->getStatus());
        self::assertSame('secret-data', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_copy_overwrite_cleans_old_restored_tier_when_paths_collide(): void
    {
        $other = new InMemoryBackend();
        $other->createBucket('bucket');
        $oldRestore = $this->hot->putObject('bucket', 'copied.bin', new ReadableBuffer('old-restore'));
        $source = $this->hot->putObject('bucket', 'source.bin', new ReadableBuffer('new-content'));
        $oldPrimary = $this->cold->putObject('bucket', 'copied.bin', new ReadableBuffer('old-restore'));
        $this->putMetadata('copied.bin', $oldPrimary->path, $oldPrimary->size, $oldPrimary->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'copied.bin', null, 'GLACIER', 'GLACIER', $oldPrimary->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'copied.bin',
            null,
            'restored',
            $oldRestore->path,
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
            'HOT',
        );
        $this->putMetadata('source.bin', $source->path, $source->size, $source->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'source.bin', null, 'HOT', 'HOT', $source->path);

        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $other, defaultWriteTier: true),
            new StorageTier('HOT', $this->hot),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
        $request = $this->request('PUT', '/bucket/copied.bin');
        $request->setHeader('x-amz-copy-source', '/bucket/source.bin');
        self::assertSame(200, (new CopyObjectHandler($this->metadata, $other, storageTiers: $tiers))->handleRequest($request)->getStatus());

        $copied = $this->metadata->getObjectMetadata('bucket', 'copied.bin');
        self::assertNotNull($copied);
        self::assertSame($oldRestore->path, $copied->systemMetadata['storagePath']);
        self::assertSame('new-content', \Amp\ByteStream\buffer($other->getObjectByPath($copied->systemMetadata['storagePath'])));
        $this->assertStoragePathMissing($this->hot, $oldRestore->path);
        $this->assertStoragePathMissing($this->cold, $oldPrimary->path);
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

    public function test_get_does_not_stream_bytes_beyond_recorded_object_size(): void
    {
        $write = $this->hot->putObject('bucket', 'oversized.bin', new ReadableBuffer('abcEXTRA'));
        $this->putMetadata('oversized.bin', $write->path, 3, $write->md5Hex);

        $response = $this->getHandler()->handleRequest($this->request('GET', '/bucket/oversized.bin'));

        self::assertSame('3', $response->getHeader('content-length'));
        self::assertSame('abc', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_website_object_and_error_document_do_not_stream_beyond_recorded_size(): void
    {
        $index = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('abcEXTRA'));
        $error = $this->hot->putObject('bucket', 'error.html', new ReadableBuffer('errEXTRA'));
        $this->putMetadata('index.html', $index->path, 3, $index->md5Hex);
        $this->putMetadata('error.html', $error->path, 3, $error->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html', 'error.html');
        $this->allowPublicReadByPolicy();
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $indexResponse = $middleware->handleRequest(
            $this->websiteRequest('GET', '/', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        self::assertSame(200, $indexResponse->getStatus());
        self::assertSame('3', $indexResponse->getHeader('content-length'));
        self::assertSame('abc', \Amp\ByteStream\buffer($indexResponse->getBody()));

        $errorResponse = $middleware->handleRequest(
            $this->websiteRequest('GET', '/missing', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        self::assertSame(404, $errorResponse->getStatus());
        self::assertSame('3', $errorResponse->getHeader('content-length'));
        self::assertSame('err', \Amp\ByteStream\buffer($errorResponse->getBody()));
    }

    public function test_website_path_decodes_key_once_and_keeps_bucket_named_first_segment(): void
    {
        $key = 'bucket/folder space.txt';
        $write = $this->hot->putObject('bucket', $key, new ReadableBuffer('public file'));
        $this->putMetadata($key, $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite('bucket', 'index.html');
        $this->grantPublicRead($key);

        $response = (new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test'))
            ->handleRequest(
                $this->websiteRequest('GET', '/bucket/folder%20space.txt', 'bucket.s3-website.test'),
                new RestoreWebsiteTerminalHandler(),
            );

        self::assertSame(200, $response->getStatus());
        self::assertSame('public file', \Amp\ByteStream\buffer($response->getBody()));
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

    public function test_website_host_matching_is_case_insensitive(): void
    {
        $this->metadata->putBucketWebsite('bucket', 'index.html', redirectAllHost: 'example.com');
        $response = (new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.S3-WEBSITE.TEST'))
            ->handleRequest(
                $this->websiteRequest('GET', '/', 'BUCKET.S3-WEBSITE.TEST'),
                new RestoreWebsiteTerminalHandler(),
            );

        self::assertSame(301, $response->getStatus());
        self::assertSame('http://example.com/', $response->getHeader('Location'));
    }

    public function test_website_redirect_without_protocol_preserves_trusted_proxy_https(): void
    {
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');
        $this->metadata->putBucketWebsite('bucket', 'index.html', redirectAllHost: 'example.com');
        $redirectAllRequest = $this->websiteRequest('GET', '/old.html', 'bucket.s3-website.test');
        $redirectAllRequest->setAttribute(\OpsFour\S3Server\Security\RequestNetworkContext::SECURE_TRANSPORT_ATTRIBUTE, true);
        $redirectAll = $middleware->handleRequest($redirectAllRequest, new RestoreWebsiteTerminalHandler());

        self::assertSame(301, $redirectAll->getStatus());
        self::assertSame('https://example.com/old.html', $redirectAll->getHeader('Location'));

        $this->metadata->putBucketWebsite('bucket', 'index.html', routingRules: [[
            'redirect' => ['replaceKeyWith' => 'secure.html'],
        ]]);
        $request = $this->websiteRequest('GET', '/old.html', 'bucket.s3-website.test');
        $request->setAttribute(\OpsFour\S3Server\Security\RequestNetworkContext::SECURE_TRANSPORT_ATTRIBUTE, true);

        $response = $middleware->handleRequest($request, new RestoreWebsiteTerminalHandler());

        self::assertSame(301, $response->getStatus());
        self::assertSame('https://bucket.s3-website.test/secure.html', $response->getHeader('Location'));
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

    public function test_website_object_and_error_document_preserve_cache_metadata(): void
    {
        foreach (['index.html', 'error.html'] as $key) {
            $write = $this->hot->putObject('bucket', $key, new ReadableBuffer($key));
            $this->metadata->putObjectMetadata(
                bucket: 'bucket',
                key: $key,
                ownerId: 'owner',
                size: $write->size,
                etag: '"' . $write->md5Hex . '"',
                contentType: 'text/html',
                storagePath: $write->path,
                contentEncoding: 'gzip',
                contentDisposition: 'inline',
                cacheControl: 'public, max-age=300',
                userMetadata: ['__expires' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
            );
        }
        $this->metadata->putBucketWebsite('bucket', 'index.html', 'error.html');
        $this->allowPublicReadByPolicy();
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        foreach (['/' => 200, '/missing' => 404] as $path => $status) {
            $response = $middleware->handleRequest(
                $this->websiteRequest('HEAD', $path, 'bucket.s3-website.test'),
                new RestoreWebsiteTerminalHandler(),
            );
            self::assertSame($status, $response->getStatus());
            self::assertSame('gzip', $response->getHeader('Content-Encoding'));
            self::assertSame('inline', $response->getHeader('Content-Disposition'));
            self::assertSame('public, max-age=300', $response->getHeader('Cache-Control'));
            self::assertSame('Wed, 21 Oct 2026 07:28:00 GMT', $response->getHeader('Expires'));
            self::assertNotNull($response->getHeader('ETag'));
            self::assertNotNull($response->getHeader('Last-Modified'));
        }
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

    public function test_redirect_only_website_works_without_an_object_or_public_read_grant(): void
    {
        $this->metadata->putBucketWebsite(
            'bucket',
            'index.html',
            redirectAllHost: 'example.com',
            redirectAllProtocol: 'https',
        );
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');

        $response = $middleware->handleRequest(
            $this->websiteRequest('GET', '/missing?from=old', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );

        self::assertSame(301, $response->getStatus());
        self::assertSame('https://example.com/missing?from=old', $response->getHeader('Location'));
    }

    public function test_website_prefix_rule_redirects_even_when_object_exists(): void
    {
        $write = $this->hot->putObject('bucket', 'old/page.html', new ReadableBuffer('existing'));
        $this->putMetadata('old/page.html', $write->path, $write->size, $write->md5Hex);
        $this->metadata->putBucketWebsite(
            'bucket',
            'index.html',
            routingRules: [[
                'condition' => ['keyPrefixEquals' => 'old/'],
                'redirect' => ['replaceKeyPrefixWith' => 'new/'],
            ]],
        );

        $response = (new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test'))
            ->handleRequest(
                $this->websiteRequest('GET', '/old/page.html', 'bucket.s3-website.test'),
                new RestoreWebsiteTerminalHandler(),
            );

        self::assertSame(301, $response->getStatus());
        self::assertSame('http://bucket.s3-website.test/new/page.html', $response->getHeader('Location'));
    }

    public function test_website_unconditional_and_access_denied_rules_redirect_without_public_access(): void
    {
        $middleware = new WebsiteHostingMiddleware($this->metadata, $this->tiers, '*.s3-website.test');
        $this->metadata->putBucketWebsite('bucket', 'index.html', routingRules: [[
            'redirect' => ['hostName' => 'example.com'],
        ]]);

        $unconditional = $middleware->handleRequest(
            $this->websiteRequest('GET', '/private', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        self::assertSame(301, $unconditional->getStatus());
        self::assertSame('http://example.com/private', $unconditional->getHeader('Location'));

        $this->metadata->putBucketWebsite('bucket', 'index.html', routingRules: [[
            'condition' => ['httpErrorCodeReturnedEquals' => 403],
            'redirect' => ['hostName' => 'example.com', 'replaceKeyPrefixWith' => 'denied/'],
        ]]);
        $denied = $middleware->handleRequest(
            $this->websiteRequest('GET', '/private', 'bucket.s3-website.test'),
            new RestoreWebsiteTerminalHandler(),
        );
        self::assertSame(301, $denied->getStatus());
        self::assertSame('http://example.com/denied/private', $denied->getHeader('Location'));
    }

    public function test_website_endpoint_requires_restore_for_cold_tier_and_then_reads_hot_copy(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'index.html', new ReadableBuffer('cold-data'));
        $hotWrite = $this->hot->putObject('bucket', 'index.html', new ReadableBuffer('hot-copy!'));
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

        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'index.html',
            null,
            'restored',
            $hotWrite->path,
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
            'STANDARD',
        );
        $restored = $middleware->handleRequest($request, new RestoreWebsiteTerminalHandler());
        self::assertSame(200, $restored->getStatus());
        self::assertSame('hot-copy!', \Amp\ByteStream\buffer($restored->getBody()));
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
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $hotWrite->path,
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
            'STANDARD',
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

    public function test_head_cold_object_does_not_report_a_failed_restore_as_ongoing(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'failed');

        $response = (new HeadObjectHandler($this->metadata))->handleRequest($this->request('HEAD', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getHeader('x-amz-restore'));
        self::assertSame('GLACIER', $response->getHeader('x-amz-storage-class'));
    }

    public function test_head_connection_close_is_only_sent_for_http_one(): void
    {
        $write = $this->hot->putObject('bucket', 'object.bin', new ReadableBuffer('data'));
        $this->putMetadata('object.bin', $write->path, $write->size, $write->md5Hex);
        $handler = new HeadObjectHandler($this->metadata);

        $httpOne = $this->request('HEAD', '/bucket/object.bin');
        self::assertSame('close', $handler->handleRequest($httpOne)->getHeader('Connection'));

        $httpTwo = $this->request('HEAD', '/bucket/object.bin');
        $httpTwo->setProtocolVersion('2');
        self::assertNull($handler->handleRequest($httpTwo)->getHeader('Connection'));
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

    public function test_get_object_attributes_rejects_missing_selection_header(): void
    {
        $write = $this->hot->putObject('bucket', 'plain.bin', new ReadableBuffer('data'));
        $this->putMetadata('plain.bin', $write->path, $write->size, $write->md5Hex);

        $this->expectException(\OpsFour\S3Server\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('x-amz-object-attributes header is required');

        (new GetObjectAttributesHandler($this->metadata))
            ->handleRequest($this->request('GET', '/bucket/plain.bin?attributes'));
    }

    public function test_get_object_attributes_rejects_unknown_selection(): void
    {
        $write = $this->hot->putObject('bucket', 'plain.bin', new ReadableBuffer('data'));
        $this->putMetadata('plain.bin', $write->path, $write->size, $write->md5Hex);
        $request = $this->request('GET', '/bucket/plain.bin?attributes');
        $request->setHeader('x-amz-object-attributes', 'ETag,Unknown');

        $this->expectException(\OpsFour\S3Server\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid x-amz-object-attributes value');

        (new GetObjectAttributesHandler($this->metadata))->handleRequest($request);
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

    public function test_restore_object_extends_an_existing_restored_copy_without_new_work(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $hotWrite = $this->hot->putObject('bucket', 'archive.bin', new ReadableBuffer('hot-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $oldExpiry = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $hotWrite->path,
            $oldExpiry,
            'STANDARD',
        );

        $response = (new RestoreObjectHandler($this->metadata, $this->tiers))->handleRequest(
            $this->request('POST', '/bucket/archive.bin?restore', '<RestoreRequest><Days>3</Days></RestoreRequest>'),
        );
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertSame(200, $response->getStatus());
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame($hotWrite->path, $object->restoredStoragePath);
        self::assertSame('STANDARD', $object->restoredStorageTier);
        self::assertGreaterThan($oldExpiry, $object->restoreExpiresAt);
        self::assertSame([], $this->metadata->dequeueRestoreJobs(1));
    }

    public function test_restore_object_rejects_unimplemented_retrieval_tiers_before_mutation(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);

        foreach (['Expedited', 'Bulk'] as $tier) {
            try {
                (new RestoreObjectHandler($this->metadata, $this->tiers))->handleRequest(
                    $this->request(
                        'POST',
                        '/bucket/archive.bin?restore',
                        "<RestoreRequest><Days>3</Days><GlacierJobParameters><Tier>{$tier}</Tier></GlacierJobParameters></RestoreRequest>",
                    ),
                );
                self::fail("{$tier} restore must not be accepted without scheduling support.");
            } catch (NotImplementedException $error) {
                self::assertSame(501, $error->getHttpStatus());
            }
        }

        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoreStatus);
        self::assertSame([], $this->metadata->dequeueRestoreJobs(1));
    }

    public function test_restore_object_rejects_duplicate_pending_restore(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $this->expectException(RestoreAlreadyInProgressException::class);

        (new RestoreObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?restore', '<RestoreRequest><Days>3</Days></RestoreRequest>'));
    }

    public function test_restore_object_rejects_missing_explicit_version_without_queuing_work(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $versionId = $this->metadata->putObjectVersioned(
            'bucket',
            'archive.bin',
            'owner',
            $coldWrite->size,
            '"' . $coldWrite->md5Hex . '"',
            'application/octet-stream',
            $coldWrite->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', $versionId, 'GLACIER', 'GLACIER', $coldWrite->path);

        try {
            (new RestoreObjectHandler($this->metadata, $this->tiers))
                ->handleRequest($this->request('POST', '/bucket/archive.bin?restore&versionId=missing', '<RestoreRequest><Days>1</Days></RestoreRequest>'));
            self::fail('An unknown explicit version must not start a restore.');
        } catch (NoSuchVersionException) {
            self::assertSame([], $this->metadata->dequeueRestoreJobs(1));
            self::assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', $versionId)?->restoreStatus);
        }
    }

    public function test_restore_targets_noncurrent_null_version_without_changing_current_version(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('old-cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', 'null', 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->putObjectLegalHold('bucket', 'archive.bin', 'ON', 'null');

        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $currentWrite = $this->hot->putObject('bucket', 'archive.bin', new ReadableBuffer('new-current-data'));
        $currentVersion = $this->metadata->putObjectVersioned('bucket', 'archive.bin', 'owner', $currentWrite->size, '"' . $currentWrite->md5Hex . '"', 'application/octet-stream', $currentWrite->path);

        $response = (new RestoreObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?restore&versionId=null', '<RestoreRequest><Days>1</Days></RestoreRequest>'));
        self::assertSame(202, $response->getStatus());
        $nullVersion = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', 'null');
        $current = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', $currentVersion);
        self::assertNotNull($nullVersion);
        self::assertNotNull($current);
        self::assertSame('pending', $nullVersion->restoreStatus);
        self::assertNull($current->restoreStatus);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], (new RestoreExecutor($this->metadata, $this->tiers))->processNext(1));
        $restoredNull = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', 'null');
        $unchangedCurrent = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', $currentVersion);
        self::assertNotNull($restoredNull);
        self::assertNotNull($unchangedCurrent);
        self::assertSame('restored', $restoredNull->restoreStatus);
        self::assertNotNull($restoredNull->restoredStoragePath);
        self::assertNull($unchangedCurrent->restoredStoragePath);
        self::assertSame('old-cold-data', \Amp\ByteStream\buffer($this->hot->getObjectByPath($restoredNull->restoredStoragePath)));
        self::assertSame('new-current-data', \Amp\ByteStream\buffer($this->hot->getObjectByPath($currentWrite->path)));
        self::assertSame('ON', $this->metadata->getObjectLegalHold('bucket', 'archive.bin', 'null'));
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
