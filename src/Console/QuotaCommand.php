<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'quotas', description: 'Manage per-account S3 quotas')]
final class QuotaCommand extends Command
{
    private const array VALID_ACTIONS = ['set', 'show', 'list', 'delete'];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: set, show, list, delete')
            ->addArgument('owner-id', InputArgument::OPTIONAL, 'Owner/account ID for set/show/delete')
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for metadata database', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Storage path used to derive SQLite metadata path', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'Explicit SQLite metadata database path')
            ->addOption('max-buckets', null, InputOption::VALUE_REQUIRED, 'Maximum buckets for the account (0 = unlimited)', '0')
            ->addOption('max-objects-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum objects per bucket (0 = unlimited)', '0')
            ->addOption('max-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per bucket (0 = unlimited)', '0')
            ->addOption('max-bytes', null, InputOption::VALUE_REQUIRED, 'Maximum bytes for the account (0 = unlimited)', '0')
            ->addOption('max-multipart-uploads-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum active multipart uploads per bucket', '0')
            ->addOption('max-multipart-uploads-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum active multipart uploads per owner', '0')
            ->addOption('max-multipart-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum staged multipart bytes per bucket', '0')
            ->addOption('max-multipart-bytes-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum staged multipart bytes per owner', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        if (! in_array($action, self::VALID_ACTIONS, true)) {
            $io->error("Unknown action '{$action}'. Expected: " . implode(', ', self::VALID_ACTIONS));

            return Command::FAILURE;
        }

        $metadata = $this->buildMetadataStore($input, $io);
        if ($metadata === null) {
            return Command::FAILURE;
        }

        return match ($action) {
            'set' => $this->handleSet($input, $io, $metadata),
            'show' => $this->handleShow($input, $io, $metadata),
            'list' => $this->handleList($io, $metadata),
            'delete' => $this->handleDelete($input, $io, $metadata),
        };
    }

    private function buildMetadataStore(InputInterface $input, SymfonyStyle $io): ?MetadataStore
    {
        $driver = (string) $input->getOption('metadata-driver');
        $config = ['dsn' => (string) $input->getOption('metadata-dsn')];

        if ($driver === 'sqlite') {
            $metadataPath = $input->getOption('metadata-path');
            if ($metadataPath !== null && $metadataPath !== '') {
                $config['path'] = (string) $metadataPath;
            } else {
                $storagePath = $input->getOption('storage-path');
                if ($storagePath === null || $storagePath === '') {
                    $io->error('--storage-path or --metadata-path is required for the sqlite metadata driver.');

                    return null;
                }

                $config['path'] = rtrim((string) $storagePath, '/') . '/metadata.sqlite';
            }
        }

        try {
            return MetadataStoreFactory::create($driver, $config);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return null;
        }
    }

    private function handleSet(InputInterface $input, SymfonyStyle $io, MetadataStore $metadata): int
    {
        $ownerId = $this->requireOwnerId($input, $io);
        if ($ownerId === null) {
            return Command::FAILURE;
        }

        try {
            $quota = new QuotaConfig(
                maxBucketsPerOwner: $this->optionInt($input, 'max-buckets'),
                maxObjectsPerBucket: $this->optionInt($input, 'max-objects-per-bucket'),
                maxBytesPerBucket: $this->optionInt($input, 'max-bytes-per-bucket'),
                maxBytesPerOwner: $this->optionInt($input, 'max-bytes'),
                maxMultipartUploadsPerBucket: $this->optionInt($input, 'max-multipart-uploads-per-bucket'),
                maxMultipartUploadsPerOwner: $this->optionInt($input, 'max-multipart-uploads-per-owner'),
                maxMultipartBytesPerBucket: $this->optionInt($input, 'max-multipart-bytes-per-bucket'),
                maxMultipartBytesPerOwner: $this->optionInt($input, 'max-multipart-bytes-per-owner'),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $metadata->putAccountQuota($ownerId, $quota);
        $io->success("Quota saved for owner {$ownerId}.");
        $this->renderQuota($io, $ownerId, $quota);

        return Command::SUCCESS;
    }

    private function handleShow(InputInterface $input, SymfonyStyle $io, MetadataStore $metadata): int
    {
        $ownerId = $this->requireOwnerId($input, $io);
        if ($ownerId === null) {
            return Command::FAILURE;
        }

        $quota = $metadata->getAccountQuota($ownerId);
        if ($quota === null) {
            $io->info("No account-specific quota configured for owner {$ownerId}.");

            return Command::SUCCESS;
        }

        $this->renderQuota($io, $ownerId, $quota);

        return Command::SUCCESS;
    }

    private function handleList(SymfonyStyle $io, MetadataStore $metadata): int
    {
        $quotas = $metadata->listAccountQuotas();
        if ($quotas === []) {
            $io->info('No account-specific quotas configured.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($quotas as $ownerId => $quota) {
            $rows[] = [
                $ownerId,
                $quota->maxBucketsPerOwner,
                $quota->maxObjectsPerBucket,
                $quota->maxBytesPerBucket,
                $quota->maxBytesPerOwner,
            ];
        }

        $io->table(
            ['Owner ID', 'Max Buckets', 'Max Objects/Bucket', 'Max Bytes/Bucket', 'Max Bytes/Owner'],
            $rows,
        );

        return Command::SUCCESS;
    }

    private function handleDelete(InputInterface $input, SymfonyStyle $io, MetadataStore $metadata): int
    {
        $ownerId = $this->requireOwnerId($input, $io);
        if ($ownerId === null) {
            return Command::FAILURE;
        }

        $metadata->deleteAccountQuota($ownerId);
        $io->success("Quota deleted for owner {$ownerId}.");

        return Command::SUCCESS;
    }

    private function requireOwnerId(InputInterface $input, SymfonyStyle $io): ?string
    {
        $ownerId = $input->getArgument('owner-id');
        if ($ownerId === null || $ownerId === '') {
            $io->error('Owner ID argument is required for this action.');

            return null;
        }

        return (string) $ownerId;
    }

    private function optionInt(InputInterface $input, string $name): int
    {
        $value = (string) $input->getOption($name);
        if (! preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException("--{$name} must be an integer >= 0.");
        }

        return (int) $value;
    }

    private function renderQuota(SymfonyStyle $io, string $ownerId, QuotaConfig $quota): void
    {
        $io->definitionList(
            ['Owner ID' => $ownerId],
            ['Max Buckets' => (string) $quota->maxBucketsPerOwner],
            ['Max Objects/Bucket' => (string) $quota->maxObjectsPerBucket],
            ['Max Bytes/Bucket' => (string) $quota->maxBytesPerBucket],
            ['Max Bytes/Owner' => (string) $quota->maxBytesPerOwner],
            ['Max Multipart Uploads/Bucket' => (string) $quota->maxMultipartUploadsPerBucket],
            ['Max Multipart Uploads/Owner' => (string) $quota->maxMultipartUploadsPerOwner],
            ['Max Multipart Bytes/Bucket' => (string) $quota->maxMultipartBytesPerBucket],
            ['Max Multipart Bytes/Owner' => (string) $quota->maxMultipartBytesPerOwner],
        );
    }
}
