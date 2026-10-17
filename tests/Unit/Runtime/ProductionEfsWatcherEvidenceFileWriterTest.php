<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Closure;
use OpsFour\S3Server\Runtime\ProductionEfsHostPin;
use OpsFour\S3Server\Runtime\ProductionEfsIdentity;
use OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidenceFileReader;
use OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidenceFileWriter;
use PHPUnit\Framework\TestCase;

final class ProductionEfsWatcherEvidenceFileWriterTest extends TestCase
{
    private string $base;

    private string $directory;

    private int $clock = 10_000_000_000;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->base = $temporaryRoot . '/opsfour-writer-' . bin2hex(random_bytes(8));
        $this->directory = $this->base . '/evidence';
        self::assertTrue(mkdir($this->directory, 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function test_writer_publishes_canonical_reader_compatible_record_and_progresses_time(): void
    {
        $writer = $this->writer();
        $first = $writer->publish();
        $second = $writer->publish();

        self::assertGreaterThan($first->issuedMonotonicNs, $second->issuedMonotonicNs);
        self::assertGreaterThan($first->expiresMonotonicNs, $second->expiresMonotonicNs);
        $path = $this->path();
        self::assertSame(0o644, fileperms($path) & 0o777);
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame($second->toJson(), $json);
        self::assertCount(17, json_decode($json, true, 2, JSON_THROW_ON_ERROR));

        $reader = new ProductionEfsWatcherEvidenceFileReader(
            $this->identity(),
            $this->hostPin(),
            fn(): int => $second->issuedMonotonicNs + 1,
            static fn(): string => self::bootId(),
            fn(): string => $this->mountInfo('ro'),
            static fn(string $path): array|false => self::rootStat(@lstat($path)),
            static fn($handle): array|false => self::rootStat(@fstat($handle)),
        );
        self::assertSame($second->toJson(), $reader->read()->toJson());
    }

    public function test_second_writer_cannot_publish_while_scope_lock_is_held(): void
    {
        $first = $this->writer();
        $first->publish();
        $second = $this->writer();
        $lockPath = $this->path() . '.lock';
        $before = lstat($lockPath);
        self::assertIsArray($before);
        $this->assertPublishFails($second);
        $after = lstat($lockPath);
        self::assertIsArray($after);
        self::assertSame($before['ino'], $after['ino']);

        $third = $this->writer();
        $this->assertPublishFails($third);
        $final = lstat($lockPath);
        self::assertIsArray($final);
        self::assertSame($before['ino'], $final['ino']);
    }

    public function test_writer_cannot_be_cloned_to_share_its_lock_resource(): void
    {
        $writer = $this->writer();
        $writer->publish();

        $this->expectException(\Error::class);
        $clone = static fn(ProductionEfsWatcherEvidenceFileWriter $candidate): ProductionEfsWatcherEvidenceFileWriter => clone $candidate;
        self::assertInstanceOf(ProductionEfsWatcherEvidenceFileWriter::class, $clone($writer));
    }

    public function test_restart_rejects_clock_that_does_not_advance_persisted_baseline(): void
    {
        $first = $this->writer();
        $evidence = $first->publish();
        unset($first);
        gc_collect_cycles();
        $this->clock = $evidence->issuedMonotonicNs - 1_000_000;

        $this->expectException(\RuntimeException::class);
        $this->writer()->publish();
    }

    public function test_partial_write_preserves_old_record_and_poisons_writer(): void
    {
        $originalWriter = $this->writer();
        $originalWriter->publish();
        $original = file_get_contents($this->path());
        unset($originalWriter);
        gc_collect_cycles();
        $failed = false;
        $writer = $this->writer(fault: static function (string $event) use (&$failed): void {
            if ($event === 'after-write-chunk' && ! $failed) {
                $failed = true;
                throw new \RuntimeException('partial');
            }
        });

        $this->assertPublishFails($writer);
        self::assertSame($original, file_get_contents($this->path()));
        $this->assertPublishFails($writer);
    }

    public function test_rename_false_after_moving_target_is_invalidated_and_never_reported_success(): void
    {
        $rename = static function (string $source, string $target): bool {
            self::assertTrue(rename($source, $target));

            return false;
        };
        $writer = $this->writer(rename: $rename);

        $this->assertPublishFails($writer);
        self::assertFileDoesNotExist($this->path());
    }

    public function test_directory_fsync_failure_invalidates_published_target(): void
    {
        $sync = static function ($handle): bool {
            $stat = fstat($handle);

            return is_array($stat) && (($stat['mode'] & 0o170000) !== 0o040000);
        };
        $writer = $this->writer(sync: $sync);

        $this->assertPublishFails($writer);
        self::assertFileDoesNotExist($this->path());
    }

    public function test_post_rename_failure_invalidates_published_target(): void
    {
        $fault = static function (string $event): void {
            if ($event === 'after-publish-rename') {
                throw new \RuntimeException('ambiguous publish');
            }
        };

        $this->assertPublishFails($this->writer(fault: $fault));
        self::assertFileDoesNotExist($this->path());
    }

    public function test_boot_id_change_during_publish_fails_closed(): void
    {
        $changed = false;
        $fault = static function (string $event) use (&$changed): void {
            if ($event === 'after-file-fsync') {
                $changed = true;
            }
        };
        $boot = static function () use (&$changed): string {
            return $changed ? '22222222-1234-1234-1234-123456789abc' : self::bootId();
        };

        $this->assertPublishFails($this->writer(bootId: $boot, fault: $fault));
        self::assertFileDoesNotExist($this->path());
    }

    public function test_directory_swap_before_rename_is_rejected_without_replacing_old_record(): void
    {
        $originalWriter = $this->writer();
        $originalWriter->publish();
        $original = file_get_contents($this->path());
        unset($originalWriter);
        gc_collect_cycles();
        $moved = $this->directory . '-moved';
        $fault = function (string $event) use ($moved): void {
            if ($event === 'before-publish-rename') {
                self::assertTrue(rename($this->directory, $moved));
                self::assertTrue(mkdir($this->directory, 0o700));
            }
        };

        try {
            $this->assertPublishFails($this->writer(fault: $fault));
            self::assertSame($original, file_get_contents($moved . '/' . basename($this->path())));
        } finally {
            $this->remove($this->directory);
            if (is_dir($moved)) {
                rename($moved, $this->directory);
            }
        }
    }

    public function test_temp_hardlink_and_lock_symlink_are_rejected(): void
    {
        $linked = false;
        $fault = function (string $event, string $path) use (&$linked): void {
            if ($event === 'after-temp-chmod' && ! $linked) {
                $linked = true;
                self::assertTrue(link($path, $path . '.alias'));
            }
        };
        $this->assertPublishFails($this->writer(fault: $fault));

        $otherDirectory = $this->base . '/other';
        self::assertTrue(mkdir($otherDirectory, 0o700));
        $identity = $this->identity($otherDirectory);
        $lock = $otherDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($identity, $this->hostPin()) . '.lock';
        self::assertTrue(symlink($this->base, $lock));
        $this->assertPublishFails($this->writer(identity: $identity));
    }

    public function test_target_symlink_hardlink_and_unsafe_directory_are_rejected(): void
    {
        $symlinkDirectory = $this->base . '/target-symlink';
        self::assertTrue(mkdir($symlinkDirectory, 0o700));
        $symlinkIdentity = $this->identity($symlinkDirectory);
        $symlinkTarget = $symlinkDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($symlinkIdentity, $this->hostPin());
        self::assertTrue(symlink($this->base, $symlinkTarget));
        $this->assertPublishFails($this->writer(identity: $symlinkIdentity));

        $hardlinkDirectory = $this->base . '/target-hardlink';
        self::assertTrue(mkdir($hardlinkDirectory, 0o700));
        $hardlinkIdentity = $this->identity($hardlinkDirectory);
        $hardlinkTarget = $hardlinkDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($hardlinkIdentity, $this->hostPin());
        self::assertSame(3, file_put_contents($hardlinkTarget, "{}\n"));
        self::assertTrue(chmod($hardlinkTarget, 0o644));
        self::assertTrue(link($hardlinkTarget, $hardlinkTarget . '.alias'));
        $this->assertPublishFails($this->writer(identity: $hardlinkIdentity));

        $unsafeDirectory = $this->base . '/unsafe-mode';
        self::assertTrue(mkdir($unsafeDirectory, 0o770));
        self::assertTrue(chmod($unsafeDirectory, 0o770));
        $unsafeIdentity = $this->identity($unsafeDirectory);
        $this->assertPublishFails($this->writer(identity: $unsafeIdentity));
    }

    public function test_non_root_owned_evidence_directory_is_rejected(): void
    {
        $identity = $this->identity();
        $lstat = function (string $path): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->directory) {
                $entry['uid'] = 10001;
            }

            return $entry;
        };

        $this->assertPublishFails($this->writer(identity: $identity, lstat: $lstat));
    }

