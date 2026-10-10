<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\ProductionEfsIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionEfsIdentityTest extends TestCase
{
    public function test_valid_identity_is_only_an_inert_value_without_path_access(): void
    {
        $identity = ProductionEfsIdentity::fromJson(self::json(self::validScope()));

        self::assertSame(str_repeat('a', 32), $identity->deploymentId);
        self::assertSame(str_repeat('b', 32), $identity->instanceId);
        self::assertSame('fs-0123456789abcdef0', $identity->efsId);
        self::assertSame('postgres', $identity->metadataDriver);
        self::assertSame('/nonexistent-efs-mount/objects', $identity->storageRoot);
        self::assertSame('/nonexistent-local-scratch', $identity->scratchPath);
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidScopeProvider')]
    public function test_test_profile_or_mismatched_scope_is_rejected(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductionEfsIdentity::fromJson(self::json(array_replace(self::validScope(), $changes)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidScopeProvider(): iterable
    {
        yield 'v1 qualification evidence' => [['schemaVersion' => 1, 'profile' => 'qualification']];
        yield 'unsafe test override' => [['allowUnfencedStorageForTests' => true]];
        yield 'test node label' => [['instanceId' => 'api-one']];
        yield 'wrong deployment identifier' => [['deploymentId' => str_repeat('g', 32)]];
        yield 'wrong EFS identifier' => [['efsId' => 'fs-untrusted']];
        yield 'wrong artifact digest' => [['sourceSha256' => str_repeat('z', 64)]];
        yield 'SQLite metadata' => [['metadataDriver' => 'sqlite']];
        yield 'different mount prefix' => [['storageRoot' => '/nonexistent-efs-mount-other/objects']];
        yield 'storage root is mount itself' => [['storageRoot' => '/nonexistent-efs-mount']];
        yield 'scratch on EFS' => [['scratchPath' => '/nonexistent-efs-mount/tmp']];
        yield 'evidence on EFS' => [['evidenceDirectory' => '/nonexistent-efs-mount/evidence']];
        yield 'scratch contains evidence' => [['scratchPath' => '/run', 'evidenceDirectory' => '/run/s3gate/evidence']];
        yield 'traversal before mount' => [['storageRoot' => '/nonexistent-efs-mount/../other']];
        yield 'path with empty component' => [['scratchPath' => '/tmp//scratch']];
        yield 'path with JSON escape' => [['scratchPath' => '/tmp/escaped\npath']];
    }

    public function test_duplicate_or_unknown_fields_and_oversize_json_are_rejected(): void
    {
        $valid = self::json(self::validScope());
        $duplicate = substr($valid, 0, -1) . ',"instanceId":"' . str_repeat('c', 32) . '"}';
        $unknown = self::json(array_replace(self::validScope(), ['secretKey' => 'must-not-appear']));
        $rejected = 0;
        foreach ([$duplicate, $unknown, str_repeat(' ', 4097), '{"schemaVersion":2}', '[]'] as $json) {
            try {
                ProductionEfsIdentity::fromJson($json);
                self::fail('Malformed production identity was accepted.');
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(5, $rejected);
    }

    /** @return array<string, mixed> */
    private static function validScope(): array
    {
        return [
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs-mount',
            'storageRoot' => '/nonexistent-efs-mount/objects',
            'scratchPath' => '/nonexistent-local-scratch',
            'evidenceDirectory' => '/run/s3gate/evidence',
        ];
    }

    /** @param array<string, mixed> $scope */
    private static function json(array $scope): string
    {
        return json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
