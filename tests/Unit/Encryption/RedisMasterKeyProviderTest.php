<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use Amp\Redis\Connection\RedisLink;
use Amp\Redis\Protocol\RedisResponse;
use Amp\Redis\RedisClient;
use OpsFour\S3Server\Encryption\RedisMasterKeyProvider;
use PHPUnit\Framework\TestCase;

final class RedisMasterKeyProviderTest extends TestCase
{
    private string|false $originalKeyName;

    private string|false $originalDsn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKeyName = getenv('S3_REDIS_MASTER_KEY_NAME');
        $this->originalDsn = getenv('S3_REDIS_MASTER_KEY_DSN');
        putenv('S3_REDIS_MASTER_KEY_NAME');
        putenv('S3_REDIS_MASTER_KEY_DSN');
    }

    protected function tearDown(): void
    {
        putenv($this->originalKeyName === false
            ? 'S3_REDIS_MASTER_KEY_NAME'
            : 'S3_REDIS_MASTER_KEY_NAME=' . $this->originalKeyName);
        putenv($this->originalDsn === false
            ? 'S3_REDIS_MASTER_KEY_DSN'
            : 'S3_REDIS_MASTER_KEY_DSN=' . $this->originalDsn);
        parent::tearDown();
    }

    public function test_multi_key_configuration_uses_explicit_active_key(): void
    {
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);
        $provider = new RedisMasterKeyProvider(redisClient: self::client([
            'HGETALL:s3:master-keys' => [
                'first', base64_encode($first),
                'second', base64_encode($second),
            ],
            'GET:s3:active-key-id' => 'second',
        ]));

        self::assertSame('second', $provider->getKeyId());
        self::assertSame($second, $provider->getMasterKey());
        self::assertSame($first, $provider->getMasterKeyById('first'));
    }

    public function test_legacy_single_key_configuration_remains_supported(): void
    {
        $key = str_repeat('k', 32);
        $provider = new RedisMasterKeyProvider(
            keyName: 'custom-key',
            redisClient: self::client([
                'HGETALL:s3:master-keys' => [],
                'GET:custom-key' => base64_encode($key),
            ]),
        );

        self::assertSame('default', $provider->getKeyId());
        self::assertSame($key, $provider->getMasterKey());
    }

    public function test_empty_environment_key_name_fails_closed(): void
    {
        putenv('S3_REDIS_MASTER_KEY_NAME=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis master key name must not be empty.');

        new RedisMasterKeyProvider(redisClient: self::client([
            'HGETALL:s3:master-keys' => ['active', base64_encode(str_repeat('a', 32))],
            'GET:s3:active-key-id' => 'active',
        ]));
    }

    public function test_zero_environment_key_name_is_used_literally(): void
    {
        $key = str_repeat('z', 32);
        putenv('S3_REDIS_MASTER_KEY_NAME=0');

        $provider = new RedisMasterKeyProvider(redisClient: self::client([
            'HGETALL:s3:master-keys' => [],
            'GET:0' => base64_encode($key),
        ]));

        self::assertSame($key, $provider->getMasterKey());
    }

    public function test_empty_environment_dsn_fails_closed(): void
    {
        putenv('S3_REDIS_MASTER_KEY_DSN=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_REDIS_MASTER_KEY_DSN environment variable or redisDsn parameter is required.');

        new RedisMasterKeyProvider();
    }

    public function test_zero_environment_dsn_reaches_connection_validation(): void
    {
        putenv('S3_REDIS_MASTER_KEY_DSN=0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to Redis for master keys.');

        new RedisMasterKeyProvider();
    }

    public function test_numeric_master_key_id_remains_resolvable(): void
    {
        $key = str_repeat('n', 32);
        $provider = new RedisMasterKeyProvider(redisClient: self::client([
            'HGETALL:s3:master-keys' => ['123', base64_encode($key)],
            'GET:s3:active-key-id' => '123',
        ]));

        self::assertSame('123', $provider->getKeyId());
        self::assertSame($key, $provider->getMasterKeyById('123'));
    }

    public function test_numeric_master_key_id_in_associative_redis_response_remains_resolvable(): void
    {
        $key = str_repeat('n', 32);
        $provider = new RedisMasterKeyProvider(redisClient: self::client([
            'HGETALL:s3:master-keys' => ['123' => base64_encode($key)],
            'GET:s3:active-key-id' => '123',
        ]));

        self::assertSame('123', $provider->getKeyId());
        self::assertSame($key, $provider->getMasterKey());
    }

    public function test_multi_key_configuration_fails_closed_without_active_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Active master key ID is missing');

        new RedisMasterKeyProvider(redisClient: self::client([
            'HGETALL:s3:master-keys' => ['first', base64_encode(str_repeat('a', 32))],
            'GET:s3:active-key-id' => null,
        ]));
    }

    public function test_redis_read_failure_is_not_treated_as_missing_multi_key_configuration(): void
    {
        $link = new class implements RedisLink {
            public function execute(string $command, array $parameters): RedisResponse
            {
                throw new \RuntimeException('redis unavailable');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read master keys from Redis');

        new RedisMasterKeyProvider(redisClient: new RedisClient($link));
    }

    public function test_transport_failures_do_not_expose_client_secrets(): void
    {
        $sentinel = 'redis://user:password-sentinel@redis.internal';
        foreach ([
            'HGETALL:s3:master-keys' => [
                'HGETALL:s3:master-keys' => new \RuntimeException($sentinel),
            ],
            'GET:s3:active-key-id' => [
                'HGETALL:s3:master-keys' => ['active', base64_encode(str_repeat('a', 32))],
                'GET:s3:active-key-id' => new \RuntimeException($sentinel),
            ],
            'GET:legacy' => [
                'HGETALL:s3:master-keys' => [],
                'GET:legacy' => new \RuntimeException($sentinel),
            ],
        ] as $stage => $responses) {
            try {
                new RedisMasterKeyProvider(keyName: 'legacy', redisClient: self::client($responses));
                self::fail("Expected {$stage} to fail.");
            } catch (\RuntimeException $e) {
                self::assertSame('Unable to read master keys from Redis.', $e->getMessage(), $stage);
                self::assertNull($e->getPrevious(), $stage);
                self::assertStringNotContainsString('password-sentinel', (string) $e, $stage);
            }
        }
    }

    /**
     * @param array<string, int|string|array<int|string, mixed>|\Throwable|null> $responses
     */
    private static function client(array $responses): RedisClient
    {
        $link = new class ($responses) implements RedisLink {
            /** @param array<string, int|string|array<int|string, mixed>|\Throwable|null> $responses */
            public function __construct(private readonly array $responses) {}

            public function execute(string $command, array $parameters): RedisResponse
            {
                $key = strtoupper($command) . ':' . implode(':', $parameters);
                $value = $this->responses[$key] ?? null;
                if ($value instanceof \Throwable) {
                    throw $value;
                }

                return new class ($value) implements RedisResponse {
                    /** @param int|string|array<int|string, mixed>|null $value */
                    public function __construct(private readonly int|string|array|null $value) {}

                    /** @return int|string|array<int|string, mixed>|null */
                    public function unwrap(): int|string|array|null
                    {
                        return $this->value;
                    }
                };
            }
        };

        return new RedisClient($link);
    }
}