    public function test_wrong_duplicate_and_malformed_mountinfo_fail_closed(): void
    {
        foreach (['ro', 'duplicate', 'malformed'] as $case) {
            $directory = $this->base . '/' . $case;
            self::assertTrue(mkdir($directory, 0o700));
            $mount = match ($case) {
                'ro' => $this->mountInfo('ro', $directory),
                'duplicate' => $this->mountInfo('rw', $directory) . $this->mountInfo('rw', $directory),
                default => "malformed\n",
            };
            $this->assertPublishFails($this->writer(
                identity: $this->identity($directory),
                mountInfo: static fn(): string => $mount,
            ));
        }
    }

    public function test_rolling_back_clock_and_expired_inflight_lease_fail_closed(): void
    {
        foreach (['rollback', 'expired'] as $case) {
            $directory = $this->base . '/clock-' . $case;
            self::assertTrue(mkdir($directory, 0o700));
            $values = match ($case) {
                'rollback' => [100, 99, 99],
                default => [100, 10_000_000_101, 10_000_000_101],
            };
            $clock = static function () use (&$values): int {
                return array_shift($values) ?? 10_000_000_101;
            };
            $this->assertPublishFails($this->writer(identity: $this->identity($directory), clock: $clock));
        }
    }

    public function test_equal_issued_time_on_second_publish_fails_closed(): void
    {
        $values = [100, 100, 100, 100];
        $clock = static function () use (&$values): int {
            return array_shift($values) ?? 100;
        };
        $writer = $this->writer(clock: $clock);
        $writer->publish();

        $this->assertPublishFails($writer);
    }

