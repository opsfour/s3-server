<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

use Illuminate\Console\Command;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\CredentialProvider;

/**
 * Artisan command for managing S3 server credentials.
 */
final class S3CredentialsCommand extends Command
{
    /** @var string */
    protected $signature = 's3:credentials
        {action : Action to perform: create, list, show, activate, deactivate, delete}
        {access-key-id? : Access key ID (required for show/activate/deactivate/delete)}
        {--owner-id= : Owner/tenant ID (required for create)}
        {--display-name= : Human-readable display name}
        {--access-key= : Custom access key ID (auto-generated if omitted)}
        {--secret-key= : Custom secret key (auto-generated if omitted)}';

    /** @var string */
    protected $description = 'Manage S3 server credentials';

    private const array VALID_ACTIONS = ['create', 'list', 'show', 'activate', 'deactivate', 'delete'];

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (!in_array($action, self::VALID_ACTIONS, true)) {
            $this->error("Unknown action '{$action}'. Expected: " . implode(', ', self::VALID_ACTIONS));

            return self::FAILURE;
        }

        $provider = app(CredentialProvider::class);

        if ($provider instanceof InMemoryCredentialProvider) {
            $this->error(
                "The 'memory' credential driver is ephemeral — changes would be lost on restart.\n"
                . "Set S3_CREDENTIALS_DRIVER to 'database' or 'file' in your .env for credential management.",
            );

            return self::FAILURE;
        }

        $manager = new CredentialManager($provider);

        return match ($action) {
            'create' => $this->handleCreate($manager),
            'list' => $this->handleList($manager),
            'show' => $this->handleShow($manager),
            'activate' => $this->handleToggle($manager, true),
            'deactivate' => $this->handleToggle($manager, false),
            'delete' => $this->handleDelete($manager),
        };
    }

    private function handleCreate(CredentialManager $manager): int
    {
        $ownerId = $this->option('owner-id');
        if ($ownerId === null || $ownerId === '') {
            $this->error('--owner-id is required when creating a credential.');

            return self::FAILURE;
        }

        $credential = $manager->createCredential(
            ownerId: $ownerId,
            displayName: (string) ($this->option('display-name') ?? ''),
            accessKeyId: $this->option('access-key'),
            secretAccessKey: $this->option('secret-key'),
        );

        $this->info('Credential created successfully.');
        $this->newLine();
        $this->line("  <comment>Access Key ID:</comment>     {$credential->accessKeyId}");
        $this->line("  <comment>Secret Access Key:</comment> {$credential->secretAccessKey}");
        $this->line("  <comment>Owner ID:</comment>          {$credential->ownerId}");
        $this->line('  <comment>Display Name:</comment>      ' . ($credential->displayName ?: '(none)'));
        $this->line('  <comment>Active:</comment>            Yes');
        $this->newLine();
        $this->warn('Store the secret access key securely. It cannot be retrieved again.');

        return self::SUCCESS;
    }

    private function handleList(CredentialManager $manager): int
    {
        $credentials = $manager->listCredentials();

        if ($credentials === []) {
            $this->info('No credentials found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Access Key ID', 'Owner ID', 'Display Name', 'Active'],
            array_map(fn($c) => [
                $c->accessKeyId,
                $c->ownerId,
                $c->displayName ?: '(none)',
                $c->isActive ? 'Yes' : 'No',
            ], $credentials),
        );
        $this->line(count($credentials) . ' credential(s) found.');

        return self::SUCCESS;
    }

    private function handleShow(CredentialManager $manager): int
    {
        $accessKeyId = $this->requireAccessKeyId();
        if ($accessKeyId === null) {
            return self::FAILURE;
        }

        $credential = $manager->getCredential($accessKeyId);
        if ($credential === null) {
            $this->error("Credential not found: {$accessKeyId}");

            return self::FAILURE;
        }

        $this->line("  <comment>Access Key ID:</comment>     {$credential->accessKeyId}");
        $this->line("  <comment>Secret Access Key:</comment> {$manager->maskSecretKey($credential->secretAccessKey)}");
        $this->line("  <comment>Owner ID:</comment>          {$credential->ownerId}");
        $this->line('  <comment>Display Name:</comment>      ' . ($credential->displayName ?: '(none)'));
        $this->line('  <comment>Active:</comment>            ' . ($credential->isActive ? 'Yes' : 'No'));

        return self::SUCCESS;
    }

    private function handleToggle(CredentialManager $manager, bool $active): int
    {
        $accessKeyId = $this->requireAccessKeyId();
        if ($accessKeyId === null) {
            return self::FAILURE;
        }

        try {
            $manager->setActive($accessKeyId, $active);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $action = $active ? 'activated' : 'deactivated';
        $this->info("Credential {$accessKeyId} has been {$action}.");

        return self::SUCCESS;
    }

    private function handleDelete(CredentialManager $manager): int
    {
        $accessKeyId = $this->requireAccessKeyId();
        if ($accessKeyId === null) {
            return self::FAILURE;
        }

        if (!$this->confirm("Are you sure you want to delete credential {$accessKeyId}?", false)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        if (!$manager->deleteCredential($accessKeyId)) {
            $this->error("Credential not found: {$accessKeyId}");

            return self::FAILURE;
        }

        $this->info("Credential {$accessKeyId} deleted.");

        return self::SUCCESS;
    }

    private function requireAccessKeyId(): ?string
    {
        $id = $this->argument('access-key-id');
        if ($id === null || $id === '') {
            $this->error('Access key ID argument is required for this action.');

            return null;
        }

        return (string) $id;
    }
}
