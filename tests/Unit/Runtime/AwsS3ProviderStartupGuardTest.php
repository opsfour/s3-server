<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\AwsS3ProviderStartupException;
use OpsFour\S3Server\Runtime\ServeFailureMessage;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;

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
}
