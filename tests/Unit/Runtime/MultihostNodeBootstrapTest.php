<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/scripts/lib/MultihostNodeBootstrap.php';

final class MultihostNodeBootstrapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/s3-multihost-preflight-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/config.json');
        rmdir($this->directory);
    }

    public function test_private_file_and_private_addresses_are_required_without_leaking_secret(): void
    {
        $config = $this->baseConfig();
        $path = $this->writeConfig($config);
        self::assertSame('secret-never-print', \MultihostNodeBootstrap::loadConfig($path)['secretKey']);

        chmod($path, 0o644);
        try {
            \MultihostNodeBootstrap::loadConfig($path);
            self::fail('World-readable secret file was admitted.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('secret-never-print', $error->getMessage());
        }

        chmod($path, 0o600);
        $config['listenIp'] = '0.0.0.0';
        $this->writeConfig($config);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private IPv4');
        \MultihostNodeBootstrap::loadConfig($path);
    }

    public function test_rejects_unknown_field_and_nonmatching_api_port(): void
    {
        $config = $this->baseConfig();
        $config['allowUnfencedStorageForTests'] = false;
        $path = $this->writeConfig($config);
        try {
            \MultihostNodeBootstrap::loadConfig($path);
            self::fail('Unknown override was admitted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Unknown', $error->getMessage());
        }

        unset($config['allowUnfencedStorageForTests']);
        $config['port'] = 9001;
        $this->writeConfig($config);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('9000');
        \MultihostNodeBootstrap::loadConfig($path);
    }

    public function test_only_two_expected_node_ids_and_private_existing_logs_are_admitted(): void
    {
        $config = $this->baseConfig();
        $config['nodeId'] = 'api-three';
        $path = $this->writeConfig($config);
        try {
            \MultihostNodeBootstrap::loadConfig($path);
            self::fail('Unexpected node ID was admitted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('identity', $error->getMessage());
        }

        $log = $this->directory . '/node-api-one.log';
        file_put_contents($log, 'private log');
        chmod($log, 0o600);
        \MultihostNodeBootstrap::assertPrivateLogPath($log);
        chmod($log, 0o644);
        try {
            \MultihostNodeBootstrap::assertPrivateLogPath($log);
            self::fail('World-readable log was admitted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('private regular file', $error->getMessage());
        } finally {
            unlink($log);
        }
    }

    public function test_mount_record_requires_hard_nfs_v41_and_expected_efs_identity(): void
    {
        $line = '36 26 0:30 / /mnt/s3-audit rw,relatime - nfs4 fs-abcdef12.efs.eu-central-1.amazonaws.com:/ rw,vers=4.1,hard';
        \MultihostNodeBootstrap::assertMountRecord($line, '/mnt/s3-audit', 'fs-abcdef12');

        foreach ([
            str_replace('vers=4.1', 'vers=4.0', $line),
            str_replace('hard', 'soft', $line),
            str_replace('nfs4', 'ext4', $line),
            str_replace('fs-abcdef12', 'fs-12345678', $line),
        ] as $invalid) {
            try {
                \MultihostNodeBootstrap::assertMountRecord($invalid, '/mnt/s3-audit', 'fs-abcdef12');
                self::fail('An invalid EFS mount was admitted.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_storage_device_must_match_verified_mount_and_differ_from_scratch(): void
    {
        \MultihostNodeBootstrap::assertStorageMountDevice(100, 100, 200);

        foreach ([[100, 300, 200], [100, 200, 200]] as [$mount, $storage, $scratch]) {
            try {
                \MultihostNodeBootstrap::assertStorageMountDevice($mount, $storage, $scratch);
                self::fail('Storage outside the verified EFS device was admitted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('verified EFS mount', $error->getMessage());
            }
        }
    }

    public function test_metadata_dsn_must_target_isolated_host_and_run_database_for_both_drivers(): void
    {
        foreach (['postgres', 'mysql'] as $driver) {
            $config = $this->baseConfig();
            $config['metadataDriver'] = $driver;
            $validDsn = 'host=10.238.1.13 user=test password=secret-never-print db=s3audit_'
                . $config['runId'] . '_' . $driver;
            $config['metadataDsn'] = $validDsn;
            $path = $this->writeConfig($config);
            self::assertSame($validDsn, \MultihostNodeBootstrap::loadConfig($path)['metadataDsn']);

            foreach ([
                str_replace('host=10.238.1.13', 'host=10.238.1.14', $validDsn),
                str_replace('_' . $driver, '_other', $validDsn),
                'host=10.238.1.13 user=test password=secret-never-print',
                'not a valid DSN secret-never-print',
            ] as $invalidDsn) {
                $config['metadataDsn'] = $invalidDsn;
                $this->writeConfig($config);
                try {
                    \MultihostNodeBootstrap::loadConfig($path);
                    self::fail('Non-isolated metadata destination was admitted.');
                } catch (RuntimeException $error) {
                    self::assertStringNotContainsString('secret-never-print', $error->getMessage());
                }
            }
        }
    }

    /** @return array<string, string> */
    private function baseConfig(): array
    {
        return [
            'nodeId' => 'api-one',
            'runId' => str_repeat('a', 32),
            'efsId' => 'fs-abcdef12',
            'mountPoint' => '/mnt/s3-audit',
            'storagePath' => '/mnt/s3-audit/postgres',
            'scratchPath' => '/tmp/s3-audit-api-one',
            'listenIp' => '10.238.1.11',
            'proxyIp' => '10.238.1.13',
            'baseDomain' => 's3.audit.internal',
            'metadataDriver' => 'postgres',
            'metadataDsn' => 'host=10.238.1.13 user=test password=private db=s3audit_' . str_repeat('a', 32) . '_postgres',
            'accessKey' => 'testKey',
            'secretKey' => 'secret-never-print',
            'ownerId' => 'gate-owner',
        ];
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): string
    {
        $path = $this->directory . '/config.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        chmod($path, 0o600);

        return $path;
    }
}