    public function test_revoke_is_durable_and_missing_target_is_idempotent_for_new_writer(): void
    {
        $writer = $this->writer();
        $writer->publish();
        $writer->revoke();
        self::assertFileDoesNotExist($this->path());
        unset($writer);
        gc_collect_cycles();

        $this->writer()->revoke();
        self::assertFileDoesNotExist($this->path());
    }

    public function test_revoke_unlink_ambiguity_is_failure_and_terminal(): void
    {
        $writer = $this->writer();
        $writer->publish();
        unset($writer);
        gc_collect_cycles();
        $writer = $this->writer(unlink: static fn(string $_path): bool => false);

        try {
            $writer->revoke();
            self::fail('Ambiguous revoke was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat revoke failed.', $error->getMessage());
        }
        $this->assertPublishFails($writer);
    }

    public function test_lstat_false_cannot_hide_existing_target_from_restart_or_revoke(): void
    {
        $writer = $this->writer();
        $writer->publish();
        unset($writer);
        gc_collect_cycles();
        $target = $this->path();
        $lstat = static fn(string $path): array|false => $path === $target
            ? false
            : self::rootStat(@lstat($path));

        $this->assertPublishFails($this->writer(lstat: $lstat));
        $revoker = $this->writer(lstat: $lstat);
        try {
            $revoker->revoke();
            self::fail('Ambiguous target absence was accepted by revoke.');
        } catch (\RuntimeException $error) {
            self::assertSame('Production EFS heartbeat revoke failed.', $error->getMessage());
        }
        self::assertFileExists($target);
    }

    public function test_combined_record_over_reader_limit_is_rejected_before_target_publish(): void
    {
        $identity = $this->oversizedHeartbeatIdentity();
        $writer = $this->writer(identity: $identity);

        $this->assertPublishFails($writer);
        $path = $this->directory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($identity, $this->hostPin());
        self::assertFileDoesNotExist($path);
    }

    public function test_old_epoch_record_is_not_touched_and_no_efs_path_is_accessed(): void
    {
        $oldPin = $this->hostPin(['epoch' => str_repeat('f', 32)]);
        $oldPath = $this->directory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($this->identity(), $oldPin);
        self::assertSame(3, file_put_contents($oldPath, "{}\n"));
        self::assertTrue(chmod($oldPath, 0o644));
        $paths = [];
        $lstat = static function (string $path) use (&$paths): array|false {
            $paths[] = $path;

            return self::rootStat(@lstat($path));
        };
        $this->writer(lstat: $lstat)->publish();

        self::assertFileExists($oldPath);
        foreach ($paths as $path) {
            self::assertFalse(str_starts_with($path, '/nonexistent-efs'));
        }
    }

