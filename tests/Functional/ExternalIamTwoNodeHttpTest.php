<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use GuzzleHttp\Client;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use Symfony\Component\Process\Process;

/** Two real API processes share the credential and metadata files. */
final class ExternalIamTwoNodeHttpTest extends S3FunctionalTestCase
{
    private static ?Process $secondServer = null;

    private static int $secondPort = 0;

    private static string $credentialPath = '';

    private static string $credentialDsn = '';

    private static ?string $credentialUser = null;

    private static ?string $credentialPassword = null;

    private static string $publicKeyPath = '';

    private static string $privateKey = '';

    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, self::$privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::$publicKeyPath = tempnam(sys_get_temp_dir(), 's3-iam-two-node-key-') ?: '';
        self::assertNotSame('', self::$publicKeyPath);
        self::assertNotFalse(file_put_contents(self::$publicKeyPath, $details['key']));

        $externalDsn = getenv('S3_TEST_OIDC_CREDENTIAL_DSN');
        if ($externalDsn !== false && $externalDsn !== '') {
            if (preg_match('/^(?:pgsql|mysql):.*(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $externalDsn) !== 1) {
                self::fail('The external OIDC credential test requires a disposable s3audit_ database.');
            }
            self::$credentialDsn = $externalDsn;
            self::$credentialUser = getenv('S3_TEST_OIDC_CREDENTIAL_USERNAME') ?: null;
            self::$credentialPassword = getenv('S3_TEST_OIDC_CREDENTIAL_PASSWORD') ?: null;
        } else {
            self::$credentialPath = tempnam(sys_get_temp_dir(), 's3-iam-two-node-db-') ?: '';
            self::assertNotSame('', self::$credentialPath);
            self::$credentialDsn = 'sqlite:' . self::$credentialPath;
        }

        $environment = [
            'S3_CREDENTIALS_DRIVER' => 'database',
            'S3_CREDENTIALS_DSN' => self::$credentialDsn,
            'S3_CREDENTIALS_CACHE_TTL' => '0',
            'S3_EXTERNAL_IAM_ENABLED' => 'true',
            'S3_EXTERNAL_IAM_ADMIN_TOKEN' => 'two-node-admin-token',
            'S3_EXTERNAL_IAM_ISSUER' => 'https://identity.example.test/realm',
            'S3_EXTERNAL_IAM_AUDIENCE' => 's3-admin',
            'S3_EXTERNAL_IAM_PUBLIC_KEY_PATH' => self::$publicKeyPath,
        ];
        if (self::$credentialUser !== null) {
            $environment['S3_CREDENTIALS_USERNAME'] = self::$credentialUser;
        }
        if (self::$credentialPassword !== null) {
            $environment['S3_CREDENTIALS_PASSWORD'] = self::$credentialPassword;
        }
        foreach ($environment as $name => $value) {
            self::$previousEnv[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            // Credential schema bootstrap is a separate, explicit step before
            // either API process starts.
            DatabaseCredentialProvider::fromDsn(
                self::$credentialDsn,
                self::$credentialUser,
                self::$credentialPassword,
                bootstrap: true,
            );
            parent::setUpBeforeClass();
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($socket);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            self::assertIsString($address);
            self::$secondPort = (int) substr($address, strrpos($address, ':') + 1);

            self::$secondServer = new Process([
                PHP_BINARY,
                dirname(__DIR__, 2) . '/bin/s3-server',
                '--host=127.0.0.1',
                '--port=' . self::$secondPort,
                '--storage-driver=filesystem',
                '--storage-path=' . self::$storagePath,
                '--metadata-path=' . self::$storagePath . '/metadata.sqlite',
            ], dirname(__DIR__, 2));
            self::$secondServer->setEnv($environment);
            self::$secondServer->setTimeout(null);
            self::$secondServer->start();

            $deadline = microtime(true) + 10;
            do {
                if (! self::$secondServer->isRunning()) {
                    self::fail('Second IAM node exited: ' . self::$secondServer->getErrorOutput());
                }
                $connection = @fsockopen('127.0.0.1', self::$secondPort, $errno, $error, 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    break;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
            self::assertNotFalse($connection, 'Second IAM node did not become ready: ' . self::$secondServer->getErrorOutput());
        } catch (\Throwable $error) {
            self::$secondServer?->stop(5);
            parent::tearDownAfterClass();
            self::restoreEnvironment();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$secondServer?->stop(5);
            self::$secondServer = null;
            parent::tearDownAfterClass();
        } finally {
            self::restoreEnvironment();
        }
    }

    public function test_credential_issued_on_one_node_is_revoked_on_the_other(): void
    {
        $admin = new Client([
            'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
            'http_errors' => false,
            'timeout' => 5,
        ]);
        $issued = $admin->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer two-node-admin-token'],
            'json' => ['token' => $this->signedToken()],
        ]);
        self::assertSame(201, $issued->getStatusCode());
        $payload = json_decode((string) $issued->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $second = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://127.0.0.1:' . self::$secondPort,
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => [
                'key' => $payload['accessKeyId'],
                'secret' => $payload['secretAccessKey'],
                'token' => $payload['sessionToken'],
            ],
        ]);
        $bucket = 'two-node-iam-' . bin2hex(random_bytes(8));
        $second->createBucket(['Bucket' => $bucket]);
        $second->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'value']);
        self::assertSame('value', (string) $second->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);

        $revoked = $admin->delete('/.admin/credentials/' . rawurlencode($payload['accessKeyId']), [
            'headers' => ['Authorization' => 'Bearer two-node-admin-token'],
        ]);
        self::assertSame(204, $revoked->getStatusCode());
        $pdo = new \PDO(self::$credentialDsn, self::$credentialUser, self::$credentialPassword);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM s3_credentials WHERE access_key_id = ?');
        $statement->execute([$payload['accessKeyId']]);
        self::assertSame(0, (int) $statement->fetchColumn());
        try {
            $second->headBucket(['Bucket' => $bucket]);
            self::fail('Credential revoked on node one was accepted by node two.');
        } catch (S3Exception $error) {
            self::assertSame(403, $error->getStatusCode());
        }
    }

    public function test_bucket_and_account_policy_changes_are_visible_on_the_other_node(): void
    {
        $admin = new Client([
            'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
            'http_errors' => false,
            'timeout' => 5,
        ]);
        $issued = $admin->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer two-node-admin-token'],
            'json' => ['token' => $this->signedToken()],
        ]);
        self::assertSame(201, $issued->getStatusCode());
        $credential = json_decode((string) $issued->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($credential);

        $owner = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => [
                'key' => $credential['accessKeyId'],
                'secret' => $credential['secretAccessKey'],
                'token' => $credential['sessionToken'],
            ],
        ]);
        $secondOwner = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://127.0.0.1:' . self::$secondPort,
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => [
                'key' => $credential['accessKeyId'],
                'secret' => $credential['secretAccessKey'],
                'token' => $credential['sessionToken'],
            ],
        ]);
        $anonymous = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://127.0.0.1:' . self::$secondPort,
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => false,
        ]);
        $metadata = MetadataStoreFactory::create('sqlite', ['path' => self::$storagePath . '/metadata.sqlite']);
        $bucket = 'two-node-policy-' . bin2hex(random_bytes(8));
        $resource = 'arn:aws:s3:::' . $bucket . '/key';
        $owner->createBucket(['Bucket' => $bucket]);

        try {
            $owner->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'value']);
            self::assertSame('value', (string) $secondOwner->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);

            $metadata->putAccountPolicy((string) $credential['ownerId'], json_encode(['Statement' => [[
                'Effect' => 'Deny',
                'Action' => 's3:GetObject',
                'Resource' => $resource,
            ]]], JSON_THROW_ON_ERROR));
            self::assertAccessDenied($secondOwner, $bucket);
            $metadata->deleteAccountPolicy((string) $credential['ownerId']);
            self::assertSame('value', (string) $secondOwner->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);

            foreach (['Allow', 'Deny', 'Allow'] as $effect) {
                $owner->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode(['Statement' => [[
                    'Effect' => $effect,
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => $resource,
                ]]], JSON_THROW_ON_ERROR)]);
                if ($effect === 'Allow') {
                    self::assertSame('value', (string) $anonymous->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);
                } else {
                    self::assertAccessDenied($anonymous, $bucket);
                }
            }
            $owner->deleteBucketPolicy(['Bucket' => $bucket]);
            self::assertAccessDenied($anonymous, $bucket);
        } finally {
            $metadata->deleteAccountPolicy((string) $credential['ownerId']);
            try {
                $owner->deleteBucketPolicy(['Bucket' => $bucket]);
            } catch (S3Exception) {
            }
            $owner->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            $owner->deleteBucket(['Bucket' => $bucket]);
            $admin->delete('/.admin/credentials/' . rawurlencode((string) $credential['accessKeyId']), [
                'headers' => ['Authorization' => 'Bearer two-node-admin-token'],
            ]);
        }
    }

    private static function assertAccessDenied(S3Client $client, string $bucket): void
    {
        try {
            $client->getObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::fail('A policy change on node one was not enforced on node two.');
        } catch (S3Exception $error) {
            self::assertSame('AccessDenied', $error->getAwsErrorCode());
        }
    }

    private function signedToken(): string
    {
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'kid' => 'default'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'sub' => 'two-node-user',
            'iss' => 'https://identity.example.test/realm',
            'aud' => 's3-admin',
            'exp' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $input = $header . '.' . $claims;
        self::assertTrue(openssl_sign($input, $signature, self::$privateKey, OPENSSL_ALGO_SHA256));

        return $input . '.' . self::base64Url($signature);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function restoreEnvironment(): void
    {
        foreach (self::$previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::$previousEnv = [];
        if (self::$credentialPath !== '') {
            foreach ([self::$credentialPath, self::$credentialPath . '-wal', self::$credentialPath . '-shm'] as $path) {
                @unlink($path);
            }
        }
        if (self::$publicKeyPath !== '') {
            @unlink(self::$publicKeyPath);
        }
        self::$credentialPath = '';
        self::$credentialDsn = '';
        self::$credentialUser = null;
        self::$credentialPassword = null;
        self::$publicKeyPath = '';
    }
}
