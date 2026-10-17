<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use OpsFour\S3Server\Metadata\ExternalStorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use OpsFour\S3Server\Metadata\SqliteStorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Metadata\StorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Runtime\LocalStorageRootLocks;
use OpsFour\S3Server\Storage\LocalStorageAuditRoots;
use OpsFour\S3Server\Storage\StorageIntegrityAuditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'storage:audit', description: 'Verify local storage references while the server is offline (non-repairing)')]
final class StorageAuditCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'PostgreSQL or MySQL DSN', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'Explicit SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null)
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Single STANDARD-tier local root (shorthand)', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('storage-tier-root', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Byte-exact local tier mapping: TIER=/canonical/root')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Metadata rows per page, 1-10000', '5000')
            ->addOption('max-errors', null, InputOption::VALUE_REQUIRED, 'Maximum issue rows to print, 0-10000', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storagePath = $input->getOption('storage-path');
        $pageSize = filter_var($input->getOption('page-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10_000]]);
        $maxErrors = filter_var($input->getOption('max-errors'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10_000]]);
        if ((! is_string($storagePath) && $storagePath !== null) || $pageSize === false || $maxErrors === false) {
            $io->error('Valid local tier mappings, --page-size 1-10000, and --max-errors 0-10000 are required.');

            return Command::FAILURE;
        }

        $references = null;
        $locks = null;
        try {
            $roots = new LocalStorageAuditRoots($this->tierMappings($input));
            $locks = LocalStorageRootLocks::create();
            $locks->acquireRoots($roots->roots());
            $references = $this->buildEnumerator($input, $roots->rootFor('STANDARD') ?? '');
            $result = (new StorageIntegrityAuditor($roots, $references, $pageSize, $maxErrors))->run();
        } catch (\Throwable $error) {
            $io->error(sprintf('Storage integrity audit failed (%s).', $error::class));

            return Command::FAILURE;
        } finally {
            try {
                $references?->close();
            } catch (\Throwable) {
                // Closing a read-only pool cannot change the completed audit result.
            }
            $locks?->release();
        }

        $rows = [];
        foreach ($result->sourceCounts as $source => $count) {
            $rows[] = [$source, $count];
        }
        $io->table(['Reference source', 'Checked'], $rows);
        if ($result->reportedIssues !== []) {
            $io->table(
                ['Source', 'Reference SHA-256', 'Tier SHA-256', 'Path SHA-256', 'Reason'],
                array_map(static fn(array $issue): array => [
                    $issue['source'],
                    $issue['reference_sha256'],
                    $issue['tier_sha256'],
                    $issue['path_sha256'],
                    $issue['reason'],
                ], $result->reportedIssues),
            );
        }
        $io->writeln(sprintf('Checked: %d; failed: %d; reported: %d.', $result->checked, $result->failed, count($result->reportedIssues)));

        if ($result->failed > 0) {
            $io->error('Storage integrity audit found invalid references. No object bytes or metadata were changed.');

            return Command::FAILURE;
        }

        $io->success('Storage integrity audit passed. No object bytes or metadata were changed.');

        return Command::SUCCESS;
    }

    private function buildEnumerator(InputInterface $input, string $storagePath): StorageIntegrityReferenceEnumerator
    {
        $driver = (string) $input->getOption('metadata-driver');
        $dsn = (string) $input->getOption('metadata-dsn');

        return match ($driver) {
            'sqlite' => new SqliteStorageIntegrityReferenceEnumerator($this->sqlitePath($input, $storagePath)),
            'postgres' => ExternalStorageIntegrityReferenceEnumerator::forPostgresDsn($dsn),
            'mysql' => ExternalStorageIntegrityReferenceEnumerator::forMysqlDsn($dsn),
            default => throw new \InvalidArgumentException('Unknown metadata driver.'),
        };
    }

    private function sqlitePath(InputInterface $input, string $storagePath): string
    {
        $metadataPath = $input->getOption('metadata-path');
        if (is_string($metadataPath) && $metadataPath !== '') {
            return SqliteMetadataPathResolver::resolve('', $metadataPath);
        }
        if ($storagePath === '') {
            throw new \InvalidArgumentException('SQLite requires --metadata-path or a STANDARD local tier mapping.');
        }

        return SqliteMetadataPathResolver::resolve($storagePath);
    }

    /** @return list<array{tier: string, root: string}> */
    private function tierMappings(InputInterface $input): array
    {
        $mappings = [];
        $storagePath = $input->getOption('storage-path');
        if (is_string($storagePath) && $storagePath !== '') {
            $mappings[] = ['tier' => 'STANDARD', 'root' => $storagePath];
        }
        $configured = $input->getOption('storage-tier-root');
        if (! is_array($configured)) {
            throw new \InvalidArgumentException('Local tier mappings are invalid.');
        }
        foreach ($configured as $mapping) {
            if (! is_string($mapping)) {
                throw new \InvalidArgumentException('Local tier mappings are invalid.');
            }
            $separator = strpos($mapping, '=');
            if ($separator === false) {
                throw new \InvalidArgumentException('Local tier mappings must use TIER=/canonical/root.');
            }
            $mappings[] = [
                'tier' => substr($mapping, 0, $separator),
                'root' => substr($mapping, $separator + 1),
            ];
        }

        return $mappings;
    }
}
