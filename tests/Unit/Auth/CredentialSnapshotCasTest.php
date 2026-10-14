<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;
use OpsFour\S3Server\Contracts\CredentialProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialSnapshotCasTest extends TestCase
{
    #[DataProvider('replacementSnapshots')]
    public function test_in_memory_atomic_mutations_reject_every_replacement_field(Credential $replacement): void
    {
        $this->assertReplacementIsPreserved(
            static fn(Credential $credential): InMemoryCredentialProvider => new InMemoryCredentialProvider($credential),
            $replacement,
        );
    }

    #[DataProvider('replacementSnapshots')]
    public function test_config_file_atomic_mutations_reject_every_replacement_field(Credential $replacement): void
    {
        $paths = [];

        try {
            $this->assertReplacementIsPreserved(
                static function (Credential $credential) use (&$paths): ConfigFileCredentialProvider {
                    $path = sys_get_temp_dir() . '/s3-credential-cas-' . bin2hex(random_bytes(8)) . '.json';
                    $paths[] = $path;
                    $provider = new ConfigFileCredentialProvider($path);
                    $provider->putCredential($credential);

                    return $provider;
                },
                $replacement,
            );
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }

    #[DataProvider('replacementSnapshots')]
    public function test_sqlite_atomic_mutations_reject_every_replacement_field(Credential $replacement): void
    {
        $this->assertReplacementIsPreserved(
            static function (Credential $credential): DatabaseCredentialProvider {
                $provider = new DatabaseCredentialProvider(new \PDO('sqlite::memory:'), 0.0);
                $provider->initialize();
                $provider->putCredential($credential);

                return $provider;
            },
            $replacement,
        );
    }

    /**
     * @return iterable<string, array{Credential}>
     */
    public static function replacementSnapshots(): iterable
    {
        yield 'secret access key' => [self::credential(secretAccessKey: 'replacement-secret')];
        yield 'owner ID' => [self::credential(ownerId: 'replacement-owner')];
        yield 'display name' => [self::credential(displayName: 'Replacement')];
        yield 'active status' => [self::credential(isActive: false)];
        yield 'session token' => [self::credential(sessionToken: 'replacement-session')];
        yield 'removed session token' => [self::credential(sessionToken: null)];
        yield 'expiration' => [self::credential(expiresAt: new \DateTimeImmutable('2031-01-02T03:04:05Z'))];
        yield 'removed expiration' => [self::credential(expiresAt: null)];
        yield 'policy names' => [self::credential(policyNames: ['replacement-policy'])];
        yield 'allowed prefixes' => [self::credential(allowedPrefixes: ['replacement/'])];
    }

    /**
     * @param callable(Credential): (CredentialProvider&AtomicCredentialDeleter&AtomicCredentialStatusUpdater) $providerFactory
     */
    private function assertReplacementIsPreserved(callable $providerFactory, Credential $replacement): void
    {
        $expected = self::credential();

        $deleteProvider = $providerFactory($replacement);
        self::assertFalse($deleteProvider->deleteCredentialIfCurrent($expected));
        $afterDelete = $deleteProvider->getCredential($expected->accessKeyId);
        self::assertNotNull($afterDelete);
        self::assertTrue($replacement->matchesSnapshot($afterDelete));

        $statusProvider = $providerFactory($replacement);
        self::assertFalse($statusProvider->setActiveIfCurrent($expected, !$replacement->isActive));
        $afterStatus = $statusProvider->getCredential($expected->accessKeyId);
        self::assertNotNull($afterStatus);
        self::assertTrue($replacement->matchesSnapshot($afterStatus));
    }

    /**
     * @param list<string> $policyNames
     * @param list<string> $allowedPrefixes
     */
    private static function credential(
        string $secretAccessKey = 'original-secret',
        string $ownerId = 'original-owner',
        string $displayName = 'Original',
        bool $isActive = true,
        ?string $sessionToken = 'original-session',
        ?\DateTimeImmutable $expiresAt = new \DateTimeImmutable('2030-01-02T03:04:05Z'),
        array $policyNames = ['original-policy'],
        array $allowedPrefixes = ['original/'],
    ): Credential {
        return new Credential(
            accessKeyId: 'AKIASNAPSHOTCAS00001',
            secretAccessKey: $secretAccessKey,
            ownerId: $ownerId,
            displayName: $displayName,
            isActive: $isActive,
            sessionToken: $sessionToken,
            expiresAt: $expiresAt,
            policyNames: $policyNames,
            allowedPrefixes: $allowedPrefixes,
        );
    }
}
