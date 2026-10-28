<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\AwsS3ProviderStartupException;
use OpsFour\S3Server\Runtime\ServeFailureMessage;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class AwsS3ProviderStartupGuardTest extends TestCase
{
    public function test_admitted_provider_still_fails_closed_as_fence_incomplete_even_with_test_override(): void
    {
        $admission = AwsS3ProviderAdmission::admitted('us-east-1');
        $tiers = StorageTierRegistry::single(new AwsS3StorageBackend($admission));

        foreach ([false, true] as $unsafeOverride) {
            try {
                StorageWriteFenceStartupGuard::assertReady($tiers, $unsafeOverride, 'postgres');
                self::fail('Provider admission must not imply a complete write fence.');
            } catch (AwsS3ProviderStartupException $error) {
                self::assertStringContainsString('writer fence is incomplete', $error->getMessage());
                self::assertStringContainsString('not production-qualified', $error->getMessage());
                self::assertSame($admission, $error->admission);
                self::assertSame($error->getMessage(), ServeFailureMessage::format($error, 'bootstrap'));
            }
        }
    }

    public function test_denied_provider_exposes_only_its_stable_reason(): void
    {
        $admission = AwsS3ProviderAdmission::denied(
            'us-east-1',
            true,
            false,
            AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
            'owner-or-access-check-failed',
        );
        $untrustedTierName = 'private-tier-name';
        $tiers = StorageTierRegistry::single(new AwsS3StorageBackend($admission), $untrustedTierName);

        try {
            StorageWriteFenceStartupGuard::assertReady($tiers, false, 'postgres');
            self::fail('Denied provider state must fail startup.');
        } catch (AwsS3ProviderStartupException $error) {
            self::assertStringContainsString('(owner-or-access-check-failed)', $error->getMessage());
            self::assertSame($admission, $error->admission);
            self::assertStringNotContainsString('123456789012', $error->getMessage());
            self::assertStringNotContainsString('private-bucket', $error->getMessage());
            self::assertStringNotContainsString($untrustedTierName, $error->getMessage());
            self::assertSame($error->safeMessage(), ServeFailureMessage::format($error, 'private-phase'));
            self::assertStringNotContainsString('private-phase', ServeFailureMessage::format($error, 'private-phase'));
        }
    }

    public function test_api_start_rejects_an_admitted_profile_before_listener_binding(): void
    {
        $backend = new AwsS3StorageBackend(AwsS3ProviderAdmission::admitted('us-east-1'));
        $server = new S3Server(
            new S3ServerConfig(storagePath: sys_get_temp_dir()),
            new SqliteMetadataStore(':memory:'),
            $backend,
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('An admitted but fence-incomplete AWS profile must not bind.');
        } catch (AwsS3ProviderStartupException $error) {
            self::assertStringContainsString('writer fence is incomplete', $error->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_runtime_binding_rejects_multiple_admitted_aws_tiers(): void
    {
        $tiers = new StorageTierRegistry([
            new StorageTier('PRIMARY', new AwsS3StorageBackend(AwsS3ProviderAdmission::admitted('us-east-1')), defaultWriteTier: true),
            new StorageTier('ARCHIVE', new AwsS3StorageBackend(AwsS3ProviderAdmission::admitted('us-west-2'))),
        ]);
        $method = new \ReflectionMethod(S3ServerRuntimeFactory::class, 'admittedAwsTier');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple admitted AWS S3 tier bindings are unsupported.');
        $method->invoke(new S3ServerRuntimeFactory(), $tiers);
    }

    public function test_mixed_local_default_and_aws_archive_rejects_non_postgres_metadata(): void
    {
        $oldAccessKey = getenv('AWS_ACCESS_KEY_ID');
        $oldSecretKey = getenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_ACCESS_KEY_ID=test');
        putenv('AWS_SECRET_ACCESS_KEY=test');
        $localRoot = sys_get_temp_dir() . '/s3-mixed-runtime-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($localRoot, 0700));
        $mock = new MockHandler([
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ]);
        $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
            'bucket' => 'backing-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/write-attempts',
        ], new AwsS3ProviderValidator(
            static fn(RequestInterface $request, array $options) => $mock($request, $options),
        ));
        $local = new FilesystemBackend($localRoot);
        $aws = new AwsS3StorageBackend($binding->admission, $binding);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $local, defaultWriteTier: true),
            new StorageTier('ARCHIVE', $aws, restoreRequired: true),
        ]);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $runtime = null;

        try {
            try {
                $runtime = (new S3ServerRuntimeFactory())->create(
                    config: new S3ServerConfig(storagePath: $localRoot),
                    metadata: $metadata,
                    storage: $local,
                    credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
                    logger: new NullLogger(),
                    metrics: new MetricsCollector(),
                    storageTiers: $tiers,
                );
                self::fail('An AWS tier must not inherit qualification from SQLite metadata.');
            } catch (\RuntimeException $error) {
                self::assertSame('The qualified AWS S3 profile requires PostgreSQL metadata.', $error->getMessage());
            }
        } finally {
            $runtime?->stop();
            putenv($oldAccessKey === false ? 'AWS_ACCESS_KEY_ID' : 'AWS_ACCESS_KEY_ID=' . $oldAccessKey);
            putenv($oldSecretKey === false ? 'AWS_SECRET_ACCESS_KEY' : 'AWS_SECRET_ACCESS_KEY=' . $oldSecretKey);
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($localRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($localRoot);
        }
    }
}
