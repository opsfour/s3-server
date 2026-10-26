<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use OpsFour\S3Server\Factory\CredentialProviderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'credentials:migrate', description: 'Upgrade the database credential schema without starting an API node')]
final class CredentialsMigrateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('credentials-dsn', null, InputOption::VALUE_REQUIRED, 'PDO DSN for the credentials database', Environment::value('S3_CREDENTIALS_DSN'))
            ->addOption('credentials-username', null, InputOption::VALUE_REQUIRED, 'Database username', Environment::value('S3_CREDENTIALS_USERNAME'))
            ->addOption('credentials-password', null, InputOption::VALUE_REQUIRED, 'Database password', Environment::value('S3_CREDENTIALS_PASSWORD'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dsn = $input->getOption('credentials-dsn');
        if (! is_string($dsn) || $dsn === '') {
            $io->error('--credentials-dsn is required.');

            return Command::FAILURE;
        }

        try {
            CredentialProviderFactory::create('database', [
                'dsn' => $dsn,
                'username' => $input->getOption('credentials-username'),
                'password' => $input->getOption('credentials-password'),
            ], bootstrap: true);
        } catch (\Throwable $error) {
            $io->error(sprintf('Credential migration failed (%s).', $error::class));

            return Command::FAILURE;
        }

        $io->success('Credential schema is current.');

        return Command::SUCCESS;
    }
}
