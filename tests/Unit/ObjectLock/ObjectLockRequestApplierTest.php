<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\ObjectLock;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use PHPUnit\Framework\TestCase;

final class ObjectLockRequestApplierTest extends TestCase
{
    public function test_legacy_oversized_default_retention_fails_closed(): void
    {
        $path = sys_get_temp_dir() . '/s3-object-lock-applier-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $metadata->putObjectLockConfig('bucket', [
                'objectLockEnabled' => 'Enabled',
                'rule' => ['defaultRetention' => ['mode' => 'COMPLIANCE', 'days' => PHP_INT_MAX]],
            ]);
            $versionId = $metadata->putObjectVersioned(
                'bucket',
                'key',
                'owner',
                1,
                '"etag"',
                'application/octet-stream',
                'path',
            );
            $request = new Request(
                $this->createMock(Client::class),
                'PUT',
                Http::new('http://127.0.0.1/bucket/key'),
            );

            try {
                (new ObjectLockRequestApplier($metadata))->apply($request, 'bucket', 'key', $versionId);
                self::fail('An unsafe persisted default retention must not produce an expired lock.');
            } catch (InvalidArgumentException) {
                self::assertNull($metadata->getObjectRetention('bucket', 'key', $versionId));
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /**
     * @param array{
     *     objectLockEnabled: string,
     *     rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}
     * } $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStoredConfigurations')]
    public function test_invalid_stored_object_lock_configuration_fails_before_retention_is_written(array $config): void
    {
        $path = sys_get_temp_dir() . '/s3-object-lock-applier-invalid-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $metadata->putObjectLockConfig('bucket', $config);
            $versionId = $metadata->putObjectVersioned(
                'bucket',
                'key',
                'owner',
                1,
                '"etag"',
                'application/octet-stream',
                'path',
            );
            $request = new Request(
                $this->createMock(Client::class),
                'PUT',
                Http::new('http://127.0.0.1/bucket/key'),
            );

            try {
                (new ObjectLockRequestApplier($metadata))->apply($request, 'bucket', 'key', $versionId);
                self::fail('Invalid stored Object Lock configuration must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertNull($metadata->getObjectRetention('bucket', 'key', $versionId));
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /**
     * @return iterable<string, array{array{
     *     objectLockEnabled: string,
     *     rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}
     * }}>
     */
    public static function invalidStoredConfigurations(): iterable
    {
        yield 'disabled row' => [[
            'objectLockEnabled' => 'Disabled',
        ]];
        yield 'unknown mode' => [[
            'objectLockEnabled' => 'Enabled',
            'rule' => ['defaultRetention' => ['mode' => 'UNKNOWN', 'days' => 1]],
        ]];
        yield 'both periods' => [[
            'objectLockEnabled' => 'Enabled',
            'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1, 'years' => 1]],
        ]];
    }
}
