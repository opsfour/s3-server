<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use OpsFour\S3Server\Metadata\ExternalStorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use OpsFour\S3Server\Metadata\SqliteStorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Metadata\StorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Storage\StorageIntegrityAuditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'storage:audit', description: 'Verify local storage references while the server is offline (read-only)')]
final class StorageAuditCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'PostgreSQL or MySQL DSN', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'Explicit SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null)
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Canonical local filesystem storage root', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Metadata rows per page, 1-500', '100')
            ->addOption('max-errors', null, InputOption::VALUE_REQUIRED, 'Maximum issue rows to print, 0-10000', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storagePath = $input->getOption('storage-path');
        $pageSize = filter_var($input->getOption('page-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $maxErrors = filter_var($input->getOption('max-errors'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10_000]]);
        if (! is_string($storagePath) || $storagePath === '' || $pageSize === false || $maxErrors === false) {
            $io->error('--storage-path, --page-size 1-500, and --max-errors 0-10000 are required.');

            return Command::FAILURE;
        }

        $references = null;
        try {
            $references = $this->buildEnumerator($input, $storagePath);
            $result = (new StorageIntegrityAuditor($storagePath, $references, $pageSize, $maxErrors))->run();
        } catch (\Throwable $error) {
            $io->error(sprintf('Storage integrity audit failed (%s).', $error::class));

            return Command::FAILURE;
        } finally {
            try {
                $references?->close();
            } catch (\Throwable) {
                // Closing a read-only pool cannot change the completed audit result.
            }
        }

        $rows = [];
        foreach ($result->sourceCounts as $source => $count) {
            $rows[] = [$source, $count];
        }
        $io->table(['Reference source', 'Checked'], $rows);
        if ($result->reportedIssues !== []) {
            $io->table(
                ['Source', 'Reference SHA-256', 'Path SHA-256', 'Reason'],
                array_map(static fn(array $issue): array => [
                    $issue['source'],
                    $issue['reference_sha256'],
                    $issue['path_sha256'],
                    $issue['reason'],
                ], $result->reportedIssues),
            );
        }
        $io->writeln(sprintf('Checked: %d; failed: %d; reported: %d.', $result->checked, $result->failed, count($result->reportedIssues)));

        if ($result->failed > 0) {
            $io->error('Storage integrity audit found invalid references. No changes were made.');

            return Command::FAILURE;
        }

        $io->success('Storage integrity audit passed. No changes were made.');

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

        return SqliteMetadataPathResolver::resolve($storagePath);
    }
}
