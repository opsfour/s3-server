<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Console;

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Start the OpsFour S3-compatible server')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen address', getenv('S3_HOST') ?: '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port', getenv('S3_PORT') ?: '9000')
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Root directory for object storage', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('storage-driver', null, InputOption::VALUE_REQUIRED, 'Storage backend: filesystem|flysystem|memory', getenv('S3_STORAGE_DRIVER') ?: 'filesystem')
            ->addOption('storage-temp-dir', null, InputOption::VALUE_REQUIRED, 'Local staging directory for remote storage transfers', getenv('S3_STORAGE_TEMP_DIR') ?: sys_get_temp_dir())
            ->addOption('flysystem-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for remote Flysystem operations', getenv('S3_FLYSYSTEM_WORKERS') ?: '0')
            ->addOption('backing-bucket', null, InputOption::VALUE_REQUIRED, 'Backing S3 bucket for standalone Flysystem mode', getenv('S3_BACKING_BUCKET') ?: null)
            ->addOption('backing-region', null, InputOption::VALUE_REQUIRED, 'Backing S3 region', getenv('S3_BACKING_REGION') ?: null)
            ->addOption('backing-access-key', null, InputOption::VALUE_REQUIRED, 'Backing S3 access key', getenv('S3_BACKING_ACCESS_KEY') ?: null)
            ->addOption('backing-secret-key', null, InputOption::VALUE_REQUIRED, 'Backing S3 secret key', getenv('S3_BACKING_SECRET_KEY') ?: null)
            ->addOption('backing-endpoint', null, InputOption::VALUE_REQUIRED, 'Backing S3-compatible endpoint', getenv('S3_BACKING_ENDPOINT') ?: null)
            ->addOption('backing-path-style', null, InputOption::VALUE_REQUIRED, 'Use path-style requests for backing S3 (true|false)', getenv('S3_BACKING_PATH_STYLE') === false ? 'false' : getenv('S3_BACKING_PATH_STYLE'))
            ->addOption('backing-prefix', null, InputOption::VALUE_REQUIRED, 'Optional key prefix in the backing S3 bucket', getenv('S3_BACKING_PREFIX') ?: '')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'AWS region identifier', getenv('S3_REGION') ?: 'us-east-1')
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for metadata database', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null)
            ->addOption('metadata-cache-ttl', null, InputOption::VALUE_REQUIRED, 'Metadata read cache TTL in seconds (0 disables)', getenv('S3_METADATA_CACHE_TTL') ?: '5')
            ->addOption('tls-cert', null, InputOption::VALUE_REQUIRED, 'Path to TLS certificate file', getenv('S3_TLS_CERT') ?: null)
            ->addOption('tls-key', null, InputOption::VALUE_REQUIRED, 'Path to TLS private key file', getenv('S3_TLS_KEY') ?: null)
            ->addOption('max-connections', null, InputOption::VALUE_REQUIRED, 'Maximum concurrent connections', getenv('S3_MAX_CONNECTIONS') ?: '10000')
            ->addOption('connection-idle-timeout', null, InputOption::VALUE_REQUIRED, 'Idle connection timeout in seconds', getenv('S3_CONNECTION_IDLE_TIMEOUT') ?: '60')
            ->addOption('body-size-limit', null, InputOption::VALUE_REQUIRED, 'Maximum request body size in bytes', getenv('S3_BODY_SIZE_LIMIT') ?: '5368709120')
            ->addOption('read-timeout', null, InputOption::VALUE_REQUIRED, 'Request stream inactivity timeout in seconds', getenv('S3_READ_TIMEOUT') ?: '300')
            ->addOption('write-timeout', null, InputOption::VALUE_REQUIRED, 'Response stream inactivity timeout in seconds', getenv('S3_WRITE_TIMEOUT') ?: '300')
            ->addOption('shutdown-drain-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds before warning that graceful shutdown is still draining', getenv('S3_SHUTDOWN_DRAIN_TIMEOUT') ?: '30')
            ->addOption('request-body-spool-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for request-body checksum spooling', getenv('S3_REQUEST_BODY_SPOOL_WORKERS') ?: '8')
            ->addOption('sqlite-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for SQLite metadata operations', getenv('S3_SQLITE_WORKERS') ?: '0')
            ->addOption('encryption-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for encryption', getenv('S3_ENCRYPTION_WORKERS') ?: '0')
            ->addOption('select-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for S3 Select', getenv('S3_SELECT_WORKERS') === false ? null : getenv('S3_SELECT_WORKERS'))
            ->addOption('encryption-threshold', null, InputOption::VALUE_REQUIRED, 'Encryption worker offload threshold in bytes', getenv('S3_ENCRYPTION_THRESHOLD') ?: '65536')
            ->addOption('enforce-min-part-size', null, InputOption::VALUE_REQUIRED, 'Enforce the S3 5 MiB minimum for non-final multipart parts (true|false)', getenv('S3_ENFORCE_MIN_PART_SIZE') === false ? 'true' : getenv('S3_ENFORCE_MIN_PART_SIZE'))
            ->addOption('notification-require-https', null, InputOption::VALUE_REQUIRED, 'Require HTTPS webhook destinations (true|false)', getenv('S3_NOTIFICATION_REQUIRE_HTTPS') === false ? 'true' : getenv('S3_NOTIFICATION_REQUIRE_HTTPS'))
            ->addOption('base-domain', null, InputOption::VALUE_REQUIRED, 'Base domain for virtual-hosted-style requests', getenv('S3_BASE_DOMAIN') ?: null)
            ->addOption('strict-bucket-naming', null, InputOption::VALUE_REQUIRED, 'Reject AWS-reserved bucket name prefixes and suffixes (true|false)', getenv('S3_STRICT_BUCKET_NAMING') === false ? 'true' : getenv('S3_STRICT_BUCKET_NAMING'))
            ->addOption('website-host-pattern', null, InputOption::VALUE_REQUIRED, 'Dedicated website endpoint host pattern', getenv('S3_WEBSITE_HOST_PATTERN') ?: null)
            ->addOption('rate-limit', null, InputOption::VALUE_REQUIRED, 'Requests per second per client (0 = unlimited)', getenv('S3_RATE_LIMIT') ?: '1000')
            ->addOption('master-key-provider', null, InputOption::VALUE_REQUIRED, 'Master key provider: config|redis|vault', getenv('S3_MASTER_KEY_PROVIDER') ?: 'config')
            ->addOption('max-encrypted-object-size', null, InputOption::VALUE_REQUIRED, 'Maximum encrypted object size in bytes', getenv('S3_MAX_ENCRYPTED_OBJECT_SIZE') ?: '268435456')
            ->addOption('max-select-object-size', null, InputOption::VALUE_REQUIRED, 'Maximum S3 Select object size in bytes', getenv('S3_MAX_SELECT_OBJECT_SIZE') ?: '268435456')
            ->addOption('metrics-bearer-token', null, InputOption::VALUE_REQUIRED, 'Bearer token required by the metrics endpoint', getenv('S3_METRICS_BEARER_TOKEN') ?: null)
            ->addOption('credentials-driver', null, InputOption::VALUE_REQUIRED, 'Credentials backend: memory|database|file', getenv('S3_CREDENTIALS_DRIVER') ?: 'memory')
            ->addOption('access-key', null, InputOption::VALUE_REQUIRED, 'Access key ID (memory driver)', getenv('S3_ACCESS_KEY') ?: null)
            ->addOption('secret-key', null, InputOption::VALUE_REQUIRED, 'Secret access key (memory driver)', getenv('S3_SECRET_KEY') ?: null)
            ->addOption('owner-id', null, InputOption::VALUE_REQUIRED, 'Owner ID (memory driver)', getenv('S3_OWNER_ID') ?: null)
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name (memory driver)', getenv('S3_DISPLAY_NAME') ?: null)
            ->addOption('credentials-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for credentials database (database driver)', getenv('S3_CREDENTIALS_DSN') ?: null)
            ->addOption('credentials-username', null, InputOption::VALUE_REQUIRED, 'Username for credentials database', getenv('S3_CREDENTIALS_USERNAME') ?: null)
            ->addOption('credentials-password', null, InputOption::VALUE_REQUIRED, 'Password for credentials database', getenv('S3_CREDENTIALS_PASSWORD') === false ? null : getenv('S3_CREDENTIALS_PASSWORD'))
            ->addOption('credentials-cache-ttl', null, InputOption::VALUE_REQUIRED, 'Positive credential cache TTL in seconds (0 = disabled)', getenv('S3_CREDENTIALS_CACHE_TTL') ?: '1')
            ->addOption('credentials-file', null, InputOption::VALUE_REQUIRED, 'Path to credentials JSON file (file driver)', getenv('S3_CREDENTIALS_PATH') ?: null)
            ->addOption('quota-max-buckets-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum buckets per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_BUCKETS_PER_OWNER') ?: '0')
            ->addOption('quota-max-objects-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum objects per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_OBJECTS_PER_BUCKET') ?: '0')
            ->addOption('quota-max-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_BYTES_PER_BUCKET') ?: '0')
            ->addOption('quota-max-bytes-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_BYTES_PER_OWNER') ?: '0')
            ->addOption('quota-max-multipart-uploads-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum active multipart uploads per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_BUCKET') ?: '0')
            ->addOption('quota-max-multipart-uploads-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum active multipart uploads per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_OWNER') ?: '0')
            ->addOption('quota-max-multipart-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum multipart staging bytes per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_BYTES_PER_BUCKET') ?: '0')
            ->addOption('quota-max-multipart-bytes-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum multipart staging bytes per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_BYTES_PER_OWNER') ?: '0')
            ->addOption('lifecycle-interval-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds between lifecycle sweeps', getenv('S3_LIFECYCLE_INTERVAL_SECONDS') ?: '60')
            ->addOption('lifecycle-batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum rows fetched per lifecycle query', getenv('S3_LIFECYCLE_BATCH_SIZE') ?: '1000')
            ->addOption('lifecycle-max-actions-per-run', null, InputOption::VALUE_REQUIRED, 'Maximum lifecycle actions per sweep', getenv('S3_LIFECYCLE_MAX_ACTIONS_PER_RUN') ?: '1000')
            ->addOption('lifecycle-lock-ttl-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds before a lifecycle lock is considered stale', getenv('S3_LIFECYCLE_LOCK_TTL_SECONDS') ?: '300')
            ->addOption('multipart-max-age-seconds', null, InputOption::VALUE_REQUIRED, 'Global maximum age for incomplete multipart uploads (0 disables cleanup)', getenv('S3_MULTIPART_MAX_AGE_SECONDS') ?: '604800');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storageDriver = (string) $input->getOption('storage-driver');
        $storagePath = $input->getOption('storage-path');

        if ($storageDriver === 'filesystem' && ($storagePath === null || $storagePath === '')) {
            $output->writeln('<error>Error: --storage-path is required for the filesystem storage driver.</error>');

            return Command::FAILURE;
        }

        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $region = (string) $input->getOption('region');
        $metadataDriver = (string) $input->getOption('metadata-driver');
        $metadataDsn = (string) $input->getOption('metadata-dsn');
        $metrics = new MetricsCollector();

        // Only the local filesystem backend owns and creates storagePath.
        if ($storageDriver === 'filesystem' && $storagePath !== null && $storagePath !== '') {
            $storagePath = realpath($storagePath) ?: $storagePath;
            if (! is_dir($storagePath)) {
                if (! @mkdir($storagePath, 0o755, true) && ! is_dir($storagePath)) {
                    $output->writeln("<error>Error: Cannot create storage directory: {$storagePath}</error>");

                    return Command::FAILURE;
                }
            }
        }

        // Build logger.
        $logHandler = new StreamHandler(ByteStream\getStdout());
        $logHandler->pushProcessor(new PsrLogMessageProcessor());
        $logHandler->setFormatter(new ConsoleFormatter());

        $logger = new Logger('s3-server');
        $logger->pushHandler($logHandler);

        try {
            $config = $this->configFromInput(
                $input,
                $storageDriver === 'filesystem' ? $storagePath : null,
            );
            $storageTierRegistry = $this->buildStorageTierRegistry($input, $storageDriver, $storagePath, $metrics);
            $storage = $storageTierRegistry->defaultBackend();
            $metadata = $this->buildMetadataStore(
                $input,
                $metadataDriver,
                $metadataDsn,
                $storageDriver === 'filesystem' ? $storagePath : null,
                $metrics,
            );
            $credentialProvider = $this->buildCredentialProvider($input, $output);
            if ($credentialProvider === null) {
                return Command::FAILURE;
            }

            $runtime = (new S3ServerRuntimeFactory())->create(
                config: $config,
                metadata: $metadata,
                storage: $storage,
                credentialProvider: $credentialProvider,
                logger: $logger,
                metrics: $metrics,
                storageTiers: $storageTierRegistry,
                externalIamConfig: $this->externalIamConfigFromEnv(),
                adminToken: $this->adminApiTokenFromEnv(),
            );
        } catch (\Throwable $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $logger->info('OpsFour S3 Server starting', [
            'storage' => "{$storageDriver}" . ($storagePath ? ":{$storagePath}" : ''),
            'region' => $region,
            'metadata' => $metadataDriver,
        ]);

        try {
            $runtime->server->start();

            // Wait for termination signal.
            $signal = \Amp\trapSignal([\SIGINT, \SIGTERM]);
            $logger->info('Received signal {signal}, shutting down...', [
                'signal' => $signal === \SIGINT ? 'SIGINT' : 'SIGTERM',
            ]);
        } catch (\Throwable $e) {
            $logger->error('S3 server runtime failed: {error}', ['error' => $e->getMessage()]);
            $output->writeln("<error>Error: {$e->getMessage()}</error>");

            return Command::FAILURE;
        } finally {
            $runtime->stop();
        }

        $logger->info('Server stopped gracefully.');

        return Command::SUCCESS;
    }

    private function configFromInput(InputInterface $input, ?string $storagePath): S3ServerConfig
    {
        return new S3ServerConfig(
            host: (string) $input->getOption('host'),
            port: (int) $input->getOption('port'),
            region: (string) $input->getOption('region'),
            storagePath: $storagePath ?? (string) $input->getOption('storage-temp-dir'),
            metadataDriver: (string) $input->getOption('metadata-driver'),
            metadataDsn: (string) $input->getOption('metadata-dsn'),
            tlsCertPath: $this->nullableString($input->getOption('tls-cert')),
            tlsKeyPath: $this->nullableString($input->getOption('tls-key')),
            maxConcurrentConnections: (int) $input->getOption('max-connections'),
            connectionIdleTimeout: (int) $input->getOption('connection-idle-timeout'),
            requestBodySizeLimit: (int) $input->getOption('body-size-limit'),
            readTimeout: (int) $input->getOption('read-timeout'),
            writeTimeout: (int) $input->getOption('write-timeout'),
            perClientRateLimit: (int) $input->getOption('rate-limit'),
            baseDomain: $this->nullableString($input->getOption('base-domain')),
            strictBucketNaming: $this->booleanOption($input, 'strict-bucket-naming'),
            websiteHostPattern: $this->nullableString($input->getOption('website-host-pattern')),
            masterKeyProvider: (string) $input->getOption('master-key-provider'),
            enforceMinPartSize: $this->booleanOption($input, 'enforce-min-part-size'),
            maxEncryptedObjectSize: (int) $input->getOption('max-encrypted-object-size'),
            maxSelectObjectSize: (int) $input->getOption('max-select-object-size'),
            shutdownDrainTimeout: (int) $input->getOption('shutdown-drain-timeout'),
            notificationRequireHttps: $this->booleanOption($input, 'notification-require-https'),
            sqliteWorkerPoolSize: (int) $input->getOption('sqlite-workers'),
            encryptionWorkerPoolSize: (int) $input->getOption('encryption-workers'),
            selectWorkerPoolSize: $input->getOption('select-workers') === null
                ? null
                : (int) $input->getOption('select-workers'),
            requestBodySpoolWorkerPoolSize: (int) $input->getOption('request-body-spool-workers'),
            encryptionParallelThreshold: (int) $input->getOption('encryption-threshold'),
            quota: new QuotaConfig(
                maxBucketsPerOwner: (int) $input->getOption('quota-max-buckets-per-owner'),
                maxObjectsPerBucket: (int) $input->getOption('quota-max-objects-per-bucket'),
                maxBytesPerBucket: (int) $input->getOption('quota-max-bytes-per-bucket'),
                maxBytesPerOwner: (int) $input->getOption('quota-max-bytes-per-owner'),
                maxMultipartUploadsPerBucket: (int) $input->getOption('quota-max-multipart-uploads-per-bucket'),
                maxMultipartUploadsPerOwner: (int) $input->getOption('quota-max-multipart-uploads-per-owner'),
                maxMultipartBytesPerBucket: (int) $input->getOption('quota-max-multipart-bytes-per-bucket'),
                maxMultipartBytesPerOwner: (int) $input->getOption('quota-max-multipart-bytes-per-owner'),
            ),
            lifecycleIntervalSeconds: (float) $input->getOption('lifecycle-interval-seconds'),
            lifecycleBatchSize: (int) $input->getOption('lifecycle-batch-size'),
            lifecycleMaxActionsPerRun: (int) $input->getOption('lifecycle-max-actions-per-run'),
            lifecycleLockTtlSeconds: (int) $input->getOption('lifecycle-lock-ttl-seconds'),
            multipartMaxAgeSeconds: (int) $input->getOption('multipart-max-age-seconds'),
            metricsBearerToken: $this->nullableString($input->getOption('metrics-bearer-token')),
        );
    }

    private function buildStorageTierRegistry(
        InputInterface $input,
        string $storageDriver,
        ?string $storagePath,
        MetricsCollector $metrics,
    ): StorageTierRegistry {
        $storageConfig = [
            'driver' => $storageDriver,
            'path' => $storagePath,
        ];

        if ($storageDriver === 'flysystem') {
            $tempDir = (string) $input->getOption('storage-temp-dir');
            $this->ensureDirectory($tempDir, 'storage staging');

            $workers = (int) $input->getOption('flysystem-workers');
            if ($workers < 1) {
                throw new \InvalidArgumentException(
                    'Standalone Flysystem storage requires --flysystem-workers >= 1.',
                );
            }

            $storageConfig += [
                'filesystem_factory' => new AwsS3FlysystemFilesystemFactory(
                    remoteBucket: $this->requiredStringOption($input, 'backing-bucket'),
                    region: $this->requiredStringOption($input, 'backing-region'),
                    accessKeyId: $this->requiredStringOption($input, 'backing-access-key'),
                    secretAccessKey: $this->requiredStringOption($input, 'backing-secret-key'),
                    endpoint: $this->nullableString($input->getOption('backing-endpoint')),
                    pathStyle: $this->booleanOption($input, 'backing-path-style'),
                    prefix: (string) $input->getOption('backing-prefix'),
                ),
                'worker_pool_size' => $workers,
                'temp_dir' => $tempDir,
            ];
        }

        return StorageBackendFactory::createTierRegistry($storageConfig, $metrics);
    }

    private function buildMetadataStore(
        InputInterface $input,
        string $metadataDriver,
        string $metadataDsn,
        ?string $storagePath,
        MetricsCollector $metrics,
    ): \OpsFour\S3Server\Metadata\MetadataStore {
        $metadataConfig = [
            'dsn' => $metadataDsn,
            'worker_pool_size' => (int) $input->getOption('sqlite-workers'),
        ];
        if ($metadataDriver === 'sqlite') {
            $path = $this->nullableString($input->getOption('metadata-path'))
                ?? rtrim($storagePath ?? (string) $input->getOption('storage-temp-dir'), '/') . '/metadata.sqlite';
            $this->ensureDirectory(dirname($path), 'SQLite metadata');
            $metadataConfig['path'] = $path;
        }

        $metadata = MetadataStoreFactory::create($metadataDriver, $metadataConfig, metrics: $metrics);
        $cacheTtl = (float) $input->getOption('metadata-cache-ttl');
        if ($cacheTtl < 0) {
            throw new \InvalidArgumentException('--metadata-cache-ttl must be >= 0.');
        }
        if ($cacheTtl > 0) {
            $metadata = new CachedMetadataStoreDecorator($metadata, $cacheTtl);
        }

        return new ObservedMetadataStore($metadata, $metrics, $metadataDriver);
    }

    private function booleanOption(InputInterface $input, string $name): bool
    {
        $value = filter_var($input->getOption($name), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \InvalidArgumentException("--{$name} must be true or false.");
        }

        return $value;
    }

    private function requiredStringOption(InputInterface $input, string $name): string
    {
        $value = $this->nullableString($input->getOption($name));
        if ($value === null) {
            throw new \InvalidArgumentException("--{$name} is required for standalone Flysystem storage.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function ensureDirectory(string $path, string $purpose): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException("A local {$purpose} directory is required.");
        }
        if (! is_dir($path) && ! @mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new \RuntimeException("Cannot create local {$purpose} directory: {$path}");
        }
        if (! is_writable($path)) {
            throw new \RuntimeException("Local {$purpose} directory is not writable: {$path}");
        }
    }

    private function buildCredentialProvider(InputInterface $input, OutputInterface $output): ?\OpsFour\S3Server\Contracts\CredentialProvider
    {
        $driver = (string) $input->getOption('credentials-driver');

        $config = match ($driver) {
            'memory' => [
                'access_key' => $input->getOption('access-key'),
                'secret_key' => $input->getOption('secret-key'),
                'owner_id' => $input->getOption('owner-id') ?? 'default-owner',
                'display_name' => $input->getOption('display-name') ?? 'Default User',
            ],
            'database' => [
                'dsn' => $input->getOption('credentials-dsn'),
                'username' => $input->getOption('credentials-username'),
                'password' => $input->getOption('credentials-password'),
                'cache_ttl' => $input->getOption('credentials-cache-ttl'),
            ],
            'file' => [
                'path' => $input->getOption('credentials-file'),
            ],
            default => [],
        };

        try {
            return CredentialProviderFactory::create($driver, $config);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function externalIamConfigFromEnv(): array
    {
        return [
            'enabled' => getenv('S3_EXTERNAL_IAM_ENABLED') ?: false,
            'admin_token' => getenv('S3_EXTERNAL_IAM_ADMIN_TOKEN') ?: null,
            'issuer' => getenv('S3_EXTERNAL_IAM_ISSUER') ?: null,
            'audience' => getenv('S3_EXTERNAL_IAM_AUDIENCE') ?: null,
            'jwks_path' => getenv('S3_EXTERNAL_IAM_JWKS_PATH') ?: null,
            'public_key_path' => getenv('S3_EXTERNAL_IAM_PUBLIC_KEY_PATH') ?: null,
            'public_key' => getenv('S3_EXTERNAL_IAM_PUBLIC_KEY') ?: null,
            'owner_claim' => getenv('S3_EXTERNAL_IAM_OWNER_CLAIM') ?: 'sub',
            'display_name_claim' => getenv('S3_EXTERNAL_IAM_DISPLAY_NAME_CLAIM') ?: 'preferred_username',
            'groups_claim' => getenv('S3_EXTERNAL_IAM_GROUPS_CLAIM') ?: 'groups',
            'policy_names_claim' => getenv('S3_EXTERNAL_IAM_POLICY_NAMES_CLAIM') ?: null,
            'allowed_prefixes_claim' => getenv('S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM') ?: null,
            'owner_prefix' => getenv('S3_EXTERNAL_IAM_OWNER_PREFIX') ?: '',
            'clock_skew_seconds' => getenv('S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS') ?: 60,
        ];
    }

    private function adminApiTokenFromEnv(): ?string
    {
        $token = getenv('S3_ADMIN_API_TOKEN');
        if ($token !== false && trim($token) !== '') {
            return trim($token);
        }

        $externalIamToken = getenv('S3_EXTERNAL_IAM_ADMIN_TOKEN');
        if ($externalIamToken !== false && trim($externalIamToken) !== '') {
            return trim($externalIamToken);
        }

        return null;
    }
}
