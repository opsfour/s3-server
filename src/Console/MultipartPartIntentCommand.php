<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'multipart-part-intents', description: 'Inspect retained encrypted multipart-part write intents (read-only)')]
final class MultipartPartIntentCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('owner-id', InputArgument::REQUIRED, 'Owner/account ID whose retained intents are inspected')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Continue after this garbage-row ID', '0')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page, 1-500', '100')
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for metadata database', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Storage path used to derive SQLite metadata path', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'Explicit SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ownerId = (string) $input->getArgument('owner-id');
        $afterId = filter_var($input->getOption('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($ownerId === '' || $afterId === false || $limit === false) {
            $io->error('A nonempty owner ID, --after-id >= 0, and --limit between 1 and 500 are required.');

            return Command::FAILURE;
        }

        $driver = (string) $input->getOption('metadata-driver');
        $config = ['dsn' => (string) $input->getOption('metadata-dsn')];
        $sqlitePath = '';
        if ($driver === 'sqlite') {
            $metadataPath = $input->getOption('metadata-path');
            if (is_string($metadataPath) && $metadataPath !== '') {
                $sqlitePath = SqliteMetadataPathResolver::resolve('', $metadataPath);
            } else {
                $storagePath = $input->getOption('storage-path');
                if (! is_string($storagePath) || $storagePath === '') {
                    $io->error('--storage-path or --metadata-path is required for SQLite.');

                    return Command::FAILURE;
                }
                $sqlitePath = SqliteMetadataPathResolver::resolve($storagePath);
            }
        }

        try {
            $metadata = match ($driver) {
                'sqlite' => new SqliteMetadataStore($sqlitePath, readOnly: true),
                'postgres' => PostgresMetadataStore::fromDsn($config['dsn']),
                'mysql' => MysqlMetadataStore::fromDsn($config['dsn']),
                default => throw new \InvalidArgumentException('Unknown metadata driver.'),
            };

            $rows = $metadata->listMultipartPartWriteIntents($ownerId, $afterId, $limit);
        } catch (\Throwable $error) {
            $io->error(sprintf('Multipart-part intent inventory failed (%s).', $error::class));

            return Command::FAILURE;
        }

        $io->table(
            ['ID', 'Bucket', 'Tier', 'Path SHA-256', 'Status', 'Attempts', 'Created', 'Next attempt'],
            array_map(static fn(array $row): array => [
                $row['id'],
                $row['bucket'],
                $row['storage_tier'],
                $row['path_sha256'],
                $row['status'],
                $row['attempts'],
                $row['created_at'],
                gmdate('Y-m-d H:i:s', (int) $row['next_attempt_at']) . ' UTC',
            ], $rows),
        );
        if ($rows !== []) {
            $io->note('Continue with --after-id=' . $rows[array_key_last($rows)]['id'] . '. Rows remain durable; this command does not clean up storage or metadata.');
        }

        return Command::SUCCESS;
    }
}
