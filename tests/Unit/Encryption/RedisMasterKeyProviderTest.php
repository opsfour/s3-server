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

    /**
     * @param array<string, int|string|list<mixed>|null> $responses
     */
    private static function client(array $responses): RedisClient
    {
        $link = new class ($responses) implements RedisLink {
            /** @param array<string, int|string|list<mixed>|null> $responses */
            public function __construct(private readonly array $responses) {}

            public function execute(string $command, array $parameters): RedisResponse
            {
                $key = strtoupper($command) . ':' . implode(':', $parameters);
                $value = $this->responses[$key] ?? null;

                return new class ($value) implements RedisResponse {
                    /** @param int|string|list<mixed>|null $value */
                    public function __construct(private readonly int|string|array|null $value) {}

                    /** @return int|string|list<mixed>|null */
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
