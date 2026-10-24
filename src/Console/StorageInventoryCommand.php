<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use Closure;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator;
use OpsFour\S3Server\Metadata\ExternalCutoverPathReferenceEnumerator;
use OpsFour\S3Server\Metadata\SqliteCutoverPathReferenceEnumerator;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use OpsFour\S3Server\Metadata\UtcMysqlConnector;
use OpsFour\S3Server\Exception\StorageInventoryScratchException;
use OpsFour\S3Server\Runtime\LocalStorageRootLocks;
use OpsFour\S3Server\Storage\StorageOrphanInventory;
use OpsFour\S3Server\Storage\StorageReferenceIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'storage:inventory', description: 'Report unreferenced local storage paths while all writers are stopped')]
final class StorageInventoryCommand extends Command
{
    /** @param (Closure(): (string|false))|null $mountInfoReader Test seam; production reads /proc/self/mountinfo. */
    public function __construct(private readonly ?Closure $mountInfoReader = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultScratchPath = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'PostgreSQL or MySQL DSN', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'Explicit SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null)
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Single STANDARD-tier local root', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('storage-tier-root', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Byte-exact local tier mapping JSON tuple: ["HOT","/canonical/root"]')
            ->addOption('default-storage-tier', null, InputOption::VALUE_REQUIRED, 'Default write tier for metadata rows without a persisted tier')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Rows per metadata or physical page, 1-500', '500')
            ->addOption('scratch-path', null, InputOption::VALUE_REQUIRED, 'Canonical directory for the bounded temporary inventory index', $defaultScratchPath)
            ->addOption('scratch-max-bytes', null, InputOption::VALUE_REQUIRED, 'Hard maximum bytes for the temporary inventory index', (string) StorageReferenceIndex::DEFAULT_MAX_BYTES)
            ->addOption('after-candidate', null, InputOption::VALUE_REQUIRED, 'Opaque cursor returned by the preceding candidate page')
            ->addOption('reveal-paths', null, InputOption::VALUE_NONE, 'Reveal candidate paths on this local terminal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageSize = filter_var($input->getOption('page-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($pageSize === false) {
            $io->error('--page-size must be between 1 and 500.');
            return Command::FAILURE;
        }
        $scratchMaxBytes = filter_var($input->getOption('scratch-max-bytes'), FILTER_VALIDATE_INT, ['options' => [
            'min_range' => StorageReferenceIndex::MIN_MAX_BYTES,
            'max_range' => StorageReferenceIndex::MAX_MAX_BYTES,
        ]]);
        if ($scratchMaxBytes === false) {
            $io->error(sprintf(
                '--scratch-max-bytes must be between %d and %d.',
                StorageReferenceIndex::MIN_MAX_BYTES,
                StorageReferenceIndex::MAX_MAX_BYTES,
            ));
            return Command::FAILURE;
        }

        $pool = null;
        $locks = null;
        try {
            [$roots, $defaultStorageTier] = $this->tierRoots($input);
            $scratchPath = $this->canonicalDirectory($input->getOption('scratch-path'), '--scratch-path');
            $afterCandidate = $this->decodeCandidateCursor($input->getOption('after-candidate'));
            $locks = LocalStorageRootLocks::create();
            $locks->acquireRoots(array_values($roots));
            [$references, $pool, $metadataPath] = $this->references($input, $roots[$defaultStorageTier]);
            $excluded = [];
            foreach ($roots as $root) {
                $excluded[] = hash('sha256', $root . '/.opsfour/runtime.lock');
            }
            if ($metadataPath !== null) {
                foreach ([$metadataPath, $metadataPath . '-wal', $metadataPath . '-shm', $metadataPath . '-journal'] as $path) {
                    $excluded[] = hash('sha256', $path);
                }
            }
            $result = (new StorageOrphanInventory(
                $roots,
                $defaultStorageTier,
                $references,
                $pageSize,
                (bool) $input->getOption('reveal-paths'),
                $excluded,
                $this->mountInfoReader,
                $scratchPath,
                $scratchMaxBytes,
                $afterCandidate,
            ))->run();
        } catch (StorageInventoryScratchException $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $error) {
            $io->error(sprintf('Storage inventory failed (%s).', $error::class));
            return Command::FAILURE;
        } finally {
            $pool?->close();
            $locks?->release();
        }

        if ($result->candidates !== []) {
            $io->table(
                (bool) $input->getOption('reveal-paths')
                    ? ['Tier', 'Path SHA-256', 'Bytes', 'Format', 'Path']
                    : ['Tier', 'Path SHA-256', 'Bytes', 'Format'],
                array_map(static function (array $row) use ($input): array {
                    $cells = [$row['storage_tier'], $row['path_sha256'], $row['size_bytes'] ?? '-', $row['format'] ?? '-'];
                    if ((bool) $input->getOption('reveal-paths')) {
                        $cells[] = $row['path'] ?? '-';
                    }
                    return $cells;
                }, $result->candidates),
            );
        }
        if ($result->nextCandidate !== null) {
            $io->note(sprintf(
                'This page is bounded to %d candidates; the exact total is shown below.',
                count($result->candidates),
            ));
            $io->writeln('Next candidate cursor: ' . self::encodeCandidateCursor($result->nextCandidate));
        }
        $io->writeln(sprintf(
            'Physical files: %d; metadata references: %d; orphan candidates: %d.',
            $result->physicalFiles,
            $result->metadataReferences,
            $result->orphanCandidates,
        ));
        $io->success('Storage inventory completed read-only. Review and quarantine candidates separately.');

        return Command::SUCCESS;
    }

