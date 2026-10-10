<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use Symfony\Component\Process\Process;

/** Two real HTTP processes sharing a disposable database and filesystem. */
final class MultiNodeMetadataFunctionalTest extends S3FunctionalTestCase
{
    private static ?Process $secondServer = null;

    private static ?S3Client $secondClient = null;

    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    public static function setUpBeforeClass(): void
    {
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        if ($driver === false || $dsn === false || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_MULTINODE_METADATA_DRIVER and S3_TEST_MULTINODE_METADATA_DSN to a disposable database.');
        }
        if (! in_array($driver, ['postgres', 'mysql'], true)) {
            throw new \InvalidArgumentException('The multi-node profile supports postgres and mysql.');
        }
        if (preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn) !== 1) {
            throw new \InvalidArgumentException('The multi-node profile requires a disposable dbname starting with s3audit_.');
        }

        $backgroundRows = getenv('S3_TEST_MULTINODE_BACKGROUND_ROWS');
        if ($backgroundRows !== false) {
            if (getenv('S3_TEST_DESTRUCTIVE_STARTUP_FIXTURE') !== '1'
                || preg_match('/^[1-9][0-9]*$/', $backgroundRows) !== 1
                || (int) $backgroundRows > 2_000_000) {
                throw new \InvalidArgumentException('Background metadata requires the destructive fixture flag and 1-2,000,000 rows.');
            }
        }

        // API processes only validate the schema. Bootstrap this disposable
        // database once before either node is allowed to start.
        $bootstrap = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        if ($backgroundRows !== false) {
            self::seedBackgroundMetadata($bootstrap, $driver, $dsn, (int) $backgroundRows);
        }

        foreach ([
            'S3_METADATA_DRIVER' => $driver,
            'S3_METADATA_DSN' => $dsn,
            'S3_OWNER_ID' => 'multinode-' . bin2hex(random_bytes(4)),
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '100',
            'S3_NOTIFICATION_WORKER' => '0',
            'S3_ENCRYPTION_MASTER_KEY' => base64_encode(str_repeat('M', 32)),
        ] as $name => $value) {
            self::$previousEnv[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            parent::setUpBeforeClass();

            $socket = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($socket);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            self::assertIsString($address);
            $port = (int) substr($address, strrpos($address, ':') + 1);

            self::$secondServer = new Process([
                PHP_BINARY,
                dirname(__DIR__, 2) . '/bin/s3-server',
                '--host=127.0.0.1',
                '--port=' . $port,
                '--storage-driver=filesystem',
                '--storage-path=' . self::$storagePath,
                '--metadata-driver=' . $driver,
                '--metadata-dsn=' . $dsn,
                '--access-key=' . self::$accessKey,
                '--secret-key=' . self::$secretKey,
                '--owner-id=' . getenv('S3_OWNER_ID'),
                '--quota-max-bytes-per-owner=100',
            ], dirname(__DIR__, 2), [
                'S3_ENCRYPTION_MASTER_KEY' => (string) getenv('S3_ENCRYPTION_MASTER_KEY'),
            ]);
            self::$secondServer->setTimeout(null);
            self::$secondServer->start();

            $startedAt = microtime(true);
            $deadline = microtime(true) + self::serverStartTimeoutMs() / 1000;
            do {
                if (! self::$secondServer->isRunning()) {
                    self::fail('Second S3 server exited: ' . self::$secondServer->getErrorOutput());
                }
                $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    if (getenv('S3_TEST_REPORT_STARTUP_TIME') === '1') {
                        fwrite(STDERR, sprintf("Second S3 HTTP node ready in %.3f seconds.\n", microtime(true) - $startedAt));
                    }
                    break;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
            self::assertNotFalse($connection, 'Second S3 server did not become ready: ' . self::$secondServer->getErrorOutput());

            self::$secondClient = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => 'http://127.0.0.1:' . $port,
                'use_path_style_endpoint' => true,
                'retries' => 0,
                'credentials' => ['key' => self::$accessKey, 'secret' => self::$secretKey],
            ]);
        } catch (\Throwable $error) {
            self::$secondServer?->stop(5);
            parent::tearDownAfterClass();
            self::restoreEnv();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$secondServer?->stop(5);
        self::$secondServer = null;
        self::$secondClient = null;
        parent::tearDownAfterClass();
        self::restoreEnv();
    }

