<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use League\Flysystem\Config;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AwsS3FlysystemFilesystemFactoryTest extends TestCase
{
    public function test_https_custom_endpoint_is_accepted(): void
    {
        $factory = self::factory(endpoint: 'https://objects.example.test');

        self::assertInstanceOf(AwsS3FlysystemFilesystemFactory::class, $factory);
    }

    public function test_external_http_custom_endpoint_is_rejected_before_client_creation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe test-only allowInsecureHttpForTests opt-in');

        self::factory(endpoint: 'http://objects.example.test');
    }

    public function test_http_custom_endpoint_requires_explicit_test_only_opt_in(): void
    {
        $factory = self::factory(
            endpoint: 'http://127.0.0.1:9000',
            allowInsecureHttpForTests: true,
        );

        self::assertInstanceOf(AwsS3FlysystemFilesystemFactory::class, $factory);
    }

    #[DataProvider('malformedEndpointProvider')]
    public function test_malformed_custom_endpoint_is_rejected(string $endpoint): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid HTTP(S) URI with a valid host');

        self::factory(endpoint: $endpoint, allowInsecureHttpForTests: true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEndpointProvider(): iterable
    {
        yield 'missing scheme' => ['objects.example.test'];
        yield 'unsupported scheme' => ['ftp://objects.example.test'];
        yield 'missing host' => ['https:///bucket'];
        yield 'invalid host' => ['https://objects_example.test'];
        yield 'embedded credentials' => ['https://user:secret@objects.example.test'];
        yield 'query' => ['https://objects.example.test?target=other'];
        yield 'fragment' => ['https://objects.example.test#target'];
        yield 'zero port' => ['https://objects.example.test:0'];
        yield 'out-of-range port' => ['https://objects.example.test:65536'];
        yield 'whitespace' => ["https://objects.example.test\n"];
    }

    public function test_timeout_changes_invalidate_cached_worker_client(): void
    {
        $default = self::factory();
        $shorterRequest = self::factory(requestTimeoutSeconds: 10);
        $shorterConnect = self::factory(connectTimeoutSeconds: 3);
        $shorterRead = self::factory(readTimeoutSeconds: 10);

        self::assertNotSame($default->cacheKey(), $shorterRequest->cacheKey());
        self::assertNotSame($default->cacheKey(), $shorterConnect->cacheKey());
        self::assertNotSame($default->cacheKey(), $shorterRead->cacheKey());
    }

    public function test_invalid_remote_client_timeout_configuration_fails_before_worker_start(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::factory(connectTimeoutSeconds: 20, requestTimeoutSeconds: 10);
    }

    public function test_default_read_deadline_does_not_cap_write_transfer_duration(): void
    {
        $filesystem = self::factory()->createFilesystem();
        $adapter = (new \ReflectionProperty($filesystem, 'adapter'))->getValue($filesystem);
        self::assertInstanceOf(AwsS3V3Adapter::class, $adapter);

        $options = (new \ReflectionProperty($adapter, 'options'))->getValue($adapter);
        self::assertSame([
            '@http' => ['stream' => true, 'timeout' => 15, 'read_timeout' => 15],
        ], $options);
        $uploadOptions = (new \ReflectionMethod($adapter, 'createOptionsFromConfig'))->invoke($adapter, new Config());
        self::assertArrayNotHasKey('@http', $uploadOptions);
    }

    private static function factory(
        int $connectTimeoutSeconds = 5,
        int $readTimeoutSeconds = 15,
        int $requestTimeoutSeconds = 0,
        ?string $endpoint = null,
        bool $allowInsecureHttpForTests = false,
    ): AwsS3FlysystemFilesystemFactory {
        return new AwsS3FlysystemFilesystemFactory(
            remoteBucket: 'test',
            region: 'us-east-1',
            accessKeyId: 'test',
            secretAccessKey: 'test',
            endpoint: $endpoint,
            connectTimeoutSeconds: $connectTimeoutSeconds,
            readTimeoutSeconds: $readTimeoutSeconds,
            requestTimeoutSeconds: $requestTimeoutSeconds,
            allowInsecureHttpForTests: $allowInsecureHttpForTests,
        );
    }
}