    private function assertPublishFails(ProductionEfsWatcherEvidenceFileWriter $writer): void
    {
        $failed = false;
        try {
            $writer->publish();
        } catch (\RuntimeException $error) {
            $failed = true;
            self::assertStringContainsString('heartbeat', $error->getMessage());
        }
        self::assertTrue($failed, 'Unsafe heartbeat publish was accepted.');
    }

    /**
     * @param (Closure(): int)|null $clock
     * @param (Closure(): string)|null $bootId
     * @param (Closure(): string)|null $mountInfo
     * @param (Closure(string): (array<array-key, int>|false))|null $lstat
     * @param (Closure(resource): bool)|null $sync
     * @param (Closure(string, string): bool)|null $rename
     * @param (Closure(string): bool)|null $unlink
     * @param (Closure(string, string): void)|null $fault
     */
    private function writer(
        ?ProductionEfsIdentity $identity = null,
        ?Closure $clock = null,
        ?Closure $bootId = null,
        ?Closure $mountInfo = null,
        ?Closure $lstat = null,
        ?Closure $sync = null,
        ?Closure $rename = null,
        ?Closure $unlink = null,
        ?Closure $fault = null,
    ): ProductionEfsWatcherEvidenceFileWriter {
        $identity ??= $this->identity();
        $method = new \ReflectionMethod(ProductionEfsWatcherEvidenceFileWriter::class, 'withIoForTests');
        $writer = $method->invoke(
            null,
            $identity,
            $this->hostPin(),
            10_000_000_000,
            $clock ?? function (): int {
                $this->clock += 1_000_000;

                return $this->clock;
            },
            $bootId ?? static fn(): string => self::bootId(),
            $mountInfo ?? fn(): string => $this->mountInfo('rw', $identity->evidenceDirectory),
            $lstat ?? static fn(string $path): array|false => self::rootStat(@lstat($path)),
            static fn($handle): array|false => self::rootStat(@fstat($handle)),
            $sync ?? static fn($_handle): bool => true,
            $rename ?? static fn(string $source, string $target): bool => @rename($source, $target),
            $unlink ?? static fn(string $path): bool => @unlink($path),
            $fault ?? static function (string $_event, string $_path): void {},
        );
        self::assertInstanceOf(ProductionEfsWatcherEvidenceFileWriter::class, $writer);

        return $writer;
    }

    private function oversizedHeartbeatIdentity(): ProductionEfsIdentity
    {
        for ($length = 1500; $length < 2000; $length += 10) {
            $mountPoint = '/' . str_repeat('m', $length);
            $json = json_encode([
                'schemaVersion' => 2,
                'profile' => 'production',
                'deploymentId' => str_repeat('a', 32),
                'instanceId' => str_repeat('b', 32),
                'efsId' => 'fs-0123456789abcdef0',
                'sourceSha256' => str_repeat('c', 64),
                'metadataDriver' => 'postgres',
                'mountPoint' => $mountPoint,
                'storageRoot' => $mountPoint . '/objects',
                'scratchPath' => '/nonexistent-local-scratch',
                'evidenceDirectory' => $this->directory,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 4096) {
                continue;
            }
            $identity = ProductionEfsIdentity::fromJson($json);
            $evidenceJson = \OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidence::issue(
                $identity,
                $this->hostPin(),
                1,
                1,
            )->toJson();
            if (strlen($evidenceJson) > 4096) {
                return $identity;
            }
        }

        self::fail('Could not construct an oversized combined heartbeat fixture.');
    }

    private function path(): string
    {
        return $this->directory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($this->identity(), $this->hostPin());
    }

    private function identity(?string $directory = null): ProductionEfsIdentity
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
            'evidenceDirectory' => $directory ?? $this->directory,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $changes */
    private function hostPin(array $changes = []): ProductionEfsHostPin
    {
        return ProductionEfsHostPin::fromJson(json_encode(array_replace([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'containerId' => str_repeat('d', 64),
            'bootId' => self::bootId(),
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
        ], $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function mountInfo(string $mode, ?string $directory = null): string
    {
        $directory ??= $this->directory;

        return "1 0 0:1 / / rw,relatime - ext4 /dev/root rw\n"
            . "2 1 0:2 / {$directory} {$mode},relatime - tmpfs tmpfs {$mode}\n";
    }

    private static function bootId(): string
    {
        return '11111111-1234-1234-1234-123456789abc';
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>|false
     */
    private static function rootStat(array|false $entry): array|false
    {
        if (is_array($entry)) {
            $entry['uid'] = 0;
        }

        return $entry;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
        }
        @rmdir($path);
    }
}
