<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

use Illuminate\Console\Command;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\AccountQuotaWriter;
use OpsFour\S3Server\Quota\OwnerIdValidator;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaValueParser;

/**
 * Artisan command for managing per-account S3 quotas.
 */
final class S3QuotaCommand extends Command
{
    /** @var string */
    protected $signature = 's3:quotas
        {action : Action to perform: set, show, list, delete}
        {owner-id? : Owner/account ID (required for set/show/delete)}
        {--max-buckets=0 : Maximum buckets for the account}
        {--max-objects-per-bucket=0 : Maximum objects per bucket}
        {--max-bytes-per-bucket=0 : Maximum bytes per bucket}
        {--max-bytes=0 : Maximum bytes for the account}
        {--max-multipart-uploads-per-bucket=0 : Maximum active multipart uploads per bucket}
        {--max-multipart-uploads-per-owner=0 : Maximum active multipart uploads per owner}
        {--max-multipart-bytes-per-bucket=0 : Maximum staged multipart bytes per bucket}
        {--max-multipart-bytes-per-owner=0 : Maximum staged multipart bytes per owner}';

    /** @var string */
    protected $description = 'Manage per-account S3 quotas';

    private const array VALID_ACTIONS = ['set', 'show', 'list', 'delete'];

    private MetadataStore $metadata;

    public function handle(): int
    {
        $actionValue = $this->argument('action');
        $action = is_string($actionValue) ? $actionValue : '';
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            $this->error("Unknown action '{$action}'. Expected: " . implode(', ', self::VALID_ACTIONS));

            return self::FAILURE;
        }

        try {
            $this->metadata = app(MetadataStore::class);

            return match ($action) {
                'set' => $this->handleSet(),
                'show' => $this->handleShow(),
                'list' => $this->handleList(),
                'delete' => $this->handleDelete(),
            };
        } catch (\Throwable $e) {
            $this->error(sprintf('S3 quota operation failed (%s).', $e::class));

            return self::FAILURE;
        }
    }

    private function handleSet(): int
    {
        $ownerId = $this->requireOwnerId();
        if ($ownerId === null) {
            return self::FAILURE;
        }

        try {
            $quota = new QuotaConfig(
                maxBucketsPerOwner: $this->optionInt('max-buckets'),
                maxObjectsPerBucket: $this->optionInt('max-objects-per-bucket'),
                maxBytesPerBucket: $this->optionInt('max-bytes-per-bucket'),
                maxBytesPerOwner: $this->optionInt('max-bytes'),
                maxMultipartUploadsPerBucket: $this->optionInt('max-multipart-uploads-per-bucket'),
                maxMultipartUploadsPerOwner: $this->optionInt('max-multipart-uploads-per-owner'),
                maxMultipartBytesPerBucket: $this->optionInt('max-multipart-bytes-per-bucket'),
                maxMultipartBytesPerOwner: $this->optionInt('max-multipart-bytes-per-owner'),
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        AccountQuotaWriter::put($this->metadata, $ownerId, $quota);
        $this->info("Quota saved for owner {$ownerId}.");
        $this->renderQuota($ownerId, $quota);

        return self::SUCCESS;
    }

    private function handleShow(): int
    {
        $ownerId = $this->requireOwnerId();
        if ($ownerId === null) {
            return self::FAILURE;
        }

        $quota = $this->metadata->getAccountQuota($ownerId);
        if ($quota === null) {
            $this->info("No account-specific quota configured for owner {$ownerId}.");

            return self::SUCCESS;
        }

        $this->renderQuota($ownerId, $quota);

        return self::SUCCESS;
    }

    private function handleList(): int
    {
        $quotas = $this->metadata->listAccountQuotas();
        if ($quotas === []) {
            $this->info('No account-specific quotas configured.');

            return self::SUCCESS;
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
        $this->table(
            ['Owner ID', 'Max Buckets', 'Max Objects/Bucket', 'Max Bytes/Bucket', 'Max Bytes/Owner'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function handleDelete(): int
    {
        $ownerId = $this->requireOwnerId();
        if ($ownerId === null) {
            return self::FAILURE;
        }

        AccountQuotaWriter::delete($this->metadata, $ownerId);
        $this->info("Quota deleted for owner {$ownerId}.");

        return self::SUCCESS;
    }

    private function requireOwnerId(): ?string
    {
        $ownerId = $this->argument('owner-id');
        if (!is_string($ownerId)) {
            $this->error('Owner ID argument is required for this action.');

            return null;
        }

        try {
            OwnerIdValidator::assertValid($ownerId);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        return $ownerId;
    }

    private function optionInt(string $name): int
    {
        return QuotaValueParser::nonNegativeInteger($this->option($name), "--{$name}");
    }

    private function renderQuota(string $ownerId, QuotaConfig $quota): void
    {
        $this->table(
            ['Limit', 'Value'],
            [
                ['Owner ID', $ownerId],
                ['Max Buckets', $quota->maxBucketsPerOwner],
                ['Max Objects/Bucket', $quota->maxObjectsPerBucket],
                ['Max Bytes/Bucket', $quota->maxBytesPerBucket],
                ['Max Bytes/Owner', $quota->maxBytesPerOwner],
                ['Max Multipart Uploads/Bucket', $quota->maxMultipartUploadsPerBucket],
                ['Max Multipart Uploads/Owner', $quota->maxMultipartUploadsPerOwner],
                ['Max Multipart Bytes/Bucket', $quota->maxMultipartBytesPerBucket],
                ['Max Multipart Bytes/Owner', $quota->maxMultipartBytesPerOwner],
            ],
        );
    }
}
