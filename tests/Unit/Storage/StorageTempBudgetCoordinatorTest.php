<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageTempBudgetSnapshot;
use OpsFour\S3Server\Storage\StorageTempWorkerLease;
use PHPUnit\Framework\TestCase;

final class StorageTempBudgetCoordinatorTest extends TestCase
{
    public function test_live_reservation_cleanup_requires_its_capability(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $owner = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $peer = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $path = $tempDir . '/s3fw-put-capability';
        $reservation = $owner->start($path);
        $owner->reserve($reservation, 4);
        self::assertSame(4, file_put_contents($path, 'data'));
        try {
            try {
                $peer->cleanupPath($path, str_repeat('0', 32));
                self::fail('A peer cleaned a live reservation without its capability.');
            } catch (\RuntimeException) {
                self::assertFileExists($path);
                self::assertSame(4, $owner->snapshot()->reservedBytes);
            }
        } finally {
            $owner->cleanup($reservation);
            $peer->close();
            $owner->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_orphan_process_state_and_unaccounted_bytes_close_readiness(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $orphanState = $tempDir . '/.opsfour-s3-storage-budget/process-state-' . str_repeat('a', 32);
        $orphanData = $tempDir . '/s3fw-put-orphan';
        self::assertSame(2, file_put_contents($orphanState, '{}'));
        self::assertTrue(chmod($orphanState, 0600));
        self::assertSame(4, file_put_contents($orphanData, 'data'));
        try {
            $snapshot = $coordinator->snapshot();
            self::assertGreaterThanOrEqual(2, $snapshot->unsafeEntries);
            self::assertSame(4, $snapshot->orphanedBytes);
            self::assertFalse($snapshot->mutationReady());
        } finally {
            @unlink($orphanState);
            @unlink($orphanData);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_leftover_process_lease_tmp_closes_readiness_and_admission(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $tmp = $tempDir . '/.opsfour-s3-storage-budget/process-lease-' . str_repeat('b', 32) . '.tmp';
        self::assertSame(0, file_put_contents($tmp, ''));
        self::assertTrue(chmod($tmp, 0600));
        try {
            $snapshot = $coordinator->snapshot();
            self::assertGreaterThanOrEqual(1, $snapshot->unsafeEntries);
            self::assertFalse($snapshot->mutationReady());
            $this->expectException(SlowDownException::class);
            $coordinator->start($tempDir . '/s3fw-put-blocked');
        } finally {
            @unlink($tmp);
            @unlink($tempDir . '/s3fw-put-blocked');
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_live_process_state_requires_node_namespace(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $id = str_repeat('c', 32);
        $lease = $tempDir . '/.opsfour-s3-storage-budget/process-lease-' . $id;
        $state = $tempDir . '/.opsfour-s3-storage-budget/process-state-' . $id;
        $handle = fopen($lease, 'x+b');
        self::assertIsResource($handle);
        self::assertTrue(chmod($lease, 0600));
        self::assertTrue(flock($handle, LOCK_SH | LOCK_NB));
        self::assertNotFalse(file_put_contents($state, json_encode([
            'budget' => 16,
            'reserve' => 0,
            'namespaces' => [],
        ], JSON_THROW_ON_ERROR)));
        self::assertTrue(chmod($state, 0600));
        try {
            $snapshot = $coordinator->snapshot();
            self::assertGreaterThanOrEqual(1, $snapshot->unsafeEntries);
            self::assertFalse($snapshot->mutationReady());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($lease);
            @unlink($state);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }


    public function test_pre_reserved_capacity_transfers_to_exact_staging_inode_and_releases_cleanly(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $reservation = $coordinator->reserveCapacity(4, 'sse_object');
        $placeholder = $reservation->tempPath;
        $target = $tempDir . '/s3fw-put-transferred';

        try {
            $coordinator->retarget($reservation, $target, 'storage');
            self::assertFileDoesNotExist($placeholder);
            self::assertFileExists($target);
            self::assertSame(realpath($target), $reservation->tempPath);
            self::assertSame(4, $coordinator->snapshot()->reservedBytes);
            self::assertSame(4, file_put_contents($target, 'data'));
            self::assertTrue(unlink($target));
            $coordinator->release($reservation);
            self::assertSame(0, $coordinator->snapshot()->reservedBytes);
        } finally {
            @unlink($placeholder);
            @unlink($target);
            if ($reservation->isOpen()) {
                $coordinator->release($reservation);
            }
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_capacity_transfer_refuses_preexisting_destination_without_losing_reservation(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $reservation = $coordinator->reserveCapacity(4, 'sse_object');
        $placeholder = $reservation->tempPath;
        $target = $tempDir . '/s3fw-put-existing';
        self::assertSame(7, file_put_contents($target, 'foreign'));

        try {
            try {
                $coordinator->retarget($reservation, $target, 'storage');
                self::fail('Pre-reserved capacity adopted a pre-existing staging destination.');
            } catch (SlowDownException) {
                self::assertFileExists($placeholder);
                self::assertSame('foreign', file_get_contents($target));
                self::assertSame($placeholder, $reservation->tempPath);
                self::assertSame(4, $coordinator->snapshot()->reservedBytes);
            }
        } finally {
            @unlink($target);
            @unlink($placeholder);
            $coordinator->release($reservation);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_publish_pause_control_markers_are_bounded_and_unknown_entries_close_readiness(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $control = $tempDir . '/.s3-local-publish-pause';
        self::assertTrue(mkdir($control, 0700));
        $arm = $control . '/arm';
        self::assertSame(0, file_put_contents($arm, ''));
        self::assertTrue(chmod($arm, 0600));
        try {
            self::assertSame(0, $coordinator->snapshot()->unsafeEntries);
            $unknown = $control . '/payload';
            self::assertSame(1, file_put_contents($unknown, 'x'));
            self::assertTrue(chmod($unknown, 0600));
            self::assertSame(1, $coordinator->snapshot()->unsafeEntries);
            self::assertFalse($coordinator->snapshot()->mutationReady());
        } finally {
            @unlink($control . '/payload');
            @unlink($arm);
            @rmdir($control);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_unknown_regular_file_in_node_root_closes_readiness_and_admission(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $unknown = $tempDir . '/operator-file';
        $candidate = $tempDir . '/s3fw-put-candidate';
        try {
            self::assertSame(1, file_put_contents($unknown, 'x'));
            self::assertSame(1, $coordinator->snapshot()->unsafeEntries);
            self::assertFalse($coordinator->snapshot()->mutationReady());
            $this->expectException(SlowDownException::class);
            $coordinator->start($candidate);
        } finally {
            @unlink($candidate);
            @unlink($unknown);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_preexisting_managed_candidate_is_never_adopted_or_deleted(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $candidate = $tempDir . '/s3fw-put-candidate';
        try {
            self::assertSame(7, file_put_contents($candidate, 'foreign'));
            try {
                $coordinator->start($candidate);
                self::fail('A pre-existing candidate must not become coordinator-owned.');
            } catch (SlowDownException) {
                self::assertSame('foreign', file_get_contents($candidate));
            }
            $coordinator->snapshot();
            self::assertSame('foreign', file_get_contents($candidate));
        } finally {
            @unlink($candidate);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_writing_before_reserving_fails_closed(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $path = $tempDir . '/s3fw-put-unreserved';
        $reservation = $coordinator->start($path);
        try {
            self::assertSame(1, file_put_contents($path, 'x'));
            try {
                $coordinator->reserve($reservation, 1);
                self::fail('Already-written bytes must never be hidden by later reservation growth.');
            } catch (SlowDownException) {
                self::assertSame(0, $coordinator->snapshot()->reservedBytes);
                self::assertFalse($coordinator->snapshot()->mutationReady());
            }
        } finally {
            @unlink($path);
            $coordinator->release($reservation);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_simultaneous_process_construction_tolerates_control_creation_races(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('Process control is unavailable.');
        }
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $gate = $tempDir . '-gate';
        $processes = [];
        try {
            for ($index = 0; $index < 4; $index++) {
                $result = $tempDir . '-result-' . $index;
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/Support/storage-temp-coordinator-construction-worker.php', $tempDir, $gate, $result],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = [$process, $pipes, $result];
            }
            self::assertSame(2, file_put_contents($gate, 'go'));
            foreach ($processes as [$process, $pipes, $result]) {
                if (is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                $stdout = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
                $stderr = is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
                foreach ([$pipes[1], $pipes[2]] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                self::assertSame(0, proc_close($process), trim($stdout . "\n" . $stderr));
                self::assertSame('ready', file_get_contents($result));
            }
        } finally {
            foreach ($processes as [$process, $pipes, $result]) {
                if (is_resource($process) && proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
                @unlink($result);
            }
            @unlink($gate);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_live_coordinator_reloads_later_registered_nested_namespace_and_sees_unsafe_bytes(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $scratch = $tempDir . '/scratch';
        self::assertTrue(mkdir($scratch, 0700));
        $first = new StorageTempBudgetCoordinator($tempDir, 1024, 0);
        $second = new StorageTempBudgetCoordinator($tempDir, 1024, 0);
        try {
            $second->registerNamespace('scratch', $scratch);
            self::assertSame(0, $first->snapshot()->unsafeEntries);
            self::assertSame(1, file_put_contents($scratch . '/unaccounted', 'x'));
            self::assertGreaterThanOrEqual(1, $first->snapshot()->unsafeEntries);

            self::assertTrue(mkdir($tempDir . '/arbitrary', 0700));
            self::assertGreaterThanOrEqual(2, $first->snapshot()->unsafeEntries);
        } finally {
            @unlink($scratch . '/unaccounted');
            @rmdir($tempDir . '/arbitrary');
            $first->close();
            $second->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($scratch);
            @rmdir($tempDir);
        }
    }

    public function test_namespace_aliases_are_canonicalized_without_double_scanning_the_same_root(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        $scratch = $tempDir . '-scratch';
        self::assertTrue(mkdir($tempDir, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $alias = dirname($scratch) . '/./' . basename($scratch);
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 1024, 0);
        try {
            $coordinator->registerNamespace('scratch', $scratch);
            $coordinator->registerNamespace('scratch', $alias);
            $coordinator->registerNamespace('duplicate', $alias);
            self::assertSame(1, file_put_contents($scratch . '/unsafe', 'x'));
            self::assertSame(1, $coordinator->snapshot()->unsafeEntries);
        } finally {
            @unlink($scratch . '/unsafe');
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
            @rmdir($scratch);
        }
    }

    public function test_node_root_alias_preserves_control_directory_and_scans_the_root_once(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 1024, 0);
        try {
            $coordinator->registerNamespace('alias', $tempDir . '/.');
            self::assertSame(0, $coordinator->snapshot()->unsafeEntries);
            self::assertSame(1, file_put_contents($tempDir . '/unaccounted', 'x'));
            self::assertSame(1, $coordinator->snapshot()->unsafeEntries);
        } finally {
            @unlink($tempDir . '/unaccounted');
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_worker_lease_uses_explicit_coordinator_root_for_distinct_registered_stage_root(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        $scratch = $tempDir . '-scratch';
        self::assertTrue(mkdir($tempDir, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $coordinator->registerNamespace('scratch', $scratch);
        $path = $scratch . '/s3fw-put-' . str_repeat('a', 64);
        $reservation = $coordinator->start($path);
        $coordinator->reserve($reservation, 4);
        self::assertSame(4, file_put_contents($path, 'data'));
        try {
            $worker = StorageTempWorkerLease::acquire(
                $coordinator->tempDir(),
                $reservation->leasePath,
                $reservation->tempPath,
                $reservation->nonce,
            );
            $worker->close();
            try {
                StorageTempWorkerLease::acquire($scratch, $reservation->leasePath, $reservation->tempPath, $reservation->nonce);
                self::fail('A worker inferred the coordinator root from its stage path.');
            } catch (\InvalidArgumentException) {
            }
        } finally {
            @unlink($path);
            $coordinator->release($reservation);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
            @rmdir($scratch);
        }
    }

    public function test_snapshot_charges_full_budget_for_corrupt_state_and_zero_logical_capacity_does_not_hide_physical_pressure(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 8, 0);
        $path = $tempDir . '/s3fw-put-corrupt';
        $reservation = $coordinator->start($path);
        $state = dirname($reservation->leasePath) . '/state-' . substr(basename($reservation->leasePath), 6);
        try {
            self::assertSame(7, file_put_contents($state, '{broken'));
            $snapshot = $coordinator->snapshot();
            self::assertSame(8, $snapshot->reservedBytes);
            self::assertSame(0, $snapshot->logicalAvailableBytes);
            self::assertFalse($snapshot->mutationReady());
            self::assertFalse((new StorageTempBudgetSnapshot(8, 8, 0, 9, 10, 0, 1, 0, 0))->mutationReady());
        } finally {
            $reservation->close();
            @unlink($path);
            @unlink($reservation->leasePath);
            @unlink($state);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_unwritten_commitments_reduce_physical_headroom_for_other_coordinators(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $free = disk_free_space($tempDir);
        self::assertIsFloat($free);
        if ($free < 100_000_000) {
            self::markTestSkipped('Test filesystem does not have stable headroom for a commitment-only regression.');
        }
        $reserve = (int) $free - 64_000_000;
        $firstCoordinator = new StorageTempBudgetCoordinator($tempDir, 100_000_000, $reserve);
        $secondCoordinator = new StorageTempBudgetCoordinator($tempDir, 100_000_000, $reserve);
        $firstPath = $firstCoordinator->tempDir() . '/s3fw-put-first';
        $secondPath = $firstCoordinator->tempDir() . '/s3fw-put-second';
        $first = $firstCoordinator->start($firstPath);
        try {
            $firstCoordinator->reserve($first, 48_000_000);
            $snapshot = $secondCoordinator->snapshot();
            self::assertSame(48_000_000, $snapshot->reservedBytes);
            self::assertLessThan(20_000_000, $snapshot->effectiveAvailableBytes);

            $second = $secondCoordinator->start($secondPath);
            try {
                $secondCoordinator->reserve($second, 20_000_000);
                self::fail('A second process oversubscribed reserved-but-unwritten physical capacity.');
            } catch (SlowDownException) {
                self::assertSame(0, filesize($secondPath));
            } finally {
                @unlink($secondPath);
                $secondCoordinator->release($second);
            }
        } finally {
            @unlink($firstPath);
            $firstCoordinator->release($first);
            $firstCoordinator->close();
            $secondCoordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_control_state_and_namespace_registry_symlinks_are_never_followed(): void
    {
        if (! function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 8, 0);
        $path = $coordinator->tempDir() . '/s3fw-put-linkstate';
        $reservation = $coordinator->start($path);
        $control = dirname($reservation->leasePath);
        $state = $control . '/state-' . substr(basename($reservation->leasePath), 6);
        $target = $coordinator->tempDir() . '/foreign-control-target';
        self::assertSame(4, file_put_contents($target, 'safe'));
        try {
            self::assertTrue(unlink($state));
            self::assertTrue(symlink($target, $state));
            $snapshot = $coordinator->snapshot();
            self::assertSame(8, $snapshot->reservedBytes);
            self::assertSame('safe', file_get_contents($target));
            self::assertFalse($snapshot->mutationReady());

            self::assertTrue(unlink($state));
            $namespaces = $control . '/namespaces';
            self::assertTrue(unlink($namespaces));
            self::assertTrue(symlink($target, $namespaces));
            try {
                $coordinator->snapshot();
                self::fail('A namespace registry symlink was followed.');
            } catch (\RuntimeException) {
                self::assertSame('safe', file_get_contents($target));
            }
        } finally {
            $reservation->close();
            @unlink($path);
            @unlink($state);
            @unlink($control . '/namespaces');
            @unlink($reservation->leasePath);
            @unlink($target);
            $coordinator->close();
            foreach (glob($control . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($control);
            @rmdir($tempDir);
        }
    }

    public function test_registered_namespace_permissions_are_revalidated(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        $scratch = $tempDir . '-scratch';
        self::assertTrue(mkdir($tempDir, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 8, 0);
        $coordinator->registerNamespace('scratch', $scratch);
        try {
            self::assertTrue(chmod($scratch, 0750));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('identity changed');
            $coordinator->snapshot();
        } finally {
            @chmod($scratch, 0700);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
            @rmdir($scratch);
        }
    }

    public function test_worker_held_lease_preserves_budget_after_parent_exits(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('Process control is unavailable.');
        }
        $directory = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $tempDir = realpath($directory);
        self::assertIsString($tempDir);
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $stagePath = $tempDir . '/s3fw-put-' . str_repeat('a', 64);
        $nextPath = $tempDir . '/s3fw-put-' . str_repeat('b', 64);
        $parent = $coordinator->start($stagePath);
        $coordinator->reserve($parent, 4);
        self::assertSame(4, file_put_contents($stagePath, 'abcd'));
        $ready = $tempDir . '-worker-ready';
        $release = $tempDir . '-worker-release';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/storage-temp-worker-lease-process.php', $tempDir, $parent->leasePath, $stagePath, $ready, $release, $parent->nonce],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $next = null;
        try {
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($ready, 'The child did not hold the staging lease.');
            $parent->close(); // The API process exits while its child still uses the stage.
            $next = $coordinator->start($nextPath);
            try {
                $coordinator->reserve($next, 4);
                self::fail('An active child escaped the shared scratch budget.');
            } catch (SlowDownException) {
                self::assertFileExists($stagePath);
            }

            self::assertSame(0, file_put_contents($release, ''));
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);
            $coordinator->reserve($next, 4);
            self::assertFileDoesNotExist($stagePath);
        } finally {
            $parent->close();
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            if ($next !== null) {
                @unlink($nextPath);
                $coordinator->release($next);
            }
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @unlink($stagePath);
            @unlink($ready);
            @unlink($release);
            @rmdir($tempDir);
        }
    }

    public function test_shared_directory_rejects_different_budget_while_a_transfer_is_active(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $tempPath = $tempDir . '/s3fw-put-active';
        $reservation = $coordinator->start($tempPath);
        try {
            $coordinator->reserve($reservation, 4);
            self::assertSame(4, file_put_contents($tempPath, 'abcd'));
            try {
                new StorageTempBudgetCoordinator($tempDir, 6);
                self::fail('A second process with a different active budget was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('same budget', $error->getMessage());
            }
            try {
                new StorageTempBudgetCoordinator($tempDir, 5, 0);
                self::fail('A second process with a different filesystem reserve was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('filesystem reserve', $error->getMessage());
            }
        } finally {
            @unlink($tempPath);
            $coordinator->release($reservation);
        }

        $coordinator->close();
        self::assertInstanceOf(StorageTempBudgetCoordinator::class, new StorageTempBudgetCoordinator($tempDir, 6));
        foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($tempDir . '/.opsfour-s3-storage-budget');
        @rmdir($tempDir);
    }

    public function test_unleased_crash_files_are_not_swept_and_block_new_admission(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $oldPath = $tempDir . '/s3fw-put-' . str_repeat('c', 64);
        $newPath = $tempDir . '/s3fw-put-' . str_repeat('d', 64);
        $triggerPath = $tempDir . '/s3fw-put-trigger';
        file_put_contents($oldPath, 'old');
        file_put_contents($newPath, 'new');
        touch($oldPath, time() - 90_000);

        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        file_put_contents($triggerPath, 'trigger');
        try {
            try {
                (new StorageTempBudgetCoordinator($tempDir, 5))->start($triggerPath);
                self::fail('Unaccounted managed files did not close new admission.');
            } catch (SlowDownException) {
            }
            self::assertFileExists($oldPath, 'Unleased files have no identity evidence and must not be deleted.');
            self::assertFileExists($newPath);
            self::assertGreaterThanOrEqual(2, $coordinator->snapshot()->unsafeEntries);
        } finally {
            @unlink($triggerPath);
            @unlink($newPath);
            @unlink($oldPath);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_abandoned_worker_multipart_temp_file_is_recovered_by_its_lease(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        try {
            $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
            $workerPath = $tempDir . '/s3fw-mpu-crashed';
            $worker = $coordinator->start($workerPath);
            $coordinator->reserve($worker, 4);
            self::assertSame(4, file_put_contents($workerPath, 'abcd'));
            $worker->close(); // Simulate a worker exit without cleanup.

            $nextPath = $tempDir . '/s3fw-put-next';
            $next = $coordinator->start($nextPath);
            try {
                $coordinator->reserve($next, 4);
                self::assertSame(4, file_put_contents($nextPath, 'wxyz'));
                self::assertFileDoesNotExist($workerPath);
            } finally {
                @unlink($nextPath);
                $coordinator->release($next);
            }
        } finally {
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_corrupt_abandoned_state_remains_charged_until_operator_removes_unsafe_control_entry(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $corruptPath = $tempDir . '/s3fw-put-corrupt';
        $validPath = $tempDir . '/s3fw-put-valid';
        $nextPath = $tempDir . '/s3fw-put-next';
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 8);
        $corrupt = $coordinator->start($corruptPath);
        $valid = null;
        $next = null;
        try {
            $coordinator->reserve($corrupt, 4);
            self::assertSame(4, file_put_contents($corruptPath, 'abcd'));
            $valid = $coordinator->start($validPath);
            $coordinator->reserve($valid, 4);
            self::assertSame(4, file_put_contents($validPath, 'efgh'));
            $corrupt->close();
            $valid->close();
            $corruptState = dirname($corrupt->leasePath) . '/state-' . substr(basename($corrupt->leasePath), 6);
            self::assertSame(7, file_put_contents($corruptState, '{broken'));

            try {
                (new StorageTempBudgetCoordinator($tempDir, 8))->start($nextPath);
                self::fail('Corrupt state allowed a new temporary writer to start.');
            } catch (SlowDownException) {
                self::assertFileExists($corruptPath);
            }

            self::assertFileExists($corrupt->leasePath);
            self::assertFileExists($corruptState);
            self::assertFileDoesNotExist($validPath);
            self::assertFileDoesNotExist($valid->leasePath);
            self::assertFalse($coordinator->snapshot()->mutationReady());

            self::assertTrue(unlink($corruptPath));
            self::assertTrue(unlink($corrupt->leasePath));
            self::assertTrue(unlink($corruptState));
            $next = $coordinator->start($nextPath);
            $coordinator->reserve($next, 4);
        } finally {
            @unlink($nextPath);
            if ($next !== null) {
                $coordinator->release($next);
            }
            @unlink($corruptPath);
            @unlink($validPath);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_active_other_process_blocks_capacity_and_crash_reclaims_its_file(): void
    {
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $readyPath = $tempDir . '-worker-ready';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/storage-temp-reservation-worker.php', $tempDir, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $reservation = null;

        try {
            $deadline = microtime(true) + 5;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyPath, 'The storage reservation worker did not become ready.');

            $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
            $parentPath = $tempDir . '/s3fw-put-parent';
            $reservation = $coordinator->start($parentPath);

            try {
                $coordinator->reserve($reservation, 4);
                self::fail('The other process must count toward the shared staging budget.');
            } catch (SlowDownException) {
                self::assertFileExists($tempDir . '/s3fw-put-worker');
            }

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $coordinator->reserve($reservation, 4);
            self::assertFileDoesNotExist($tempDir . '/s3fw-put-worker');
        } finally {
            if ($reservation !== null) {
                @unlink($reservation->tempPath);
                (new StorageTempBudgetCoordinator($tempDir, 5))->release($reservation);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @unlink($readyPath);
            @unlink($tempDir . '/s3fw-put-worker');
            @unlink($tempDir . '/s3fw-put-parent');
            @rmdir($tempDir);
        }
    }

    public function test_incomplete_state_replacement_preserves_active_bytes(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $firstPath = $tempDir . '/s3fw-put-first';
        $secondPath = $tempDir . '/s3fw-put-second';
        $first = $coordinator->start($firstPath);
        $second = null;
        try {
            $coordinator->reserve($first, 4);
            self::assertSame(4, file_put_contents($firstPath, 'abcd'));
            $second = $coordinator->start($secondPath);
            $statePath = dirname($first->leasePath) . '/state-' . substr(basename($first->leasePath), 6);
            self::assertSame(7, file_put_contents($statePath . '.tmp', '{broken'));
            self::assertSame(7, file_put_contents(dirname($first->leasePath) . '/total', 'corrupt'));

            new StorageTempBudgetCoordinator($tempDir, 5);
            try {
                $coordinator->reserve($second, 1);
                self::fail('An unsafe incomplete control-file replacement allowed reservation growth.');
            } catch (SlowDownException) {
                self::assertSame(4, $coordinator->snapshot()->reservedBytes);
            }
            self::assertTrue(unlink($statePath . '.tmp'));
            $coordinator->reserve($second, 1);
            self::assertSame(1, file_put_contents($secondPath, 'z'));
            $this->expectException(SlowDownException::class);
            $coordinator->reserve($second, 1);
        } finally {
            @unlink($secondPath);
            if ($second !== null) {
                $coordinator->release($second);
            }
            @unlink($firstPath);
            $coordinator->release($first);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_physical_reserve_rejects_before_bytes_are_written_and_closes_readiness(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $free = disk_free_space($tempDir);
        self::assertIsFloat($free);
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 1024, (int) $free);
        $path = $tempDir . '/s3fw-put-pressure';
        $reservation = $coordinator->start($path);

        try {
            $snapshot = $coordinator->snapshot();
            self::assertSame(0, $snapshot->effectiveAvailableBytes);
            self::assertFalse($snapshot->mutationReady());
            try {
                $coordinator->reserve($reservation, 1);
                self::fail('Physical reserve pressure admitted staging bytes.');
            } catch (SlowDownException) {
                self::assertSame(0, filesize($path));
            }
        } finally {
            @unlink($path);
            $coordinator->release($reservation);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_abandoned_reservation_never_deletes_a_replaced_inode(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $path = $tempDir . '/s3fw-put-replaced';
        $reservation = $coordinator->start($path);
        $coordinator->reserve($reservation, 4);
        self::assertSame(4, file_put_contents($path, 'old!'));
        $reservation->close();
        self::assertTrue(unlink($path));
        self::assertSame(11, file_put_contents($path, 'replacement'));

        try {
            $snapshot = $coordinator->snapshot();
            self::assertSame('replacement', file_get_contents($path));
            self::assertGreaterThanOrEqual(1, $snapshot->unsafeEntries);
            self::assertFalse($snapshot->mutationReady());
            self::assertSame(4, $snapshot->reservedBytes);
        } finally {
            @unlink($path);
            $coordinator->snapshot();
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_abandoned_reservation_never_follows_or_deletes_a_symlink_replacement(): void
    {
        if (! function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 16, 0);
        $path = $tempDir . '/s3fw-put-link';
        $target = $tempDir . '/foreign';
        $reservation = $coordinator->start($path);
        $coordinator->reserve($reservation, 4);
        self::assertSame(4, file_put_contents($path, 'old!'));
        $reservation->close();
        self::assertTrue(unlink($path));
        self::assertSame(7, file_put_contents($target, 'foreign'));
        self::assertTrue(symlink($target, $path));

        try {
            $snapshot = $coordinator->snapshot();
            self::assertTrue(is_link($path));
            self::assertSame('foreign', file_get_contents($target));
            self::assertGreaterThanOrEqual(1, $snapshot->unsafeEntries);
            self::assertFalse($snapshot->mutationReady());
        } finally {
            @unlink($path);
            @unlink($target);
            $coordinator->snapshot();
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }
}