    /** @return array{CutoverPathReferenceEnumerator, PostgresConnectionPool|MysqlConnectionPool|null, ?string} */
    private function references(InputInterface $input, string $standardRoot): array
    {
        $driver = (string) $input->getOption('metadata-driver');
        $dsn = (string) $input->getOption('metadata-dsn');
        if ($driver === 'sqlite') {
            $configured = $input->getOption('metadata-path');
            $path = is_string($configured) && $configured !== ''
                ? SqliteMetadataPathResolver::resolve('', $configured)
                : SqliteMetadataPathResolver::resolve($standardRoot);
            $canonicalPath = realpath($path);
            if ($canonicalPath === false || ! is_file($canonicalPath)) {
                throw new \InvalidArgumentException('SQLite metadata path must resolve to an existing file.');
            }
            return [new SqliteCutoverPathReferenceEnumerator($canonicalPath), null, $canonicalPath];
        }
        if ($dsn === '') {
            throw new \InvalidArgumentException('External metadata inventory requires --metadata-dsn.');
        }
        if ($driver === 'postgres') {
            $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
            return [ExternalCutoverPathReferenceEnumerator::forPostgres($pool), $pool, null];
        }
        if ($driver === 'mysql') {
            $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn), connector: new UtcMysqlConnector());
            return [ExternalCutoverPathReferenceEnumerator::forMysql($pool), $pool, null];
        }
        throw new \InvalidArgumentException('Unknown metadata driver.');
    }

    /** @return array{array<string, string>, string} */
    private function tierRoots(InputInterface $input): array
    {
        $roots = [];
        $storagePath = $input->getOption('storage-path');
        if (is_string($storagePath) && $storagePath !== '') {
            $roots['STANDARD'] = $this->canonicalRoot($storagePath);
        }
        $configured = $input->getOption('storage-tier-root');
        if (! is_array($configured)) {
            throw new \InvalidArgumentException('Local tier mappings are invalid.');
        }
        foreach ($configured as $mapping) {
            $decoded = is_string($mapping) ? json_decode($mapping, true, 3, JSON_THROW_ON_ERROR) : null;
            if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) !== 2
                || ! is_string($decoded[0]) || $decoded[0] === '' || ! is_string($decoded[1])) {
                throw new \InvalidArgumentException('Local tier mappings require exactly a two-string JSON tuple.');
            }
            if (array_key_exists($decoded[0], $roots)) {
                throw new \InvalidArgumentException('Local tier mappings must use unique tier names.');
            }
            $roots[$decoded[0]] = $this->canonicalRoot($decoded[1]);
        }
        if ($roots === []) {
            throw new \InvalidArgumentException('Storage inventory requires at least one local tier root.');
        }
        $configuredDefault = $input->getOption('default-storage-tier');
        if ($configuredDefault === null && count($roots) === 1) {
            $configuredDefault = array_key_first($roots);
        }
        if (! is_string($configuredDefault) || $configuredDefault === ''
            || ! array_key_exists($configuredDefault, $roots)) {
            throw new \InvalidArgumentException('Storage inventory requires --default-storage-tier when multiple tiers are mapped.');
        }

        return [$roots, $configuredDefault];
    }

    private function canonicalRoot(string $root): string
    {
        $canonical = realpath($root);
        if ($canonical === false || ! is_dir($canonical) || $canonical !== $root) {
            throw new \InvalidArgumentException('Local tier roots must be existing canonical directories.');
        }
        return $canonical;
    }

    private function canonicalDirectory(mixed $path, string $option): string
    {
        if (! is_string($path) || $path === '') {
            throw new \InvalidArgumentException($option . ' requires an existing canonical directory.');
        }
        $canonical = realpath($path);
        if ($canonical === false || ! is_dir($canonical) || $canonical !== $path) {
            throw new \InvalidArgumentException($option . ' requires an existing canonical directory.');
        }

        return $canonical;
    }

    /** @return array{storage_tier: string, path_sha256: string}|null */
    private function decodeCandidateCursor(mixed $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (! is_string($cursor) || strlen($cursor) > 8_192
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('Invalid --after-candidate cursor.');
        }
        $padding = (4 - strlen($cursor) % 4) % 4;
        $json = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
        if (! is_string($json)) {
            throw new \InvalidArgumentException('Invalid --after-candidate cursor.');
        }
        try {
            $decoded = json_decode($json, true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid --after-candidate cursor.');
        }
        if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) !== 2
            || ! is_string($decoded[0]) || $decoded[0] === '' || str_contains($decoded[0], "\0")
            || ! is_string($decoded[1]) || preg_match('/\A[a-f0-9]{64}\z/D', $decoded[1]) !== 1) {
            throw new \InvalidArgumentException('Invalid --after-candidate cursor.');
        }

        return ['storage_tier' => $decoded[0], 'path_sha256' => $decoded[1]];
    }

    /** @param array{storage_tier: string, path_sha256: string} $cursor */
    private static function encodeCandidateCursor(array $cursor): string
    {
        $json = json_encode([$cursor['storage_tier'], $cursor['path_sha256']], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
