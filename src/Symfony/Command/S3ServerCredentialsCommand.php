<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony\Command;

use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\CredentialProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'opsfour:s3:credentials', description: 'Manage S3 server credentials')]
final class S3ServerCredentialsCommand extends Command
{
    private const array VALID_ACTIONS = ['create', 'list', 'show', 'activate', 'deactivate', 'delete'];

    public function __construct(
        private readonly CredentialProvider $credentialProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: create, list, show, activate, deactivate, delete')
            ->addArgument('access-key-id', InputArgument::OPTIONAL, 'Access key ID for show/activate/deactivate/delete')
            ->addOption('owner-id', null, InputOption::VALUE_REQUIRED, 'Owner/tenant ID for create')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Human-readable display name', '')
            ->addOption('access-key', null, InputOption::VALUE_REQUIRED, 'Custom access key ID')
            ->addOption('secret-key', null, InputOption::VALUE_REQUIRED, 'Custom secret access key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        if (! in_array($action, self::VALID_ACTIONS, true)) {
            $io->error("Unknown action '{$action}'. Expected: " . implode(', ', self::VALID_ACTIONS));

            return Command::FAILURE;
        }

        if ($this->credentialProvider instanceof InMemoryCredentialProvider) {
            $io->error(
                "The 'memory' credential driver is ephemeral — changes would be lost on restart.\n"
                . "Use 'database' or 'file' credentials in your Symfony config for credential management.",
            );

            return Command::FAILURE;
        }

        $manager = new CredentialManager($this->credentialProvider);

        return match ($action) {
            'create' => $this->handleCreate($input, $io, $manager),
            'list' => $this->handleList($io, $manager),
            'show' => $this->handleShow($input, $io, $manager),
            'activate' => $this->handleToggle($input, $io, $manager, true),
            'deactivate' => $this->handleToggle($input, $io, $manager, false),
            'delete' => $this->handleDelete($input, $io, $manager),
        };
    }

    private function handleCreate(InputInterface $input, SymfonyStyle $io, CredentialManager $manager): int
    {
        $ownerId = $input->getOption('owner-id');
        if ($ownerId === null || $ownerId === '') {
            $io->error('--owner-id is required when creating a credential.');

            return Command::FAILURE;
        }

        $credential = $manager->createCredential(
            ownerId: (string) $ownerId,
            displayName: (string) ($input->getOption('display-name') ?? ''),
            accessKeyId: $input->getOption('access-key'),
            secretAccessKey: $input->getOption('secret-key'),
        );

        $io->success('Credential created successfully.');
        $io->definitionList(
            ['Access Key ID' => $credential->accessKeyId],
            ['Secret Access Key' => $credential->secretAccessKey],
            ['Owner ID' => $credential->ownerId],
            ['Display Name' => $credential->displayName ?: '(none)'],
            ['Active' => 'Yes'],
        );
        $io->warning('Store the secret access key securely. It cannot be retrieved again.');

        return Command::SUCCESS;
    }

    private function handleList(SymfonyStyle $io, CredentialManager $manager): int
    {
        $credentials = $manager->listCredentials();

        if ($credentials === []) {
            $io->info('No credentials found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Access Key ID', 'Owner ID', 'Display Name', 'Active'],
            array_map(static fn($credential): array => [
                $credential->accessKeyId,
                $credential->ownerId,
                $credential->displayName ?: '(none)',
                $credential->isActive ? 'Yes' : 'No',
            ], $credentials),
        );
        $io->text(count($credentials) . ' credential(s) found.');

        return Command::SUCCESS;
    }

    private function handleShow(InputInterface $input, SymfonyStyle $io, CredentialManager $manager): int
    {
        $accessKeyId = $this->requireAccessKeyId($input, $io);
        if ($accessKeyId === null) {
            return Command::FAILURE;
        }

        $credential = $manager->getCredential($accessKeyId);
        if ($credential === null) {
            $io->error("Credential not found: {$accessKeyId}");

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Access Key ID' => $credential->accessKeyId],
            ['Secret Access Key' => $manager->maskSecretKey($credential->secretAccessKey)],
            ['Owner ID' => $credential->ownerId],
            ['Display Name' => $credential->displayName ?: '(none)'],
            ['Active' => $credential->isActive ? 'Yes' : 'No'],
        );

        return Command::SUCCESS;
    }

    private function handleToggle(
        InputInterface $input,
        SymfonyStyle $io,
        CredentialManager $manager,
        bool $active,
    ): int {
        $accessKeyId = $this->requireAccessKeyId($input, $io);
        if ($accessKeyId === null) {
            return Command::FAILURE;
        }

        try {
            $manager->setActive($accessKeyId, $active);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success("Credential {$accessKeyId} has been " . ($active ? 'activated' : 'deactivated') . '.');

        return Command::SUCCESS;
    }

    private function handleDelete(InputInterface $input, SymfonyStyle $io, CredentialManager $manager): int
    {
        $accessKeyId = $this->requireAccessKeyId($input, $io);
        if ($accessKeyId === null) {
            return Command::FAILURE;
        }

        if (! $io->confirm("Are you sure you want to delete credential {$accessKeyId}?", false)) {
            $io->text('Cancelled.');

            return Command::SUCCESS;
        }

        if (! $manager->deleteCredential($accessKeyId)) {
            $io->error("Credential not found: {$accessKeyId}");

            return Command::FAILURE;
        }

        $io->success("Credential {$accessKeyId} deleted.");

        return Command::SUCCESS;
    }

    private function requireAccessKeyId(InputInterface $input, SymfonyStyle $io): ?string
    {
        $id = $input->getArgument('access-key-id');
        if ($id === null || $id === '') {
            $io->error('Access key ID argument is required for this action.');

            return null;
        }

        return (string) $id;
    }
}
