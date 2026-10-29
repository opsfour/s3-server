<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\AppendStream;
use GuzzleHttp\Psr7\Utils;
use Aws\S3\Exception\S3Exception;
use OpsFour\S3Server\Auth\SigningKey;

final class PostObjectTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$bucket !== '') {
            return;
        }

        self::$bucket = 'post-object-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
    }

    public function test_sigv4_browser_form_uses_normal_object_write_path(): void
    {
        $body = 'signed browser upload';
        $response = $this->postV4(
            key: 'signed/${filename}',
            filename: 'hello.txt',
            body: $body,
            extraFields: [
                'success_action_status' => '201',
                'Content-Type' => 'text/plain',
                'x-amz-meta-origin' => 'browser',
                'x-amz-storage-class' => 'STANDARD_IA',
                'tagging' => '<Tagging><TagSet><Tag><Key>project</Key><Value>post-object</Value></Tag></TagSet></Tagging>',
            ],
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('<Key>signed/hello.txt</Key>', (string) $response->getBody());

        $object = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'signed/hello.txt',
        ]);
        $this->assertSame($body, (string) $object['Body']);
        $this->assertSame('text/plain', $object['ContentType']);
        $this->assertSame('browser', $object['Metadata']['origin']);
        $this->assertSame('STANDARD_IA', $object['StorageClass']);
        $attributes = self::$s3->getObjectAttributes([
            'Bucket' => self::$bucket,
            'Key' => 'signed/hello.txt',
            'ObjectAttributes' => ['ObjectParts', 'ObjectSize'],
        ]);
        $this->assertSame((string) strlen($body), $attributes['ObjectSize']);
        $this->assertArrayNotHasKey('ObjectParts', $attributes->toArray());

        $tags = self::$s3->getObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'signed/hello.txt',
        ]);
        $this->assertSame([['Key' => 'project', 'Value' => 'post-object']], $tags['TagSet']);
    }

    public function test_authenticated_browser_post_sets_auth_type_before_preflight_and_commit_recheck(): void
    {
        $prefix = 'auth-type-' . bin2hex(random_bytes(4));
        $keys = [$prefix . '-v4.txt', $prefix . '-v2.txt'];
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $prefix . '-*',
                    'Condition' => ['StringNotEquals' => ['s3:authType' => 'POST']],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $v4 = $this->postV4($keys[0], 'upload.txt', 'authenticated v4 browser upload');
            self::assertSame(204, $v4->getStatusCode(), (string) $v4->getBody());
            self::assertSame('authenticated v4 browser upload', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $keys[0],
            ])['Body']);

            $v2 = $this->postV2($keys[1], 'upload.txt', 'authenticated v2 browser upload');
            self::assertSame(204, $v2->getStatusCode(), (string) $v2->getBody());
            self::assertSame('authenticated v2 browser upload', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $keys[1],
            ])['Body']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            foreach ($keys as $key) {
                self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
            }
        }
    }

    public function test_object_lock_browser_form_requires_and_validates_a_payload_checksum(): void
    {
        $bucket = 'post-lock-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        self::$s3->putObjectLockConfiguration([
            'Bucket' => $bucket,
            'ObjectLockConfiguration' => [
                'ObjectLockEnabled' => 'Enabled',
                'Rule' => ['DefaultRetention' => ['Mode' => 'GOVERNANCE', 'Days' => 1]],
            ],
        ]);
        $body = 'locked browser upload';

        $missing = $this->postV4('missing.txt', 'missing.txt', $body, bucketOverride: $bucket);
        self::assertSame(400, $missing->getStatusCode(), (string) $missing->getBody());
        self::assertStringContainsString('<Code>InvalidRequest</Code>', (string) $missing->getBody());

        $bad = $this->postV4('bad.txt', 'bad.txt', $body, [
            'x-amz-checksum-sha256' => base64_encode(str_repeat("\0", 32)),
        ], bucketOverride: $bucket);
        self::assertSame(400, $bad->getStatusCode(), (string) $bad->getBody());
        self::assertStringContainsString('<Code>BadDigest</Code>', (string) $bad->getBody());

        $valid = $this->postV4('valid.txt', 'valid.txt', $body, [
            'x-amz-checksum-sha256' => base64_encode(hash('sha256', $body, true)),
        ], bucketOverride: $bucket);
        self::assertSame(204, $valid->getStatusCode(), (string) $valid->getBody());
        self::assertSame($body, (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'valid.txt'])['Body']);
        self::assertSame('GOVERNANCE', self::$s3->getObjectRetention([
            'Bucket' => $bucket,
            'Key' => 'valid.txt',
        ])['Retention']['Mode']);

        self::$s3->putObjectLockConfiguration([
            'Bucket' => $bucket,
            'ObjectLockConfiguration' => ['ObjectLockEnabled' => 'Enabled'],
        ]);
        $explicitMissing = $this->postV4('held-missing.txt', 'held-missing.txt', $body, [
            'x-amz-object-lock-legal-hold' => 'ON',
        ], bucketOverride: $bucket);
        self::assertSame(400, $explicitMissing->getStatusCode(), (string) $explicitMissing->getBody());
        self::assertStringContainsString('<Code>InvalidRequest</Code>', (string) $explicitMissing->getBody());

        $held = $this->postV4('held.txt', 'held.txt', $body, [
            'x-amz-object-lock-legal-hold' => 'ON',
            'Content-MD5' => base64_encode(md5($body, true)),
        ], bucketOverride: $bucket);
        self::assertSame(204, $held->getStatusCode(), (string) $held->getBody());
        self::assertSame('ON', self::$s3->getObjectLegalHold([
            'Bucket' => $bucket,
            'Key' => 'held.txt',
        ])['LegalHold']['Status']);
    }

    public function test_signed_browser_form_rejects_unsigned_object_headers(): void
    {
        foreach ([
            'acl' => ['x-amz-acl' => 'public-read'],
            'metadata' => ['x-amz-meta-injected' => 'unsigned'],
            'cache-control' => ['Cache-Control' => 'public, max-age=86400'],
        ] as $case => $headers) {
            $key = 'unsigned-http-' . $case . '-' . bin2hex(random_bytes(4));
            $client = new Client([
                'http_errors' => false,
                'timeout' => 10,
                'headers' => $headers,
            ]);

            $response = $this->postV4($key, 'upload.txt', 'signed payload', client: $client);

            self::assertSame(403, $response->getStatusCode(), $case . ': ' . (string) $response->getBody());
            self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody(), $case);
            self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? [], $case);
        }
    }

    public function test_virtual_host_browser_post_requires_valid_bucket_host_signature_and_policy(): void
    {
        $previousBaseDomain = getenv('S3_BASE_DOMAIN');
        putenv('S3_BASE_DOMAIN=s3.localhost');

        try {
            self::restartServer();
            $suffix = bin2hex(random_bytes(4));
            $acceptedKey = 'virtual-accepted-' . $suffix;
            $accepted = $this->postV4($acceptedKey, 'upload.txt', 'virtual payload', virtualHost: true);
            self::assertSame(204, $accepted->getStatusCode(), (string) $accepted->getBody());
            self::assertSame('virtual payload', (string) self::$s3->getObject([
                'Bucket' => self::$bucket, 'Key' => $acceptedKey,
            ])['Body']);

            $badSignatureKey = 'virtual-bad-signature-' . $suffix;
            $badSignature = $this->postV4($badSignatureKey, 'upload.txt', 'denied', invalidSignature: true, virtualHost: true);
            self::assertSame(403, $badSignature->getStatusCode(), (string) $badSignature->getBody());
            self::assertStringContainsString('<Code>SignatureDoesNotMatch</Code>', (string) $badSignature->getBody());

            $invalidHost = (new Client(['http_errors' => false]))->request(
                'POST',
                sprintf('http://%s:%d/', self::$host, self::$port),
                [
                    'headers' => ['Host' => 'not-a-bucket.example.test'],
                    'multipart' => [
                        ['name' => 'key', 'contents' => 'virtual-invalid-host-' . $suffix],
                        ['name' => 'file', 'contents' => 'denied', 'filename' => 'upload.txt'],
                    ],
                ],
            );
            self::assertSame(405, $invalidHost->getStatusCode(), (string) $invalidHost->getBody());

            $deniedKey = 'virtual-policy-denied-' . $suffix;
            self::$s3->putBucketPolicy([
                'Bucket' => self::$bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:PutObject',
                        'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $deniedKey,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            try {
                $denied = $this->postV4($deniedKey, 'upload.txt', 'denied', virtualHost: true);
                self::assertSame(403, $denied->getStatusCode(), (string) $denied->getBody());
                self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $denied->getBody());
                foreach ([$badSignatureKey, $deniedKey, 'virtual-invalid-host-' . $suffix] as $key) {
                    self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
                }
            } finally {
                self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            }
        } finally {
            putenv($previousBaseDomain === false ? 'S3_BASE_DOMAIN' : 'S3_BASE_DOMAIN=' . $previousBaseDomain);
            self::restartServer();
        }
    }

    public function test_signed_post_preserves_false_boundary_prefix_inside_file(): void
    {
        $boundary = 'post-false-boundary';
        $body = "before\r\n--{$boundary}XXXX" . str_repeat('a', 20_000) . 'after';
        $key = 'false-boundary-' . bin2hex(random_bytes(4));

        $response = $this->postV4($key, 'upload.txt', $body, boundary: $boundary);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $object = self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key]);
        self::assertSame($body, (string) $object['Body']);
        self::assertSame(strlen($body), (int) $object['ContentLength']);
        self::assertSame('"' . md5($body) . '"', (string) $object['ETag']);
    }

    public function test_signed_post_rejects_total_multipart_body_above_server_limit(): void
    {
        $previousLimit = getenv('S3_BODY_SIZE_LIMIT');
        putenv('S3_BODY_SIZE_LIMIT=1024');
        try {
            self::restartServer();
            $response = $this->postV4('over-body-limit', 'upload.txt', str_repeat('x', 2_048));
            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody() . "\n" . self::serverLogs());
            self::assertStringContainsString('<Code>EntityTooLarge</Code>', (string) $response->getBody());
        } finally {
            putenv($previousLimit === false ? 'S3_BODY_SIZE_LIMIT' : 'S3_BODY_SIZE_LIMIT=' . $previousLimit);
            self::restartServer();
        }
    }

    public function test_signed_post_epilog_allows_next_request_on_same_http_connection(): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 10]);
        $key = 'epilog-' . bin2hex(random_bytes(4));
        $firstStats = [];
        $response = $this->postV4(
            $key,
            'upload.txt',
            'payload',
            ['success_action_status' => '201'],
            boundary: 'post-epilog-boundary',
            epilog: "ignored epilog\r\n",
            client: $client,
            onStats: static function (\GuzzleHttp\TransferStats $stats) use (&$firstStats): void {
                $firstStats = $stats->getHandlerStats();
            },
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $secondStats = [];
        $health = $client->request('GET', sprintf('http://%s:%d/.live', self::$host, self::$port), [
            'on_stats' => static function (\GuzzleHttp\TransferStats $stats) use (&$secondStats): void {
                $secondStats = $stats->getHandlerStats();
            },
        ]);
        self::assertSame(200, $health->getStatusCode());
        self::assertIsInt($firstStats['local_port'] ?? null);
        self::assertSame($firstStats['local_port'] ?? null, $secondStats['local_port'] ?? null);

        $object = self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key]);
        self::assertSame('payload', (string) $object['Body']);
    }

    public function test_browser_post_sse_s3_roundtrips_through_encrypted_spool(): void
    {
        $previousMasterKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('B', 32)));

        try {
            self::restartServer();
            $key = 'encrypted-form-' . bin2hex(random_bytes(4));
            $response = $this->postV4($key, 'upload.txt', 'browser SSE payload', [
                'x-amz-server-side-encryption' => 'AES256',
            ]);
            self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
            $object = self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key]);
            self::assertSame('browser SSE payload', (string) $object['Body']);
            self::assertSame('AES256', $object['ServerSideEncryption']);
        } finally {
            putenv($previousMasterKey === false
                ? 'S3_ENCRYPTION_MASTER_KEY'
                : 'S3_ENCRYPTION_MASTER_KEY=' . $previousMasterKey);
            self::restartServer();
        }
    }

    public function test_signed_browser_post_returns_literal_null_version_header_when_suspended(): void
    {
        $bucket = 'post-null-version-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $unversioned = $this->postV4('plain.txt', 'plain.txt', 'plain', bucketOverride: $bucket);
            self::assertSame(204, $unversioned->getStatusCode());
            self::assertSame('', $unversioned->getHeaderLine('x-amz-version-id'));

            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
            $suspended = $this->postV4('suspended.txt', 'suspended.txt', 'suspended', [
                'success_action_status' => '201',
            ], bucketOverride: $bucket);
            self::assertSame(201, $suspended->getStatusCode(), (string) $suspended->getBody());
            self::assertSame('null', $suspended->getHeaderLine('x-amz-version-id'));
            self::assertSame('suspended', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'suspended.txt', 'VersionId' => 'null',
            ])['Body']);

            $redirect = $this->postV4('redirect.txt', 'redirect.txt', 'redirected', [
                'success_action_redirect' => 'https://example.test/complete',
            ], bucketOverride: $bucket);
            self::assertSame(303, $redirect->getStatusCode());
            self::assertSame('null', $redirect->getHeaderLine('x-amz-version-id'));
        } finally {
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $bucket, 'Key' => $version['Key'], 'VersionId' => $version['VersionId'],
                ]);
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_browser_form_acl_obeys_bucket_owner_enforced_mode(): void
    {
        self::$s3->putBucketOwnershipControls([
            'Bucket' => self::$bucket,
            'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
        ]);
        try {
            $rejectedKey = 'enforced-rejected-' . bin2hex(random_bytes(4)) . '.txt';
            $rejected = $this->postV4($rejectedKey, 'upload.txt', 'denied', ['acl' => 'public-read']);
            $this->assertSame(400, $rejected->getStatusCode());
            $this->assertStringContainsString('<Code>AccessControlListNotSupported</Code>', (string) $rejected->getBody());
            $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $rejectedKey])['Contents'] ?? []);

            $acceptedKey = 'enforced-accepted-' . bin2hex(random_bytes(4)) . '.txt';
            $accepted = $this->postV4($acceptedKey, 'upload.txt', 'allowed', ['acl' => 'bucket-owner-full-control']);
            $this->assertSame(204, $accepted->getStatusCode(), (string) $accepted->getBody());
            $this->assertSame('allowed', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $acceptedKey])['Body']);
        } finally {
            self::$s3->deleteBucketOwnershipControls(['Bucket' => self::$bucket]);
        }
    }

    public function test_anonymous_form_is_denied_for_private_bucket(): void
    {
        $response = $this->postAnonymous('anonymous/denied.txt', str_repeat('x', 1_048_576));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
    }

    public function test_unauthenticated_oversized_part_header_returns_http_400_without_a_spool(): void
    {
        $previousMemoryLimit = getenv('S3_TEST_SERVER_MEMORY_LIMIT');
        $previousStorageTempDir = getenv('S3_STORAGE_TEMP_DIR');
        $tempDir = self::$storagePath . '/post-header-temp-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tempDir, 0o700));
        putenv('S3_TEST_SERVER_MEMORY_LIMIT=128M');
        putenv('S3_STORAGE_TEMP_DIR=' . $tempDir);

        try {
            self::restartServer();
            self::assertDirectoryExists($tempDir . '/.opsfour-s3-storage-budget');

            $boundary = 'post-header-limit';
            $response = (new Client(['http_errors' => false, 'timeout' => 10]))->request(
                'POST',
                sprintf('http://%s:%d/%s', self::$host, self::$port, self::$bucket),
                [
                    'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                    'body' => "--{$boundary}\r\nX-Long: " . str_repeat('a', 20_000),
                ],
            );

            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
            self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
            self::assertStringContainsString('Multipart part header exceeds 16 KiB.', (string) $response->getBody());
            self::assertSame([], glob($tempDir . '/s3-post-*') ?: []);
            self::assertNotNull(self::serverPid());
        } finally {
            putenv($previousMemoryLimit === false
                ? 'S3_TEST_SERVER_MEMORY_LIMIT'
                : 'S3_TEST_SERVER_MEMORY_LIMIT=' . $previousMemoryLimit);
            putenv($previousStorageTempDir === false
                ? 'S3_STORAGE_TEMP_DIR'
                : 'S3_STORAGE_TEMP_DIR=' . $previousStorageTempDir);
            self::restartServer();
        }
    }

    public function test_browser_post_spool_budget_returns_retryable_error_and_recovers(): void
    {
        $previousBudget = getenv('S3_POST_SPOOL_BUDGET');
        // One authenticated frame uses 32 bytes beyond the file payload.
        putenv('S3_POST_SPOOL_BUDGET=35');
        try {
            self::restartServer();
            $rejectedKey = 'spool-budget-rejected-' . bin2hex(random_bytes(4));
            $rejected = $this->postV4($rejectedKey, 'upload.txt', 'abcd');
            self::assertSame(503, $rejected->getStatusCode());
            self::assertStringContainsString('<Code>SlowDown</Code>', (string) $rejected->getBody());
            self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $rejectedKey])['Contents'] ?? []);

            $acceptedKey = 'spool-budget-accepted-' . bin2hex(random_bytes(4));
            $accepted = $this->postV4($acceptedKey, 'upload.txt', 'ok');
            self::assertSame(204, $accepted->getStatusCode(), (string) $accepted->getBody());
            self::assertSame('ok', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $acceptedKey])['Body']);
        } finally {
            if ($previousBudget === false) {
                putenv('S3_POST_SPOOL_BUDGET');
            } else {
                putenv('S3_POST_SPOOL_BUDGET=' . $previousBudget);
            }
            self::restartServer();
        }
    }

    public function test_invalid_storage_class_is_rejected_for_put_copy_multipart_and_form_uploads(): void
    {
        $prefix = 'invalid-storage-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => "{$prefix}/source", 'Body' => 'source']);

        $requests = [
            ['PutObject', [
                'Bucket' => self::$bucket, 'Key' => "{$prefix}/put", 'Body' => 'body', 'StorageClass' => 'NOT_A_CLASS',
            ]],
            ['CopyObject', [
                'Bucket' => self::$bucket, 'Key' => "{$prefix}/copy",
                'CopySource' => self::$bucket . "/{$prefix}/source", 'StorageClass' => 'NOT_A_CLASS',
            ]],
            ['CreateMultipartUpload', [
                'Bucket' => self::$bucket, 'Key' => "{$prefix}/multipart", 'StorageClass' => 'NOT_A_CLASS',
            ]],
        ];
        foreach ($requests as [$operation, $arguments]) {
            try {
                self::$s3->execute(self::$s3->getCommand($operation, $arguments));
                self::fail('An unsupported storage class must fail before creating an object or upload.');
            } catch (S3Exception $e) {
                self::assertSame(400, $e->getStatusCode());
                self::assertSame('InvalidArgument', $e->getAwsErrorCode());
            }
        }

        $form = $this->postV4("{$prefix}/form", 'file.txt', 'body', ['x-amz-storage-class' => 'NOT_A_CLASS']);
        self::assertSame(400, $form->getStatusCode());
        self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $form->getBody());
        $lockForm = $this->postV4("{$prefix}/lock", 'file.txt', 'body', ['x-amz-object-lock-mode' => 'GOVERNANCE']);
        self::assertSame(400, $lockForm->getStatusCode());
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => "{$prefix}/put"])['Contents'] ?? []);
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => "{$prefix}/copy"])['Contents'] ?? []);
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => "{$prefix}/form"])['Contents'] ?? []);
    }

    public function test_sigv4_form_rejects_impossible_calendar_date(): void
    {
        $response = $this->postV4(
            key: 'invalid-date.txt',
            filename: 'invalid-date.txt',
            body: 'must not be written',
            dateTime: '20260231T120000Z',
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
    }

    public function test_sigv4_form_rejects_trailing_newline_in_date(): void
    {
        $key = 'invalid-newline-date-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'invalid-date.txt',
            body: 'must not be written',
            dateTime: gmdate('Ymd\THis\Z') . "\n",
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_sigv4_form_rejects_a_signing_key_older_than_seven_days(): void
    {
        $key = 'stale-signing-key-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'stale.txt',
            body: 'must not be written',
            dateTime: gmdate('Ymd\THis\Z', time() - 8 * 86400),
            policyExpiration: gmdate('Y-m-d\TH:i:s\Z', time() + 300),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_bad_form_signature_rejects_large_file_without_creating_object(): void
    {
        $key = 'invalid-signature-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: str_repeat('x', 1_048_576),
            invalidSignature: true,
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>SignatureDoesNotMatch</Code>', (string) $response->getBody());
        $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_sigv4_form_rejects_impossible_policy_expiration(): void
    {
        $response = $this->postV4(
            key: 'invalid-policy-expiration.txt',
            filename: 'invalid-policy-expiration.txt',
            body: 'must not be written',
            policyExpiration: '2030-02-31T12:00:00.000Z',
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
    }

    public function test_sigv4_form_rejects_policy_expiration_with_trailing_data(): void
    {
        $response = $this->postV4(
            key: 'invalid-policy-trailer.txt',
            filename: 'invalid-policy-trailer.txt',
            body: 'must not be written',
            policyExpiration: gmdate('Y-m-d\TH:i:s\Z', time() + 300) . ' trailing-data',
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
    }

    public function test_sigv4_form_rejects_invalid_size_range(): void
    {
        $key = 'invalid-policy-range-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: str_repeat('x', 1_048_576),
            additionalConditions: [['content-length-range', 10, 1]],
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
        $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_sigv4_form_rejects_unsatisfied_field_condition(): void
    {
        $key = 'invalid-policy-field-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: str_repeat('x', 1_048_576),
            additionalConditions: [['eq', '$key', 'different-key.txt']],
        );

        $this->assertSame(403, $response->getStatusCode());
        $xml = new \SimpleXMLElement((string) $response->getBody());
        $this->assertSame('AccessDenied', (string) $xml->Code);
        $this->assertSame('/' . self::$bucket, (string) $xml->Resource);
        $this->assertSame($response->getHeaderLine('x-amz-request-id'), (string) $xml->RequestId);
        $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_content_type_list_must_satisfy_starts_with_for_every_value(): void
    {
        $key = 'mixed-content-types-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: 'must not be written',
            extraFields: ['Content-Type' => 'image/jpeg,text/plain'],
            additionalConditions: [['starts-with', '$Content-Type', 'image/']],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
        self::assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_sigv4_form_rejects_field_not_covered_by_policy(): void
    {
        $key = 'uncovered-policy-field-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: str_repeat('x', 1_048_576),
            uncoveredFields: ['x-amz-meta-uncovered' => 'value'],
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
        $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key])['Contents'] ?? []);
    }

    public function test_expired_signed_policy_and_size_limit_reject_large_form_files(): void
    {
        $expiredKey = 'expired-policy-' . bin2hex(random_bytes(4)) . '.txt';
        $expired = $this->postV4(
            key: $expiredKey,
            filename: 'upload.txt',
            body: str_repeat('x', 1_048_576),
            policyExpiration: gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        );
        $this->assertSame(403, $expired->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $expired->getBody());

        $oversizeKey = 'oversize-policy-' . bin2hex(random_bytes(4)) . '.txt';
        $oversize = $this->postV4(
            key: $oversizeKey,
            filename: 'upload.txt',
            body: str_repeat('x', 1_048_576),
            additionalConditions: [['content-length-range', 0, 10]],
        );
        $this->assertSame(400, $oversize->getStatusCode());
        $this->assertStringContainsString('<Code>EntityTooLarge</Code>', (string) $oversize->getBody());
        $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $expiredKey])['Contents'] ?? []);
        $this->assertSame([], self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $oversizeKey])['Contents'] ?? []);

        $acceptedKey = 'within-policy-' . bin2hex(random_bytes(4)) . '.txt';
        $accepted = $this->postV4(
            key: $acceptedKey,
            filename: 'upload.txt',
            body: '0123456789',
            additionalConditions: [['content-length-range', 0, 10]],
        );
        $this->assertSame(204, $accepted->getStatusCode(), (string) $accepted->getBody());
        $this->assertSame('0123456789', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $acceptedKey])['Body']);
    }

    public function test_invalid_success_redirect_uses_success_status_without_redirecting(): void
    {
        foreach (['javascript:alert(1)', 'http:/relative', "https://example.test/\tbad"] as $redirect) {
            $key = 'invalid-redirect-' . bin2hex(random_bytes(4)) . '.txt';
            $response = $this->postV4(
                key: $key,
                filename: 'upload.txt',
                body: 'uploaded without redirect',
                extraFields: ['success_action_redirect' => $redirect],
            );

            $this->assertSame(204, $response->getStatusCode(), $redirect);
            $this->assertSame('', $response->getHeaderLine('Location'));
            $this->assertSame('uploaded without redirect', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key])['Body']);
        }
    }

    public function test_valid_success_redirect_is_returned_after_object_write(): void
    {
        $key = 'valid-redirect-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: 'uploaded',
            extraFields: ['success_action_redirect' => 'https://example.test/upload?source=form#section'],
        );

        $this->assertSame(303, $response->getStatusCode());
        $this->assertStringStartsWith(
            'https://example.test/upload?source=form&bucket=' . self::$bucket . '&key=' . $key,
            $response->getHeaderLine('Location'),
        );
        $this->assertStringEndsWith('#section', $response->getHeaderLine('Location'));
        $this->assertSame('uploaded', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key])['Body']);
    }

    public function test_legacy_redirect_field_is_returned_after_object_write(): void
    {
        $key = 'legacy-redirect-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: 'uploaded',
            extraFields: ['redirect' => 'https://example.test/upload'],
        );

        $this->assertSame(303, $response->getStatusCode());
        $this->assertStringStartsWith(
            'https://example.test/upload?bucket=' . self::$bucket . '&key=' . $key,
            $response->getHeaderLine('Location'),
        );
        $this->assertSame('uploaded', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key])['Body']);
    }

    public function test_uninterpretable_redirect_falls_back_to_requested_success_status(): void
    {
        $key = 'fallback-redirect-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: 'uploaded',
            extraFields: [
                'success_action_redirect' => 'javascript:alert(1)',
                'success_action_status' => '201',
            ],
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('<PostResponse>', (string) $response->getBody());
        $this->assertSame('uploaded', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key])['Body']);
    }

    public function test_tagged_form_respects_put_object_tagging_deny(): void
    {
        $key = 'tagged-denied-' . bin2hex(random_bytes(4)) . '.txt';
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObjectTagging',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $response = $this->postV4(
                key: $key,
                filename: 'upload.txt',
                body: str_repeat('x', 1_048_576),
                extraFields: [
                    'tagging' => '<Tagging><TagSet><Tag><Key>project</Key><Value>private</Value></Tag></TagSet></Tagging>',
                ],
            );
            $this->assertSame(403, $response->getStatusCode());
            $objects = self::$s3->listObjectsV2(['Bucket' => self::$bucket, 'Prefix' => $key]);
            $this->assertSame([], $objects['Contents'] ?? []);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_tagging_xml_preserves_values_and_policy_condition_context(): void
    {
        $key = 'tagging-xml-' . bin2hex(random_bytes(4)) . '.txt';
        $tagging = '<Tagging xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<TagSet>'
            . '<Tag><Key>project name</Key><Value>opsfour + storage/@</Value></Tag>'
            . '<Tag><Key>empty</Key><Value/></Tag>'
            . '</TagSet></Tagging>';

        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: 'tagged payload',
            extraFields: ['tagging' => $tagging],
        );

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('tagged payload', (string) self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['Body']);
        self::assertSame([
            ['Key' => 'project name', 'Value' => 'opsfour + storage/@'],
            ['Key' => 'empty', 'Value' => ''],
        ], self::$s3->getObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['TagSet']);
    }

    public function test_tagging_xml_populates_request_object_tag_policy_conditions(): void
    {
        $prefix = 'tagging-condition-' . bin2hex(random_bytes(4));
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $prefix . '/*',
                    'Condition' => [
                        'StringNotEquals' => ['s3:RequestObjectTag/project' => 'approved'],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $acceptedKey = $prefix . '/accepted.txt';
            $accepted = $this->postV4($acceptedKey, 'upload.txt', 'accepted', [
                'tagging' => '<Tagging><TagSet><Tag><Key>project</Key><Value>approved</Value></Tag></TagSet></Tagging>',
            ]);
            self::assertSame(204, $accepted->getStatusCode(), (string) $accepted->getBody());

            $deniedKey = $prefix . '/denied.txt';
            $denied = $this->postV4($deniedKey, 'upload.txt', str_repeat('denied', 131_072), [
                'tagging' => '<Tagging><TagSet><Tag><Key>project</Key><Value>private</Value></Tag></TagSet></Tagging>',
            ]);
            self::assertSame(403, $denied->getStatusCode(), (string) $denied->getBody());
            self::assertSame([], self::$s3->listObjectsV2([
                'Bucket' => self::$bucket,
                'Prefix' => $deniedKey,
            ])['Contents'] ?? []);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    /** @param string $tagging */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBrowserPostTagging')]
    public function test_invalid_tagging_field_fails_before_object_publication(string $tagging): void
    {
        $key = 'invalid-tagging-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->postV4(
            key: $key,
            filename: 'upload.txt',
            body: str_repeat('must not be published', 65_536),
            extraFields: ['tagging' => $tagging],
        );

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => $key,
        ])['Contents'] ?? []);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBrowserPostTagging(): iterable
    {
        yield 'private query format' => ['project=private'];
        yield 'malformed XML' => ['<Tagging><TagSet>'];
        yield 'invalid S3 tag character' => [
            '<Tagging><TagSet><Tag><Key>project*</Key><Value>private</Value></Tag></TagSet></Tagging>',
        ];
    }

    public function test_invalid_utf8_tagging_field_fails_before_object_publication(): void
    {
        $key = 'invalid-tagging-utf8-' . bin2hex(random_bytes(4)) . '.txt';
        $response = $this->sendMultipart([
            'key' => $key,
            'tagging' => "<Tagging><TagSet><Tag><Key>project</Key><Value>\xC3\x28</Value></Tag></TagSet></Tagging>",
        ], 'upload.txt', str_repeat('must not be published', 65_536));

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => $key,
        ])['Contents'] ?? []);
    }

    public function test_anonymous_form_is_allowed_by_public_write_acl(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'public-read-write',
        ]);

        try {
            self::$s3->putBucketPolicy([
                'Bucket' => self::$bucket,
                'Policy' => json_encode([
                    'Statement' => [[
                        'Effect' => 'Deny',
                        'Principal' => '*',
                        'Action' => 's3:PutObject',
                        'Resource' => 'arn:aws:s3:::' . self::$bucket . '/anonymous/allowed.txt',
                        'Condition' => ['Null' => ['s3:authType' => 'false']],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            $response = $this->postAnonymous('anonymous/allowed.txt', 'public write');
            $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

            $object = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'anonymous/allowed.txt',
            ]);
            $this->assertSame('public write', (string) $object['Body']);
        } finally {
            self::$s3->putBucketAcl([
                'Bucket' => self::$bucket,
                'ACL' => 'private',
            ]);
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_large_sigv4_form_upload_round_trips_without_heap_buffering(): void
    {
        $bytes = (int) (getenv('S3_TEST_POST_OBJECT_BYTES') ?: 8 * 1024 * 1024);
        $resource = fopen('php://temp/maxmemory:1048576', 'w+b');
        $this->assertIsResource($resource);

        $chunk = str_repeat('post-object-stream-', 4096);
        $remaining = $bytes;
        $hash = hash_init('sha256');
        while ($remaining > 0) {
            $data = substr($chunk, 0, min(strlen($chunk), $remaining));
            fwrite($resource, $data);
            hash_update($hash, $data);
            $remaining -= strlen($data);
        }
        rewind($resource);
        $expectedHash = hash_final($hash);

        try {
            $response = $this->postV4(
                key: 'large/${filename}',
                filename: 'large.bin',
                body: $resource,
                extraFields: ['Content-Type' => 'application/octet-stream'],
            );
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $object = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'large/large.bin',
        ]);
        $this->assertSame($bytes, (int) $object['ContentLength']);
        $this->assertSame($expectedHash, hash('sha256', (string) $object['Body']));
    }

    /**
     * @param resource|string $body
     * @param array<string, string> $extraFields
     * @param list<list<int|string>> $additionalConditions
     * @param array<string, string> $uncoveredFields
     */
    private function postV4(
        string $key,
        string $filename,
        mixed $body,
        array $extraFields = [],
        ?string $dateTime = null,
        ?string $policyExpiration = null,
        bool $invalidSignature = false,
        array $additionalConditions = [],
        array $uncoveredFields = [],
        ?string $bucketOverride = null,
        ?string $boundary = null,
        ?string $epilog = null,
        ?Client $client = null,
        ?\Closure $onStats = null,
        bool $virtualHost = false,
    ): \Psr\Http\Message\ResponseInterface {
        $bucket = $bucketOverride ?? self::$bucket;
        $dateTime ??= gmdate('Ymd\THis\Z');
        $date = substr($dateTime, 0, 8);
        $credential = self::$accessKey . "/{$date}/us-east-1/s3/aws4_request";

        $fields = [
            'key' => $key,
            'x-amz-algorithm' => 'AWS4-HMAC-SHA256',
            'x-amz-credential' => $credential,
            'x-amz-date' => $dateTime,
            ...$extraFields,
        ];

        $conditions = [
            ['bucket' => $bucket],
            ['starts-with', '$key', strstr($key, '${filename}', true) ?: $key],
        ];
        foreach ($fields as $name => $value) {
            if ($name === 'key') {
                continue;
            }
            $conditions[] = [$name => $value];
        }
        array_push($conditions, ...$additionalConditions);

        $policy = base64_encode(json_encode([
            'expiration' => $policyExpiration ?? gmdate('Y-m-d\TH:i:s\Z', time() + 300),
            'conditions' => $conditions,
        ], JSON_THROW_ON_ERROR));
        $signature = hash_hmac(
            'sha256',
            $policy,
            SigningKey::derive(self::$secretKey, $date, 'us-east-1', 's3'),
        );

        $fields['policy'] = $policy;
        $fields['x-amz-signature'] = $invalidSignature ? str_repeat('0', 64) : $signature;
        $fields = [...$fields, ...$uncoveredFields];

        return $this->sendMultipart($fields, $filename, $body, $bucket, $boundary, $epilog, $client, $onStats, $virtualHost);
    }

    private function postAnonymous(string $key, string $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->sendMultipart(['key' => $key], 'upload.txt', $body);
    }

    private function postV2(string $key, string $filename, string $body): \Psr\Http\Message\ResponseInterface
    {
        $policy = base64_encode(json_encode([
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
            'conditions' => [
                ['bucket' => self::$bucket],
                ['key' => $key],
                ['AWSAccessKeyId' => self::$accessKey],
            ],
        ], JSON_THROW_ON_ERROR));

        return $this->sendMultipart([
            'key' => $key,
            'AWSAccessKeyId' => self::$accessKey,
            'policy' => $policy,
            'signature' => base64_encode(hash_hmac('sha1', $policy, self::$secretKey, true)),
        ], $filename, $body);
    }

    /**
     * @param array<string, string> $fields
     * @param resource|string $body
     */
    private function sendMultipart(
        array $fields,
        string $filename,
        mixed $body,
        ?string $bucket = null,
        ?string $boundary = null,
        ?string $epilog = null,
        ?Client $client = null,
        ?\Closure $onStats = null,
        bool $virtualHost = false,
    ): \Psr\Http\Message\ResponseInterface {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        $multipart[] = [
            'name' => 'file',
            'contents' => $body,
            'filename' => $filename,
        ];

        $customBody = $boundary === null ? null : new MultipartStream($multipart, $boundary);
        if ($customBody !== null && $epilog !== null) {
            $customBody = new AppendStream([$customBody, Utils::streamFor($epilog)]);
        }
        $options = $boundary === null
            ? ['multipart' => $multipart, 'allow_redirects' => false]
            : [
                'body' => $customBody,
                'headers' => [
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    'Connection' => 'keep-alive',
                ],
                'allow_redirects' => false,
            ];
        if ($onStats !== null) {
            $options['on_stats'] = $onStats;
        }
        if ($virtualHost) {
            $options['headers']['Host'] = ($bucket ?? self::$bucket) . '.s3.localhost';
        }

        return ($client ?? new Client([
            'http_errors' => false,
            'timeout' => 60,
        ]))->request(
            'POST',
            sprintf('http://%s:%d/%s', self::$host, self::$port, $virtualHost ? '' : ($bucket ?? self::$bucket)),
            $options,
        );
    }
}
