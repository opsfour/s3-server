<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:migrate', description: 'Upgrade the metadata schema without starting an API node')]
final class MetadataMigrateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'PostgreSQL or MySQL DSN', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null)
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Storage path used to derive SQLite metadata path', getenv('S3_STORAGE_PATH') ?: null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $driver = (string) $input->getOption('metadata-driver');
        $config = [];

        if ($driver === 'sqlite') {
            $configuredPath = $input->getOption('metadata-path');
            if (is_string($configuredPath) && $configuredPath !== '') {
                $config['path'] = SqliteMetadataPathResolver::resolve('', $configuredPath);
            } else {
                $storagePath = $input->getOption('storage-path');
                if (! is_string($storagePath) || $storagePath === '') {
                    $io->error('--metadata-path or --storage-path is required for SQLite.');

                    return Command::FAILURE;
                }
                $config['path'] = SqliteMetadataPathResolver::resolve($storagePath);
            }
        } elseif (in_array($driver, ['postgres', 'mysql'], true)) {
            $dsn = $input->getOption('metadata-dsn');
            if (! is_string($dsn) || $dsn === '') {
                $io->error('--metadata-dsn is required for PostgreSQL and MySQL.');

                return Command::FAILURE;
            }
            $config['dsn'] = $dsn;
        } else {
            $io->error('Unknown metadata driver.');

            return Command::FAILURE;
        }

        try {
            MetadataStoreFactory::create($driver, $config, migrate: true);
        } catch (\Throwable $error) {
            $io->error(sprintf('Metadata migration failed (%s).', $error::class));

            return Command::FAILURE;
        }

        $io->success('Metadata schema is current.');

        return Command::SUCCESS;
    }
}
