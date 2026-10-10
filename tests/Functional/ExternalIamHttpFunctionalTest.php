<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Client;

/** Exercises the actual admin route, JWT verifier, credential store, and SigV4 server. */
final class ExternalIamHttpFunctionalTest extends S3FunctionalTestCase
{
    private static string $privateKey = '';

    private static string $publicKeyPath = '';

    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('openssl')) {
            self::markTestSkipped('The external IAM HTTP profile requires OpenSSL.');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, self::$privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::$publicKeyPath = tempnam(sys_get_temp_dir(), 's3-external-iam-key-') ?: '';
        self::assertNotSame('', self::$publicKeyPath);
        self::assertNotFalse(file_put_contents(self::$publicKeyPath, $details['key']));

        foreach ([
            'S3_EXTERNAL_IAM_ENABLED' => 'true',
            'S3_EXTERNAL_IAM_ADMIN_TOKEN' => 'external-test-admin-token',
            'S3_EXTERNAL_IAM_ISSUER' => 'https://identity.example.test/realm',
            'S3_EXTERNAL_IAM_AUDIENCE' => 's3-admin',
            'S3_EXTERNAL_IAM_PUBLIC_KEY_PATH' => self::$publicKeyPath,
            'S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM' => 's3_prefixes',
        ] as $name => $value) {
            self::$previousEnv[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            parent::setUpBeforeClass();
        } catch (\Throwable $e) {
            self::restoreEnvironment();
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            self::restoreEnvironment();
        }
    }

    public function test_admin_exchange_requires_authentication_and_expected_jwt_audience(): void
    {
        $client = $this->httpClient();
        $token = $this->signedToken('s3-admin', ['incoming/']);

        $unauthorized = $client->post('/.admin/credentials', [
            'json' => ['token' => $token],
        ]);
        self::assertSame(401, $unauthorized->getStatusCode());

        $wrongAudience = $client->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer external-test-admin-token'],
            'json' => ['token' => $this->signedToken('other-client')],
        ]);
        self::assertSame(400, $wrongAudience->getStatusCode());
        self::assertStringContainsString('JWT audience is not allowed', (string) $wrongAudience->getBody());

        $tooLong = $client->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer external-test-admin-token'],
            'json' => ['token' => $token, 'ttlSeconds' => 43_201],
        ]);
        self::assertSame(400, $tooLong->getStatusCode());
        self::assertStringContainsString('between 3600 and 43200 seconds', (string) $tooLong->getBody());
    }

    public function test_issued_one_hour_credential_works_with_sigv4_and_is_revocable(): void
    {
        $client = $this->httpClient();
        $bucket = 'external-iam-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);
        $issued = $client->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer external-test-admin-token'],
            'json' => ['token' => $this->signedToken('s3-admin', ['key'], 'default-owner')],
        ]);
        self::assertSame(201, $issued->getStatusCode());
        $payload = json_decode((string) $issued->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['sessionToken']);
        self::assertNotSame('', $payload['sessionToken']);
        self::assertIsString($payload['expiresAt']);
        $expiresAt = new \DateTimeImmutable($payload['expiresAt']);
        self::assertEqualsWithDelta(time() + 3600, $expiresAt->getTimestamp(), 5);

        $s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => [
                'key' => $payload['accessKeyId'],
                'secret' => $payload['secretAccessKey'],
                'token' => $payload['sessionToken'],
            ],
        ]);
        $s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'value']);
        self::assertSame('value', (string) $s3->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);

        $revoked = $client->delete('/.admin/credentials/' . rawurlencode($payload['accessKeyId']), [
            'headers' => ['Authorization' => 'Bearer external-test-admin-token'],
        ]);
        self::assertSame(204, $revoked->getStatusCode());
        try {
            $s3->headBucket(['Bucket' => $bucket]);
            self::fail('Expected the revoked SigV4 credential to be rejected.');
        } catch (S3Exception $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function test_scoped_oidc_owner_credential_cannot_put_outside_prefix(): void
    {
        $bucket = 'external-iam-scope-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);
        $admin = $this->httpClient();

        $missingScope = $admin->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer external-test-admin-token'],
            'json' => ['token' => $this->signedToken('s3-admin', null, 'default-owner')],
        ]);
        self::assertSame(400, $missingScope->getStatusCode());
        self::assertStringContainsString('must contain at least one prefix', (string) $missingScope->getBody());

        $issued = $admin->post('/.admin/credentials', [
            'headers' => ['Authorization' => 'Bearer external-test-admin-token'],
            'json' => ['token' => $this->signedToken('s3-admin', ['incoming/'], 'default-owner')],
        ]);
        self::assertSame(201, $issued->getStatusCode());
        $payload = json_decode((string) $issued->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('default-owner', $payload['ownerId']);
        self::assertSame(['incoming/'], $payload['allowedPrefixes']);

        $s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => [
                'key' => $payload['accessKeyId'],
                'secret' => $payload['secretAccessKey'],
                'token' => $payload['sessionToken'],
            ],
        ]);

        try {
            $s3->putObject(['Bucket' => $bucket, 'Key' => 'private/blocked', 'Body' => 'blocked']);
            self::fail('A scoped owner credential wrote outside its allowed prefix.');
        } catch (S3Exception $e) {
            self::assertSame(403, $e->getStatusCode());
        }

        $s3->putObject(['Bucket' => $bucket, 'Key' => 'incoming/allowed', 'Body' => 'allowed']);
        self::assertSame('allowed', (string) self::$s3->getObject([
            'Bucket' => $bucket,
            'Key' => 'incoming/allowed',
        ])['Body']);
        try {
            self::$s3->headObject(['Bucket' => $bucket, 'Key' => 'private/blocked']);
            self::fail('A denied scoped write must not create an object.');
        } catch (S3Exception $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    private function httpClient(): Client
    {
        return new Client([
            'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    /** @param ?list<string> $prefixes */
    private function signedToken(string $audience, ?array $prefixes = null, string $subject = 'external-user'): string
    {
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'kid' => 'default'], JSON_THROW_ON_ERROR));
        $claims = [
            'sub' => $subject,
            'iss' => 'https://identity.example.test/realm',
            'aud' => $audience,
            'exp' => time() + 300,
        ];
        if ($prefixes !== null) {
            $claims['s3_prefixes'] = $prefixes;
        }
        $claims = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
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
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        self::$previousEnv = [];
        if (self::$publicKeyPath !== '') {
            @unlink(self::$publicKeyPath);
            self::$publicKeyPath = '';
        }
    }
}