    public function test_mode_change_and_quota_race_are_consistent_across_two_http_nodes(): void
    {
        $second = self::$secondClient;
        self::assertNotNull($second);
        $bucket = 'multi-node-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::assertSame('ObjectWriter', $second->getBucketOwnershipControls(['Bucket' => $bucket])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
            $anonymous = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => (string) $second->getEndpoint(),
                'use_path_style_endpoint' => true,
                'retries' => 0,
                'credentials' => false,
            ]);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'public', 'Body' => 'old-public', 'ACL' => 'public-read']);
            self::assertSame('old-public', (string) $anonymous->getObject(['Bucket' => $bucket, 'Key' => 'public'])['Body']);
            self::$s3->putBucketOwnershipControls([
                'Bucket' => $bucket,
                'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
            ]);
            self::assertSame('BucketOwnerEnforced', $second->getBucketOwnershipControls(['Bucket' => $bucket])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
            try {
                $anonymous->getObject(['Bucket' => $bucket, 'Key' => 'public']);
                self::fail('The old public ACL must stop granting access on the other node.');
            } catch (S3Exception $error) {
                self::assertSame('AccessDenied', $error->getAwsErrorCode());
            }
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'public']);

            $second->putObject(['Bucket' => $bucket, 'Key' => 'visible', 'Body' => 'from-node-two']);
            self::assertSame('from-node-two', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'visible'])['Body']);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'visible']);

            $settled = Utils::settle([
                self::$s3->putObjectAsync(['Bucket' => $bucket, 'Key' => 'first', 'Body' => str_repeat('a', 60)]),
                $second->putObjectAsync(['Bucket' => $bucket, 'Key' => 'second', 'Body' => str_repeat('b', 60)]),
            ])->wait();
            $states = array_column($settled, 'state');
            sort($states);
            self::assertSame(['fulfilled', 'rejected'], $states);
            foreach ($settled as $result) {
                if ($result['state'] === 'rejected') {
                    self::assertInstanceOf(S3Exception::class, $result['reason']);
                    self::assertSame('QuotaExceeded', $result['reason']->getAwsErrorCode());
                }
            }

            $objects = self::$s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [];
            self::assertCount(1, $objects);
            $winner = $objects[0]['Key'];
            self::assertSame(60, (int) $objects[0]['Size']);
            self::assertSame(60, strlen((string) $second->getObject(['Bucket' => $bucket, 'Key' => $winner])['Body']));
        } finally {
            foreach (['public', 'visible', 'first', 'second'] as $key) {
                try {
                    self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
                } catch (\Throwable) {
                }
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_sse_multipart_parts_remain_encrypted_across_two_http_nodes(): void
    {
        $second = self::$secondClient;
        self::assertNotNull($second);
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        self::assertIsString($driver);
        self::assertIsString($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $bucket = 'multi-sse-parts-' . bin2hex(random_bytes(8));
        $key = 'encrypted-parts';
        $firstBody = 'plain-A1';
        $secondBody = 'plain-B2';
        $uploadId = null;
        $completed = false;
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $created = self::$s3->createMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'ServerSideEncryption' => 'AES256',
            ]);
            $uploadId = (string) $created['UploadId'];
            $upload = $metadata->getMultipartUpload($uploadId);
            self::assertNotNull($upload);
            self::assertSame('encrypted-v2', $upload['user_metadata']['__mpu-part-format'] ?? null);

            $first = self::$s3->uploadPart([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
                'PartNumber' => 1, 'Body' => $firstBody,
            ]);
            $secondPart = $second->uploadPart([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
                'PartNumber' => 2, 'Body' => $secondBody,
            ]);
            $parts = $metadata->getParts($uploadId);
            self::assertCount(2, $parts);
            foreach ($parts as $index => $part) {
                $ciphertext = file_get_contents($part['storage_path']);
                self::assertIsString($ciphertext);
                self::assertGreaterThan($part['size'], strlen($ciphertext));
                self::assertStringNotContainsString($index === 0 ? $firstBody : $secondBody, $ciphertext);
            }

            self::$s3->completeMultipartUpload([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [
                    ['PartNumber' => 1, 'ETag' => $first['ETag']],
                    ['PartNumber' => 2, 'ETag' => $secondPart['ETag']],
                ]],
            ]);
            $completed = true;

            foreach ([self::$s3, $second] as $client) {
                $object = $client->getObject(['Bucket' => $bucket, 'Key' => $key]);
                self::assertSame($firstBody . $secondBody, (string) $object['Body']);
                self::assertSame('AES256', $object['ServerSideEncryption']);
            }
        } finally {
            if (!$completed && $uploadId !== null) {
                try {
                    self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
                } catch (\Throwable) {
                }
            }
            try {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            } catch (\Throwable) {
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_upload_part_rechecks_policy_after_a_second_node_changes_it_during_body_read(): void
    {
        $second = self::$secondClient;
        self::assertNotNull($second);
        $bucket = 'multi-part-policy-' . bin2hex(random_bytes(8));
        $key = 'part-key';
        $body = str_repeat('p', 64);
        self::$s3->createBucket(['Bucket' => $bucket]);
        $uploadId = (string) self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key])['UploadId'];
        $socket = null;

        try {
            $url = sprintf('http://%s:%d/%s/%s?partNumber=1&uploadId=%s', self::$host, self::$port, $bucket, $key, $uploadId);
            $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request('PUT', $url, [
                    'Content-Length' => (string) strlen($body),
                    'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
                ], $body),
                new Credentials(self::$accessKey, self::$secretKey),
            );
            $wire = $signed->getMethod() . ' ' . $signed->getRequestTarget() . " HTTP/1.1\r\n";
            foreach ($signed->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $wire .= $name . ': ' . $value . "\r\n";
                }
            }
            $wire .= "Connection: close\r\n\r\n";

            $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
            self::assertNotFalse($socket, $error ?: 'Could not connect to the first API node.');
            stream_set_timeout($socket, 10);
            $tempDir = self::$storagePath . '/.tmp';
            $before = glob($tempDir . '/*') ?: [];
            self::writeAll($socket, $wire . substr($body, 0, 8));

            // FilesystemBackend opens its staging file after initial policy
            // authorization and before it reads the rest of this request body.
            $deadline = microtime(true) + 5;
            $staged = false;
            do {
                $staged = array_diff(glob($tempDir . '/*') ?: [], $before) !== [];
                if ($staged) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($staged, 'The first node did not begin staging the paused part.');

            $second->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode([
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObject',
                    'Resource' => 'arn:aws:s3:::' . $bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR)]);
            self::writeAll($socket, substr($body, 8));
            $response = stream_get_contents($socket);
            self::assertIsString($response);
            self::assertFalse(stream_get_meta_data($socket)['timed_out']);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 403 /', $response);
            self::assertStringContainsString('<Code>AccessDenied</Code>', $response);
            self::assertSame([], self::$s3->listParts([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
            ])['Parts'] ?? []);

            $second->deleteBucketPolicy(['Bucket' => $bucket]);
            self::$s3->uploadPart(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => 1, 'Body' => $body]);
            self::assertCount(1, self::$s3->listParts([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
            ])['Parts'] ?? []);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            try {
                $second->deleteBucketPolicy(['Bucket' => $bucket]);
            } catch (\Throwable) {
            }
            self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    /** @param resource $socket */
    private static function writeAll($socket, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($socket, $bytes);
            self::assertNotFalse($written);
            self::assertGreaterThan(0, $written);
            $bytes = substr($bytes, $written);
        }
    }

    public function test_account_and_bucket_policy_changes_are_immediate_on_second_http_node(): void
    {
        $second = self::$secondClient;
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        $ownerId = getenv('S3_OWNER_ID');
        self::assertNotNull($second);
        self::assertIsString($driver);
        self::assertIsString($dsn);
        self::assertIsString($ownerId);

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $bucket = 'multi-policy-' . bin2hex(random_bytes(8));
        $resource = 'arn:aws:s3:::' . $bucket . '/key';
        $anonymous = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => (string) $second->getEndpoint(),
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => false,
        ]);
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'value']);
            self::assertSame('value', (string) $second->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);

            $metadata->putAccountPolicy($ownerId, json_encode(['Statement' => [[
                'Effect' => 'Deny',
                'Action' => 's3:GetObject',
                'Resource' => $resource,
            ]]], JSON_THROW_ON_ERROR));
            self::assertPolicyAccessDenied($second, $bucket);
            $metadata->deleteAccountPolicy($ownerId);
            self::assertSame('value', (string) $second->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);

            foreach (['Allow', 'Deny', 'Allow'] as $effect) {
                self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode(['Statement' => [[
                    'Effect' => $effect,
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => $resource,
                ]]], JSON_THROW_ON_ERROR)]);
                if ($effect === 'Allow') {
                    self::assertSame('value', (string) $anonymous->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);
                } else {
                    self::assertPolicyAccessDenied($anonymous, $bucket);
                }
            }
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            self::assertPolicyAccessDenied($anonymous, $bucket);
        } finally {
            $metadata->deleteAccountPolicy($ownerId);
            try {
                self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            } catch (S3Exception) {
            }
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    private static function assertPolicyAccessDenied(S3Client $client, string $bucket): void
    {
        try {
            $client->getObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::fail('A policy change on node one was not enforced on node two.');
        } catch (S3Exception $error) {
            self::assertSame('AccessDenied', $error->getAwsErrorCode());
        }
    }

    public function test_webhook_sequencer_increases_across_two_http_nodes(): void
    {
        $second = self::$secondClient;
        self::assertNotNull($second);
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        self::assertIsString($driver);
        self::assertIsString($dsn);
        $bucket = 'multi-sequence-' . bin2hex(random_bytes(8));
        $key = 'shared-key';
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => $bucket,
                'NotificationConfiguration' => ['TopicConfigurations' => [[
                    'Id' => 'sequence',
                    'TopicArn' => 'https://example.test/hook',
                    'Events' => ['s3:ObjectCreated:Put', 's3:ObjectRemoved:Delete'],
                ]]],
            ]);

            self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'one']);
            $second->deleteObject(['Bucket' => $bucket, 'Key' => $key]);

            $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $queued = $metadata->dequeueNotifications(2);
            self::assertCount(2, $queued);
            $created = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
            $removed = json_decode($queued[1]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
            self::assertSame('ObjectCreated:Put', $created['eventName']);
            self::assertSame('ObjectRemoved:Delete', $removed['eventName']);
            self::assertLessThan(
                hexdec($removed['s3']['object']['sequencer']),
                hexdec($created['s3']['object']['sequencer']),
            );
            foreach ($queued as $item) {
                $metadata->updateNotificationStatus($item['id'], 'sent');
            }
        } finally {
            self::$s3->putBucketNotificationConfiguration(['Bucket' => $bucket, 'NotificationConfiguration' => []]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_access_logs_flushed_by_one_http_node_are_readable_on_the_other(): void
    {
        $second = self::$secondClient;
        self::assertNotNull($second);
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        $owner = getenv('S3_OWNER_ID');
        self::assertIsString($driver);
        self::assertIsString($dsn);
        self::assertIsString($owner);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $suffix = bin2hex(random_bytes(8));
        $source = 'multi-log-source-' . $suffix;
        $target = 'multi-log-target-' . $suffix;
        $key = 'private-' . $suffix . '.txt';
        // The class's 100-byte race quota is intentionally too small for a
        // server access-log record; override it for this delivery profile.
        $metadata->putAccountQuota($owner, new QuotaConfig());

        try {
            self::$s3->createBucket(['Bucket' => $source]);
            self::$s3->createBucket(['Bucket' => $target]);
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $target,
                    'TargetPrefix' => 'access/',
                ]],
            ]);
            self::$s3->putObject(['Bucket' => $source, 'Key' => $key, 'Body' => 'stored']);
            self::restartServer(shutdownGraceSeconds: 120);

            $objects = $second->listObjectsV2(['Bucket' => $target, 'Prefix' => 'access/'])['Contents'] ?? [];
            self::assertNotEmpty($objects, sprintf(
                'No access log after graceful restart; previous server reported a flush failure: %s.',
                self::$lastServerAccessLogFlushFailed ? 'yes' : 'no',
            ));
            $log = (string) $second->getObject(['Bucket' => $target, 'Key' => $objects[0]['Key']])['Body'];
            self::assertStringContainsString($key, $log);
        } finally {
            if ($metadata->getBucketOwner($source) === $owner) {
                try {
                    self::$s3->putBucketLogging(['Bucket' => $source, 'BucketLoggingStatus' => []]);
                } catch (\Throwable) {
                }
                foreach (self::$s3->listObjectsV2(['Bucket' => $source])['Contents'] ?? [] as $object) {
                    self::$s3->deleteObject(['Bucket' => $source, 'Key' => $object['Key']]);
                }
                self::$s3->deleteBucket(['Bucket' => $source]);
            }
            if ($metadata->getBucketOwner($target) === $owner) {
                foreach (self::$s3->listObjectsV2(['Bucket' => $target])['Contents'] ?? [] as $object) {
                    self::$s3->deleteObject(['Bucket' => $target, 'Key' => $object['Key']]);
                }
                self::$s3->deleteBucket(['Bucket' => $target]);
            }
            $metadata->deleteAccountQuota($owner);
        }
    }

    private static function restoreEnv(): void
    {
        foreach (self::$previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::$previousEnv = [];
    }

    private static function seedBackgroundMetadata(MetadataStore $metadata, string $driver, string $dsn, int $count): void
    {
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $owner = 'startup-fixture-' . bin2hex(random_bytes(4));
        $suffix = bin2hex(random_bytes(4));
        $buckets = [];

        try {
            for ($index = 0; $index < 12; $index++) {
                $bucket = sprintf('s3audit-startup-%02d-%s', $index, $suffix);
                $metadata->createBucket($owner, $bucket, 'us-east-1');
                $metadata->setBucketVersioning($bucket, 'Enabled');
                $buckets[] = $bucket;
            }

            for ($offset = 0; $offset < $count; $offset += 500) {
                $rows = [];
                $tagRows = [];
                for ($number = $offset; $number < min($offset + 500, $count); $number++) {
                    $group = intdiv($number, 10);
                    $within = $number % 10;
                    $bucket = $buckets[$group % count($buckets)];
                    $key = sprintf('key-%09d', $group * 9 + max(0, $within - 1));
                    $version = $within === 0 ? 'v1' : ($within === 1 ? 'v2' : 'null');
                    $latest = $within === 0 ? 'FALSE' : 'TRUE';
                    $rows[] = sprintf(
                        "('%s', '%s', '%s', %s, '%s', '\"scale\"', 1, 'startup-path-%09d', '{}')",
                        $bucket,
                        $key,
                        $version,
                        $latest,
                        $owner,
                        $number,
                    );
                    if ($within === 1) {
                        $tagRows[] = sprintf("('object', '%s', '%s', 'v2', 'state', 'scale')", $bucket, $key);
                    }
                }

                // Values above are generated from fixed strings and integers.
                // Small statements avoid large prepared packets on MySQL.
                $pool->query('INSERT INTO s3_objects (bucket, key_name, version_id, is_latest, owner_id, etag, size, storage_path, user_metadata) VALUES ' . implode(', ', $rows));
                if ($tagRows !== []) {
                    $pool->query('INSERT INTO s3_tagging (resource_type, bucket, key_name, version_id, tag_key, tag_value) VALUES ' . implode(', ', $tagRows));
                }
            }

            $actual = $pool->query("SELECT COUNT(*) AS total FROM s3_objects WHERE owner_id = '{$owner}'")->fetchRow();
            self::assertSame($count, (int) ($actual['total'] ?? 0));
            if (getenv('S3_TEST_REPORT_STARTUP_TIME') === '1') {
                fwrite(STDERR, sprintf("Seeded %d background rows across 12 versioned buckets on %s.\n", $count, $driver));
            }
        } finally {
            $pool->close();
        }
    }
}
