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
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
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
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'AWS region identifier', getenv('S3_REGION') ?: 'us-east-1')
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for metadata database', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('tls-cert', null, InputOption::VALUE_REQUIRED, 'Path to TLS certificate file', getenv('S3_TLS_CERT') ?: null)
            ->addOption('tls-key', null, InputOption::VALUE_REQUIRED, 'Path to TLS private key file', getenv('S3_TLS_KEY') ?: null)
            ->addOption('max-connections', null, InputOption::VALUE_REQUIRED, 'Maximum concurrent connections', getenv('S3_MAX_CONNECTIONS') ?: '10000')
            ->addOption('connection-idle-timeout', null, InputOption::VALUE_REQUIRED, 'Idle connection timeout in seconds', getenv('S3_CONNECTION_IDLE_TIMEOUT') ?: '60')
            ->addOption('body-size-limit', null, InputOption::VALUE_REQUIRED, 'Maximum request body size in bytes', getenv('S3_BODY_SIZE_LIMIT') ?: '5368709120')
            ->addOption('read-timeout', null, InputOption::VALUE_REQUIRED, 'Request stream inactivity timeout in seconds', getenv('S3_READ_TIMEOUT') ?: '300')
            ->addOption('write-timeout', null, InputOption::VALUE_REQUIRED, 'Response stream inactivity timeout in seconds', getenv('S3_WRITE_TIMEOUT') ?: '300')
            ->addOption('shutdown-drain-timeout', null, InputOption::VALUE_REQUIRED, 'Graceful shutdown drain timeout in seconds', getenv('S3_SHUTDOWN_DRAIN_TIMEOUT') ?: '30')
            ->addOption('request-body-spool-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for request-body checksum spooling', getenv('S3_REQUEST_BODY_SPOOL_WORKERS') ?: '8')
            ->addOption('notification-require-https', null, InputOption::VALUE_REQUIRED, 'Require HTTPS webhook destinations (true|false)', getenv('S3_NOTIFICATION_REQUIRE_HTTPS') === false ? 'true' : getenv('S3_NOTIFICATION_REQUIRE_HTTPS'))
            ->addOption('credentials-driver', null, InputOption::VALUE_REQUIRED, 'Credentials backend: memory|database|file', getenv('S3_CREDENTIALS_DRIVER') ?: 'memory')
            ->addOption('access-key', null, InputOption::VALUE_REQUIRED, 'Access key ID (memory driver)', getenv('S3_ACCESS_KEY') ?: null)
            ->addOption('secret-key', null, InputOption::VALUE_REQUIRED, 'Secret access key (memory driver)', getenv('S3_SECRET_KEY') ?: null)
            ->addOption('owner-id', null, InputOption::VALUE_REQUIRED, 'Owner ID (memory driver)', getenv('S3_OWNER_ID') ?: null)
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name (memory driver)', getenv('S3_DISPLAY_NAME') ?: null)
            ->addOption('credentials-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for credentials database (database driver)', getenv('S3_CREDENTIALS_DSN') ?: null)
            ->addOption('credentials-file', null, InputOption::VALUE_REQUIRED, 'Path to credentials JSON file (file driver)', getenv('S3_CREDENTIALS_PATH') ?: null)
            ->addOption('quota-max-buckets-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum buckets per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_BUCKETS_PER_OWNER') ?: '0')
            ->addOption('quota-max-objects-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum objects per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_OBJECTS_PER_BUCKET') ?: '0')
            ->addOption('quota-max-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_BYTES_PER_BUCKET') ?: '0')
            ->addOption('quota-max-bytes-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_BYTES_PER_OWNER') ?: '0')
            ->addOption('lifecycle-interval-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds between lifecycle sweeps', getenv('S3_LIFECYCLE_INTERVAL_SECONDS') ?: '60')
            ->addOption('lifecycle-batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum rows fetched per lifecycle query', getenv('S3_LIFECYCLE_BATCH_SIZE') ?: '1000')
            ->addOption('lifecycle-max-actions-per-run', null, InputOption::VALUE_REQUIRED, 'Maximum lifecycle actions per sweep', getenv('S3_LIFECYCLE_MAX_ACTIONS_PER_RUN') ?: '1000')
            ->addOption('lifecycle-lock-ttl-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds before a lifecycle lock is considered stale', getenv('S3_LIFECYCLE_LOCK_TTL_SECONDS') ?: '300');
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
        $tlsCertPath = $input->getOption('tls-cert');
        $tlsKeyPath = $input->getOption('tls-key');
        $maxConnections = (int) $input->getOption('max-connections');
        $metrics = new MetricsCollector();

        // Resolve storage path for filesystem driver.
        if ($storagePath !== null && $storagePath !== '') {
            $storagePath = realpath($storagePath) ?: $storagePath;
            if (! is_dir($storagePath)) {
                if (! mkdir($storagePath, 0o755, true)) {
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

        // Build configuration.
        $config = new S3ServerConfig(
            host: $host,
            port: $port,
            region: $region,
            storagePath: $storagePath ?? sys_get_temp_dir() . '/s3-server',
            metadataDriver: $metadataDriver,
            metadataDsn: $metadataDsn,
            tlsCertPath: $tlsCertPath,
            tlsKeyPath: $tlsKeyPath,
            maxConcurrentConnections: $maxConnections,
            connectionIdleTimeout: (int) $input->getOption('connection-idle-timeout'),
            requestBodySizeLimit: (int) $input->getOption('body-size-limit'),
            readTimeout: (int) $input->getOption('read-timeout'),
            writeTimeout: (int) $input->getOption('write-timeout'),
            shutdownDrainTimeout: (int) $input->getOption('shutdown-drain-timeout'),
            requestBodySpoolWorkerPoolSize: (int) $input->getOption('request-body-spool-workers'),
            notificationRequireHttps: filter_var(
                $input->getOption('notification-require-https'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? true,
            quota: new QuotaConfig(
                maxBucketsPerOwner: (int) $input->getOption('quota-max-buckets-per-owner'),
                maxObjectsPerBucket: (int) $input->getOption('quota-max-objects-per-bucket'),
                maxBytesPerBucket: (int) $input->getOption('quota-max-bytes-per-bucket'),
                maxBytesPerOwner: (int) $input->getOption('quota-max-bytes-per-owner'),
            ),
            lifecycleIntervalSeconds: (float) $input->getOption('lifecycle-interval-seconds'),
            lifecycleBatchSize: (int) $input->getOption('lifecycle-batch-size'),
            lifecycleMaxActionsPerRun: (int) $input->getOption('lifecycle-max-actions-per-run'),
            lifecycleLockTtlSeconds: (int) $input->getOption('lifecycle-lock-ttl-seconds'),
        );

        // Create storage backend via factory.
        $storageTierRegistry = StorageBackendFactory::createTierRegistry([
            'driver' => $storageDriver,
            'path' => $storagePath,
        ]);
        $storage = $storageTierRegistry->defaultBackend();
        $storage = new ObservedStorageBackend($storage, $metrics, $storageDriver);

        // Create metadata backend via factory.
        $metadataConfig = ['dsn' => $metadataDsn];
        if ($metadataDriver === 'sqlite') {
            $metadataConfig['path'] = ($storagePath ?? sys_get_temp_dir() . '/s3-server') . '/metadata.sqlite';
        }
        $metadata = MetadataStoreFactory::create($metadataDriver, $metadataConfig, metrics: $metrics);
        $metadata = new ObservedMetadataStore($metadata, $metrics, $metadataDriver);

        // Create credential provider via factory.
        $credentialProvider = $this->buildCredentialProvider($input, $output);
        if ($credentialProvider === null) {
            return Command::FAILURE;
        }

        try {
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
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $logger->info('OpsFour S3 Server starting', [
            'storage' => "{$storageDriver}" . ($storagePath ? ":{$storagePath}" : ''),
            'region' => $region,
            'metadata' => $metadataDriver,
        ]);

        $runtime->server->start();

        // Wait for termination signal.
        $signal = \Amp\trapSignal([\SIGINT, \SIGTERM]);

        $logger->info('Received signal {signal}, shutting down...', [
            'signal' => $signal === \SIGINT ? 'SIGINT' : 'SIGTERM',
        ]);

        $runtime->stop();

        $logger->info('Server stopped gracefully.');

        return Command::SUCCESS;
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
