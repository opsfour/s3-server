<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Closure;
use OpsFour\S3Server\Runtime\ProductionEfsHostPin;
use OpsFour\S3Server\Runtime\ProductionEfsIdentity;
use OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidenceFileReader;
use PHPUnit\Framework\TestCase;

final class ProductionEfsWatcherEvidenceFileReaderTest extends TestCase
{
    private const int NOW_NS = 1_000_000_000_000;

    private string $base;

    private string $evidenceDirectory;

    private string $path;

    private int $nowNs = self::NOW_NS;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->base = $temporaryRoot . '/s3-production-heartbeat-' . bin2hex(random_bytes(6));
        $this->evidenceDirectory = $this->base . '/evidence';
        self::assertTrue(mkdir($this->base, 0o700));
        self::assertTrue(mkdir($this->evidenceDirectory, 0o755));
        $this->path = $this->evidenceDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor(
                self::identity($this->evidenceDirectory),
                self::hostPin(),
            );
        $this->writeRecord();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDirectory . '/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->evidenceDirectory)) {
            rmdir($this->evidenceDirectory);
        }
        if (is_dir($this->base)) {
            rmdir($this->base);
        }
    }

    public function test_reads_only_the_identity_derived_file_and_accepts_renewed_time_fields(): void
    {
        self::assertIsInt(file_put_contents($this->evidenceDirectory . '/heartbeat.json', 'not evidence'));
        $reader = $this->reader();

        $first = $reader->read();
        $firstInode = fileinode($this->path);
        self::assertIsInt($firstInode);
        self::assertSame(self::NOW_NS - 1_000_000_000, $first->issuedMonotonicNs);
        self::assertSame(self::NOW_NS + 19_000_000_000, $first->expiresMonotonicNs);

        $this->nowNs += 5_000_000_000;
        $this->writeRecord(replace: true);
        clearstatcache(true, $this->path);
        self::assertNotSame($firstInode, fileinode($this->path));
        $second = $reader->read();
        self::assertSame(self::NOW_NS + 4_000_000_000, $second->issuedMonotonicNs);
        self::assertSame(self::NOW_NS + 24_000_000_000, $second->expiresMonotonicNs);
        self::assertSame($first->identity, $second->identity);
        self::assertSame($first->hostPin, $second->hostPin);
    }

    public function test_file_name_is_fixed_hex_and_binds_the_complete_host_pin(): void
    {
        $identity = self::identity($this->evidenceDirectory);
        $name = ProductionEfsWatcherEvidenceFileReader::fileNameFor($identity, self::hostPin());
        $changed = ProductionEfsWatcherEvidenceFileReader::fileNameFor(
            $identity,
            self::hostPin(['epoch' => str_repeat('f', 32)]),
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}\.json$/D', $name);
        self::assertSame($name, ProductionEfsWatcherEvidenceFileReader::fileNameFor($identity, self::hostPin()));
        self::assertNotSame($name, $changed);
    }

    public function test_missing_file_revokes_permanently_without_leaking_the_failed_record(): void
    {
        $mountReads = 0;
        $reader = $this->reader(mountInfo: function () use (&$mountReads): string {
            $mountReads++;

            return self::mountInfo($this->evidenceDirectory);
        });
        self::assertTrue(unlink($this->path));

        try {
            $reader->assertFresh();
            self::fail('A missing production heartbeat was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat is unavailable or invalid.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(1, $mountReads);

        $this->writeRecord(['sourceSha256' => str_repeat('f', 64)]);
        try {
            $reader->assertFresh();
            self::fail('A repaired heartbeat readmitted a revoked reader.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat reader was revoked.', $error->getMessage());
            self::assertStringNotContainsString(str_repeat('f', 64), $error->getMessage());
        }
        self::assertSame(1, $mountReads);
    }

    public function test_exact_read_only_local_directory_bind_is_required_before_file_stat(): void
    {
        $root = "1 0 0:1 / / rw - ext4 /dev/root rw\n";
        $cases = [
            'missing' => $root,
            'writable' => $root . "2 1 0:2 /evidence {$this->evidenceDirectory} rw - tmpfs tmpfs rw\n",
            'network' => $root . "2 1 0:2 /evidence {$this->evidenceDirectory} ro - nfs4 server:/ rw\n",
            'nested file mount' => self::mountInfo($this->evidenceDirectory)
                . "3 2 0:3 /record {$this->path} ro - tmpfs tmpfs rw\n",
            'ambiguous directory mount' => self::mountInfo($this->evidenceDirectory)
                . "3 2 0:3 /other {$this->evidenceDirectory} ro - ext4 /dev/other ro\n",
        ];

        foreach ($cases as $name => $mountInfo) {
            $fileStats = 0;
            $reader = $this->reader(
                mountInfo: static fn(): string => $mountInfo,
                lstat: function (string $path) use (&$fileStats): array|false {
                    if ($path === $this->path) {
                        $fileStats++;
                    }

                    return self::rootStat(@lstat($path));
                },
            );
            try {
                $reader->assertFresh();
                self::fail("{$name} heartbeat mount was accepted.");
            } catch (\RuntimeException $error) {
                self::assertSame('Production EFS heartbeat is unavailable or invalid.', $error->getMessage(), $name);
                self::assertSame(0, $fileStats, $name);
            }
        }
    }

    public function test_symlink_unsafe_owner_permissions_and_hardlink_are_rejected(): void
    {
        $target = $this->evidenceDirectory . '/target.json';
        self::assertTrue(rename($this->path, $target));
        self::assertTrue(symlink($target, $this->path));
        try {
            $this->reader()->assertFresh();
            self::fail('A symlinked heartbeat was accepted.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        } finally {
            unlink($this->path);
            self::assertTrue(rename($target, $this->path));
        }

        foreach (['owner', 'permissions', 'hardlink'] as $case) {
            $reader = $this->reader(lstat: function (string $path) use ($case): array|false {
                $entry = self::rootStat(@lstat($path));
                if (is_array($entry) && $path === $this->path) {
                    if ($case === 'owner') {
                        $entry['uid'] = 10001;
                    } elseif ($case === 'permissions') {
                        $entry['mode'] |= 0o020;
                    } else {
                        $entry['nlink'] = 2;
                    }
                }

                return $entry;
            });
            try {
                $reader->assertFresh();
                self::fail("Unsafe {$case} heartbeat was accepted.");
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_file_parent_and_mount_changes_during_read_revoke_the_reader(): void
    {
        $fileVisits = 0;
        $reader = $this->reader(lstat: function (string $path) use (&$fileVisits): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->path && ++$fileVisits === 2) {
                $entry['ino']++;
            }

            return $entry;
        });
        $this->assertRejected($reader, 'file replacement');

        $directoryVisits = 0;
        $reader = $this->reader(lstat: function (string $path) use (&$directoryVisits): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->evidenceDirectory && ++$directoryVisits === 2) {
                $entry['ino']++;
            }

            return $entry;
        });
        $this->assertRejected($reader, 'parent replacement');

        $mountReads = 0;
        $reader = $this->reader(mountInfo: function () use (&$mountReads): string {
            return ++$mountReads === 1
                ? self::mountInfo($this->evidenceDirectory)
                : str_replace('2 1 0:2', '9 1 0:2', self::mountInfo($this->evidenceDirectory));
        });
        $this->assertRejected($reader, 'mount change');
    }

    public function test_unrelated_mount_changes_do_not_revoke_valid_evidence(): void
    {
        $mountReads = 0;
        $reader = $this->reader(mountInfo: function () use (&$mountReads): string {
            $mountInfo = self::mountInfo($this->evidenceDirectory);

            return ++$mountReads === 1
                ? $mountInfo
                : $mountInfo . "3 1 0:3 / /unrelated rw - ext4 /dev/other rw\n";
        });

        $reader->assertFresh();
        self::assertSame(2, $mountReads);
    }

    public function test_successful_mount_and_directory_identity_are_pinned_across_reads(): void
    {
        $fileStats = 0;
        $reader = $this->reader(
            mountInfo: function (): string {
                $mountInfo = self::mountInfo($this->evidenceDirectory);

                return $this->nowNs > self::NOW_NS
                    ? str_replace('2 1 0:2', '9 1 0:2', $mountInfo)
                    : $mountInfo;
            },
            lstat: function (string $path) use (&$fileStats): array|false {
                $entry = self::rootStat(@lstat($path));
                if (is_array($entry) && $path === $this->evidenceDirectory
                    && $this->nowNs > self::NOW_NS) {
                    $entry['ino']++;
                }
                if ($path === $this->path && $this->nowNs > self::NOW_NS) {
                    $fileStats++;
                }

                return $entry;
            },
        );
        $reader->assertFresh();

        $this->nowNs += 5_000_000_000;
        $this->writeRecord(replace: true);
        $this->assertRejected($reader, 'complete evidence mount replacement');
        self::assertSame(0, $fileStats);

        try {
            $reader->assertFresh();
            self::fail('A restored evidence mount readmitted the revoked reader.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat reader was revoked.', $error->getMessage());
        }
    }

    public function test_unsafe_evidence_directory_owner_mode_and_symlink_type_are_rejected(): void
    {
        foreach (['owner', 'mode', 'symlink'] as $case) {
            $fileStats = 0;
            $reader = $this->reader(lstat: function (string $path) use ($case, &$fileStats): array|false {
                $entry = self::rootStat(@lstat($path));
                if (is_array($entry) && $path === $this->evidenceDirectory) {
                    if ($case === 'owner') {
                        $entry['uid'] = 10001;
                    } elseif ($case === 'mode') {
                        $entry['mode'] |= 0o020;
                    } else {
                        $entry['mode'] = ($entry['mode'] & 0o0777) | 0o120000;
                    }
                }
                if ($path === $this->path) {
                    $fileStats++;
                }

                return $entry;
            });

            $this->assertRejected($reader, "unsafe evidence directory {$case}");
            self::assertSame(0, $fileStats, $case);
        }
    }

    public function test_fstat_change_scope_drift_and_clock_failure_revoke_without_details(): void
    {
        $reader = $this->reader(fstat: function ($handle): array|false {
            $entry = self::rootStat(@fstat($handle));
            if (is_array($entry)) {
                $entry['size']++;
            }

            return $entry;
        });
        $this->assertRejected($reader, 'open-file change');

        $this->writeRecord(['containerId' => str_repeat('f', 64)]);
        $this->assertRejected($this->reader(), 'host-pin drift');

        $this->writeRecord();
        $clock = static function (): int {
            throw new \RuntimeException('secret clock failure');
        };
        $this->assertRejected($this->reader(clock: $clock), 'clock failure');
    }

    public function test_monotonic_time_is_sampled_after_the_stable_file_snapshot(): void
    {
        $now = self::NOW_NS;
        $fileVisits = 0;
        $reader = $this->reader(
            clock: static function () use (&$now): int {
                return $now;
            },
            lstat: function (string $path) use (&$fileVisits, &$now): array|false {
                $entry = self::rootStat(@lstat($path));
                if ($path === $this->path && ++$fileVisits === 2) {
                    $now = self::NOW_NS + 20_000_000_000;
                }

                return $entry;
            },
        );

        $this->assertRejected($reader, 'lease expired during the read');
        self::assertSame(self::NOW_NS + 20_000_000_000, $now);
    }

    public function test_boot_identity_is_checked_independently_and_latched(): void
    {
        $bootReads = 0;
        $reader = $this->reader(bootId: function () use (&$bootReads): string {
            $bootReads++;

            return $bootReads === 1
                ? '11111111-1234-1234-1234-123456789abc'
                : '99999999-1234-1234-1234-123456789abc';
        });

        $this->assertRejected($reader, 'boot identity drift');
        self::assertSame(2, $bootReads);
        try {
            $reader->assertFresh();
            self::fail('A revoked boot identity was retried.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat reader was revoked.', $error->getMessage());
        }
        self::assertSame(2, $bootReads);
    }

    public function test_initial_wrong_boot_identity_latches_before_file_access(): void
    {
        $bootReads = 0;
        $fileStats = 0;
        $reader = $this->reader(
            bootId: static function () use (&$bootReads): string {
                return ++$bootReads === 1
                    ? '99999999-1234-1234-1234-123456789abc'
                    : '11111111-1234-1234-1234-123456789abc';
            },
            lstat: function (string $path) use (&$fileStats): array|false {
                if ($path === $this->path) {
                    $fileStats++;
                }

                return self::rootStat(@lstat($path));
            },
        );
        $this->assertRejected($reader, 'initial wrong boot identity');
        self::assertSame(0, $fileStats);

        try {
            $reader->assertFresh();
            self::fail('A corrected boot identity readmitted the revoked reader.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat reader was revoked.', $error->getMessage());
        }
        self::assertSame(0, $fileStats);
        self::assertSame(1, $bootReads);
    }

    public function test_malformed_expired_and_oversized_records_latch_permanently(): void
    {
        foreach (['malformed', 'expired', 'oversized'] as $case) {
            if ($case === 'malformed') {
                $this->writeRaw('{"secret":"credential-value"}' . "\n");
            } elseif ($case === 'expired') {
                $this->writeRecord(['expiresMonotonicNs' => self::NOW_NS]);
            } else {
                $this->writeRaw(str_repeat('x', 4097));
            }
            $reader = $this->reader();
            $this->assertRejected($reader, $case);

            $this->writeRecord();
            try {
                $reader->assertFresh();
                self::fail("A repaired {$case} record readmitted the revoked reader.");
            } catch (\RuntimeException $error) {
                self::assertSame('Production EFS heartbeat reader was revoked.', $error->getMessage(), $case);
                self::assertStringNotContainsString('credential-value', $error->getMessage(), $case);
            }
        }
    }

    public function test_clock_and_heartbeat_progression_are_monotonic(): void
    {
        $clockValues = [self::NOW_NS, self::NOW_NS - 1];
        $reader = $this->reader(clock: static function () use (&$clockValues): int {
            $value = array_shift($clockValues);
            self::assertIsInt($value);

            return $value;
        });
        $this->assertRejected($reader, 'clock moved backward during one read');

        $clockValues = [self::NOW_NS, self::NOW_NS, self::NOW_NS - 1, self::NOW_NS];
        $reader = $this->reader(clock: static function () use (&$clockValues): int {
            $value = array_shift($clockValues);
            self::assertIsInt($value);

            return $value;
        });
        $reader->assertFresh();
        $this->assertRejected($reader, 'monotonic clock rollback');

        foreach ([
            'issued rollback' => [self::NOW_NS - 2_000_000_000, self::NOW_NS + 19_000_000_000],
            'expiry rollback' => [self::NOW_NS, self::NOW_NS + 18_000_000_000],
            'same issue changed expiry' => [self::NOW_NS - 1_000_000_000, self::NOW_NS + 18_000_000_000],
            'new issue without new expiry' => [self::NOW_NS, self::NOW_NS + 19_000_000_000],
        ] as $case => [$issued, $expires]) {
            $this->nowNs = self::NOW_NS;
            $this->writeRecord();
            $reader = $this->reader();
            $reader->assertFresh();
            $this->writeRecord([
                'issuedMonotonicNs' => $issued,
                'expiresMonotonicNs' => $expires,
            ]);
            $this->assertRejected($reader, $case);
        }

        $this->writeRecord();
        $reader = $this->reader();
        $reader->assertFresh();
        $reader->assertFresh();
        self::addToAssertionCount(1);
    }

    private function assertRejected(ProductionEfsWatcherEvidenceFileReader $reader, string $case): void
    {
        try {
            $reader->assertFresh();
            self::fail("{$case} was accepted.");
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat is unavailable or invalid.', $error->getMessage(), $case);
            self::assertNull($error->getPrevious(), $case);
        }
    }

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(): (string|false))|null $bootId
     * @param (Closure(): (string|false))|null $mountInfo
     * @param (Closure(string): (array<array-key, int>|false))|null $lstat
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstat
     */
    private function reader(
        ?Closure $clock = null,
        ?Closure $bootId = null,
        ?Closure $mountInfo = null,
        ?Closure $lstat = null,
        ?Closure $fstat = null,
    ): ProductionEfsWatcherEvidenceFileReader {
        return new ProductionEfsWatcherEvidenceFileReader(
            self::identity($this->evidenceDirectory),
            self::hostPin(),
            $clock ?? fn(): int => $this->nowNs,
            $bootId ?? static fn(): string => '11111111-1234-1234-1234-123456789abc',
            $mountInfo ?? fn(): string => self::mountInfo($this->evidenceDirectory),
            $lstat ?? static fn(string $path): array|false => self::rootStat(@lstat($path)),
            $fstat ?? static fn($handle): array|false => self::rootStat(@fstat($handle)),
        );
    }

    /** @param array<string, mixed> $changes */
    private function writeRecord(array $changes = [], bool $replace = false): void
    {
        $record = array_replace([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs',
            'storageRoot' => '/nonexistent-efs/objects',
            'containerId' => str_repeat('d', 64),
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
            'issuedMonotonicNs' => $this->nowNs - 1_000_000_000,
            'expiresMonotonicNs' => $this->nowNs + 19_000_000_000,
        ], $changes);
        $path = $replace ? $this->path . '.new' : $this->path;
        self::assertIsInt(file_put_contents(
            $path,
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        ));
        self::assertTrue(chmod($path, 0o644));
        if ($replace) {
            self::assertTrue(rename($path, $this->path));
        }
    }

    private function writeRaw(string $contents): void
    {
        self::assertIsInt(file_put_contents($this->path, $contents));
        self::assertTrue(chmod($this->path, 0o644));
    }

    private static function identity(string $evidenceDirectory): ProductionEfsIdentity
    {
        return ProductionEfsIdentity::fromJson(json_encode([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs',
            'storageRoot' => '/nonexistent-efs/objects',
            'scratchPath' => '/nonexistent-local-scratch',
            'evidenceDirectory' => $evidenceDirectory,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $changes */
    private static function hostPin(array $changes = []): ProductionEfsHostPin
    {
        return ProductionEfsHostPin::fromJson(json_encode(array_replace([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'containerId' => str_repeat('d', 64),
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
        ], $changes), JSON_THROW_ON_ERROR));
    }

    private static function mountInfo(string $evidenceDirectory): string
    {
        return "1 0 0:1 / / rw - ext4 /dev/root rw\n"
            . "2 1 0:2 /evidence {$evidenceDirectory} ro - tmpfs tmpfs rw\n";
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>|false
     */
    private static function rootStat(array|false $entry): array|false
    {
        if ($entry === false) {
            return false;
        }
        $entry['uid'] = 0;
        $entry['mode'] &= ~0o022;

        return $entry;
    }
}
