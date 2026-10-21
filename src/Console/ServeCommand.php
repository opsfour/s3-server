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
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Runtime\BootstrapResourceCleanup;
use OpsFour\S3Server\Runtime\PostgresApiDriverGuard;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\Runtime\ServeFailureMessage;
use OpsFour\S3Server\Runtime\ServeRuntimeShutdown;
use OpsFour\S3Server\Runtime\TerminationSignalTrap;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Start the opsfour S3-compatible server')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen address', getenv('S3_HOST') ?: '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port', self::envOption('S3_PORT', '9000'))
            ->addOption('storage-path', null, InputOption::VALUE_REQUIRED, 'Root directory for object storage', getenv('S3_STORAGE_PATH') ?: null)
            ->addOption('storage-driver', null, InputOption::VALUE_REQUIRED, 'Storage backend: filesystem|flysystem|memory', getenv('S3_STORAGE_DRIVER') ?: 'filesystem')
            ->addOption('allow-unfenced-storage-for-tests', null, InputOption::VALUE_REQUIRED, 'UNSAFE TEST ONLY: allow storage without a verified late-write fence (true|false)', self::envOption('S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS', 'false'))
            ->addOption('storage-temp-dir', null, InputOption::VALUE_REQUIRED, 'Local staging directory for remote storage transfers', getenv('S3_STORAGE_TEMP_DIR') ?: sys_get_temp_dir())
            ->addOption('storage-temp-budget', null, InputOption::VALUE_REQUIRED, 'Shared local Flysystem staging budget in bytes', getenv('S3_STORAGE_TEMP_BUDGET') === false ? '10737418240' : getenv('S3_STORAGE_TEMP_BUDGET'))
            ->addOption('flysystem-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for remote Flysystem operations', getenv('S3_FLYSYSTEM_WORKERS') ?: '0')
            ->addOption('flysystem-first-byte-timeout', null, InputOption::VALUE_REQUIRED, 'Maximum seconds to wait for the first remote read byte', self::envOption('S3_FLYSYSTEM_FIRST_BYTE_TIMEOUT_SECONDS', '20'))
            ->addOption('backing-bucket', null, InputOption::VALUE_REQUIRED, 'Backing S3 bucket for standalone Flysystem mode', getenv('S3_BACKING_BUCKET') ?: null)
            ->addOption('backing-region', null, InputOption::VALUE_REQUIRED, 'Backing S3 region', getenv('S3_BACKING_REGION') ?: null)
            ->addOption('backing-access-key', null, InputOption::VALUE_REQUIRED, 'Backing S3 access key', getenv('S3_BACKING_ACCESS_KEY') ?: null)
            ->addOption('backing-secret-key', null, InputOption::VALUE_REQUIRED, 'Backing S3 secret key', getenv('S3_BACKING_SECRET_KEY') ?: null)
            ->addOption('backing-endpoint', null, InputOption::VALUE_REQUIRED, 'Backing S3-compatible endpoint', getenv('S3_BACKING_ENDPOINT') ?: null)
            ->addOption('backing-allow-insecure-http-for-tests', null, InputOption::VALUE_REQUIRED, 'UNSAFE TEST ONLY: allow an HTTP backing S3 endpoint (true|false)', self::envOption('S3_BACKING_ALLOW_INSECURE_HTTP_FOR_TESTS', 'false'))
            ->addOption('backing-path-style', null, InputOption::VALUE_REQUIRED, 'Use path-style requests for backing S3 (true|false)', getenv('S3_BACKING_PATH_STYLE') === false ? 'false' : getenv('S3_BACKING_PATH_STYLE'))
            ->addOption('backing-prefix', null, InputOption::VALUE_REQUIRED, 'Optional key prefix in the backing S3 bucket', getenv('S3_BACKING_PREFIX') ?: '')
            ->addOption('backing-connect-timeout', null, InputOption::VALUE_REQUIRED, 'Backing S3 connection timeout in seconds', self::envOption('S3_BACKING_CONNECT_TIMEOUT_SECONDS', '5'))
            ->addOption('backing-read-timeout', null, InputOption::VALUE_REQUIRED, 'Backing S3 streamed-read idle timeout in seconds', self::envOption('S3_BACKING_READ_TIMEOUT_SECONDS', '15'))
            ->addOption('backing-request-timeout', null, InputOption::VALUE_REQUIRED, 'Backing S3 total request timeout in seconds (0 disables)', self::envOption('S3_BACKING_REQUEST_TIMEOUT_SECONDS', '0'))
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'AWS region identifier', getenv('S3_REGION') ?: 'us-east-1')
            ->addOption('metadata-driver', null, InputOption::VALUE_REQUIRED, 'Metadata backend: sqlite|postgres|mysql', getenv('S3_METADATA_DRIVER') ?: 'sqlite')
            ->addOption('metadata-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for metadata database', getenv('S3_METADATA_DSN') ?: '')
            ->addOption('metadata-path', null, InputOption::VALUE_REQUIRED, 'SQLite metadata database path', getenv('S3_METADATA_PATH') ?: null)
            ->addOption('metadata-cache-ttl', null, InputOption::VALUE_REQUIRED, 'Metadata read cache TTL in seconds (0 disables)', getenv('S3_METADATA_CACHE_TTL') === false ? '5' : getenv('S3_METADATA_CACHE_TTL'))
            ->addOption('tls-cert', null, InputOption::VALUE_REQUIRED, 'Path to TLS certificate file', getenv('S3_TLS_CERT') ?: null)
            ->addOption('tls-key', null, InputOption::VALUE_REQUIRED, 'Path to TLS private key file', getenv('S3_TLS_KEY') ?: null)
            ->addOption('trusted-proxy-cidrs', null, InputOption::VALUE_REQUIRED, 'Comma-separated CIDRs of trusted proxy peers (default: none)', getenv('S3_TRUSTED_PROXY_CIDRS') === false ? '' : getenv('S3_TRUSTED_PROXY_CIDRS'))
            ->addOption('max-connections', null, InputOption::VALUE_REQUIRED, 'Maximum concurrent connections', self::envOption('S3_MAX_CONNECTIONS', '10000'))
            ->addOption('connection-idle-timeout', null, InputOption::VALUE_REQUIRED, 'HTTP/2 idle connection timeout in seconds; HTTP/1 uses the stream timeout', self::envOption('S3_CONNECTION_IDLE_TIMEOUT', '60'))
            ->addOption('body-size-limit', null, InputOption::VALUE_REQUIRED, 'Maximum request body size in bytes', self::envOption('S3_BODY_SIZE_LIMIT', '5368709120'))
            ->addOption('post-spool-budget', null, InputOption::VALUE_REQUIRED, 'Concurrent browser POST temporary bytes shared by processes using one local temp directory', getenv('S3_POST_SPOOL_BUDGET') === false ? '10737418240' : getenv('S3_POST_SPOOL_BUDGET'))
            ->addOption('read-timeout', null, InputOption::VALUE_REQUIRED, 'Request stream inactivity timeout in seconds', self::envOption('S3_READ_TIMEOUT', '300'))
            ->addOption('write-timeout', null, InputOption::VALUE_REQUIRED, 'Response stream inactivity timeout in seconds', self::envOption('S3_WRITE_TIMEOUT', '300'))
            ->addOption('shutdown-drain-timeout', null, InputOption::VALUE_REQUIRED, 'Maximum seconds to drain in-flight requests during graceful shutdown', self::envOption('S3_SHUTDOWN_DRAIN_TIMEOUT', '30'))
            ->addOption('request-body-spool-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for request-body checksum spooling', self::envOption('S3_REQUEST_BODY_SPOOL_WORKERS', '8'))
            ->addOption('sqlite-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for SQLite metadata operations', self::envOption('S3_SQLITE_WORKERS', '4'))
            ->addOption('encryption-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for encryption', getenv('S3_ENCRYPTION_WORKERS') ?: '0')
            ->addOption('select-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for S3 Select', getenv('S3_SELECT_WORKERS') === false ? null : getenv('S3_SELECT_WORKERS'))
            ->addOption('encryption-threshold', null, InputOption::VALUE_REQUIRED, 'Encryption worker offload threshold in bytes', self::envOption('S3_ENCRYPTION_THRESHOLD', '65536'))
            ->addOption('enforce-min-part-size', null, InputOption::VALUE_REQUIRED, 'Enforce the S3 5 MiB minimum for non-final multipart parts (true|false)', getenv('S3_ENFORCE_MIN_PART_SIZE') === false ? 'true' : getenv('S3_ENFORCE_MIN_PART_SIZE'))
            ->addOption('notification-require-https', null, InputOption::VALUE_REQUIRED, 'Require HTTPS webhook destinations (true|false)', getenv('S3_NOTIFICATION_REQUIRE_HTTPS') === false ? 'true' : getenv('S3_NOTIFICATION_REQUIRE_HTTPS'))
            ->addOption('notification-worker', null, InputOption::VALUE_REQUIRED, 'Process queued webhook deliveries on this server (true|false)', getenv('S3_NOTIFICATION_WORKER') === false ? 'true' : getenv('S3_NOTIFICATION_WORKER'))
            ->addOption('base-domain', null, InputOption::VALUE_REQUIRED, 'Base domain for virtual-hosted-style requests', getenv('S3_BASE_DOMAIN') ?: null)
            ->addOption('strict-bucket-naming', null, InputOption::VALUE_REQUIRED, 'Reject AWS-reserved bucket name prefixes and suffixes (true|false)', getenv('S3_STRICT_BUCKET_NAMING') === false ? 'true' : getenv('S3_STRICT_BUCKET_NAMING'))
            ->addOption('website-host-pattern', null, InputOption::VALUE_REQUIRED, 'Dedicated website endpoint host pattern', getenv('S3_WEBSITE_HOST_PATTERN') ?: null)
            ->addOption('rate-limit', null, InputOption::VALUE_REQUIRED, 'Requests per second per client (0 = unlimited)', getenv('S3_RATE_LIMIT') === false ? '1000' : getenv('S3_RATE_LIMIT'))
            ->addOption('master-key-provider', null, InputOption::VALUE_REQUIRED, 'Master key provider: config|redis|vault', getenv('S3_MASTER_KEY_PROVIDER') ?: 'config')
            ->addOption('max-encrypted-object-size', null, InputOption::VALUE_REQUIRED, 'Maximum encrypted object size in bytes', self::envOption('S3_MAX_ENCRYPTED_OBJECT_SIZE', '268435456'))
            ->addOption('max-select-object-size', null, InputOption::VALUE_REQUIRED, 'Maximum S3 Select object size in bytes', self::envOption('S3_MAX_SELECT_OBJECT_SIZE', '67108864'))
            ->addOption('metrics-bearer-token', null, InputOption::VALUE_REQUIRED, 'Bearer token required by the metrics endpoint', getenv('S3_METRICS_BEARER_TOKEN') === false ? null : getenv('S3_METRICS_BEARER_TOKEN'))
            ->addOption('credentials-driver', null, InputOption::VALUE_REQUIRED, 'Credentials backend: memory|database|file', getenv('S3_CREDENTIALS_DRIVER') ?: 'memory')
            ->addOption('access-key', null, InputOption::VALUE_REQUIRED, 'Access key ID (memory driver)', getenv('S3_ACCESS_KEY') ?: null)
            ->addOption('secret-key', null, InputOption::VALUE_REQUIRED, 'Secret access key (memory driver)', getenv('S3_SECRET_KEY') ?: null)
            ->addOption('owner-id', null, InputOption::VALUE_REQUIRED, 'Owner ID (memory driver)', getenv('S3_OWNER_ID') ?: null)
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name (memory driver)', getenv('S3_DISPLAY_NAME') ?: null)
            ->addOption('credentials-dsn', null, InputOption::VALUE_REQUIRED, 'DSN for credentials database (database driver)', getenv('S3_CREDENTIALS_DSN') ?: null)
            ->addOption('credentials-username', null, InputOption::VALUE_REQUIRED, 'Username for credentials database', getenv('S3_CREDENTIALS_USERNAME') ?: null)
            ->addOption('credentials-password', null, InputOption::VALUE_REQUIRED, 'Password for credentials database', getenv('S3_CREDENTIALS_PASSWORD') === false ? null : getenv('S3_CREDENTIALS_PASSWORD'))
            ->addOption('credentials-cache-ttl', null, InputOption::VALUE_REQUIRED, 'Positive credential cache TTL in seconds (0 = disabled)', getenv('S3_CREDENTIALS_CACHE_TTL') === false ? '1' : getenv('S3_CREDENTIALS_CACHE_TTL'))
            ->addOption('credentials-workers', null, InputOption::VALUE_REQUIRED, 'Worker processes for database credential operations', self::envOption('S3_CREDENTIALS_WORKERS', '4'))
            ->addOption('credentials-operation-timeout', null, InputOption::VALUE_REQUIRED, 'Database credential operation timeout in seconds', self::envOption('S3_CREDENTIALS_OPERATION_TIMEOUT_SECONDS', '15'))
            ->addOption('credentials-file', null, InputOption::VALUE_REQUIRED, 'Path to credentials JSON file (file driver)', getenv('S3_CREDENTIALS_PATH') ?: null)
            ->addOption('quota-max-buckets-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum buckets per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_BUCKETS_PER_OWNER') ?: '0')
            ->addOption('quota-max-objects-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum objects per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_OBJECTS_PER_BUCKET') ?: '0')
            ->addOption('quota-max-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_BYTES_PER_BUCKET') ?: '0')
            ->addOption('quota-max-bytes-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum bytes per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_BYTES_PER_OWNER') ?: '0')
            ->addOption('quota-max-multipart-uploads-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum active multipart uploads per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_BUCKET') ?: '0')
            ->addOption('quota-max-multipart-uploads-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum active multipart uploads per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_OWNER') ?: '0')
            ->addOption('quota-max-multipart-bytes-per-bucket', null, InputOption::VALUE_REQUIRED, 'Maximum multipart staging bytes per bucket (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_BYTES_PER_BUCKET') ?: '0')
            ->addOption('quota-max-multipart-bytes-per-owner', null, InputOption::VALUE_REQUIRED, 'Maximum multipart staging bytes per owner (0 = unlimited)', getenv('S3_QUOTA_MAX_MULTIPART_BYTES_PER_OWNER') ?: '0')
            ->addOption('lifecycle-interval-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds between lifecycle sweeps', self::envOption('S3_LIFECYCLE_INTERVAL_SECONDS', '60'))
            ->addOption('lifecycle-batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum rows fetched per lifecycle query', self::envOption('S3_LIFECYCLE_BATCH_SIZE', '1000'))
            ->addOption('lifecycle-max-actions-per-run', null, InputOption::VALUE_REQUIRED, 'Maximum lifecycle actions per sweep', self::envOption('S3_LIFECYCLE_MAX_ACTIONS_PER_RUN', '1000'))
            ->addOption('lifecycle-lock-ttl-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds before a lifecycle lock is considered stale', self::envOption('S3_LIFECYCLE_LOCK_TTL_SECONDS', '300'))
            ->addOption('multipart-max-age-seconds', null, InputOption::VALUE_REQUIRED, 'Global maximum age for incomplete multipart uploads (0 disables cleanup)', getenv('S3_MULTIPART_MAX_AGE_SECONDS') === false ? '604800' : getenv('S3_MULTIPART_MAX_AGE_SECONDS'));
    }

    private static function envOption(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storageDriver = (string) $input->getOption('storage-driver');
        $storagePath = $input->getOption('storage-path');

        if ($storageDriver === 'filesystem' && ($storagePath === null || $storagePath === '')) {
            $output->writeln('<error>Error: --storage-path is required for the filesystem storage driver.</error>');

            return Command::FAILURE;
        }

        $region = (string) $input->getOption('region');
        $metadataDriver = (string) $input->getOption('metadata-driver');
        $metadataDsn = (string) $input->getOption('metadata-dsn');
        $metrics = new MetricsCollector();

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
        $logger = new FailureIsolatingLogger($logger);

        $storageTierRegistry = null;
        $metadata = null;
        try {
            $config = $this->configFromInput(
                $input,
                $storageDriver === 'filesystem' ? $storagePath : null,
            );
            PostgresApiDriverGuard::assertAvailable($config);
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
                BootstrapResourceCleanup::release($metadata, $storageTierRegistry);

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
            $storageTierRegistry = null;
            $metadata = null;
        } catch (\Throwable $e) {
            BootstrapResourceCleanup::release($metadata, $storageTierRegistry);
            $output->writeln('<error>Error: ' . ServeFailureMessage::format($e, 'bootstrap') . '</error>');

            return Command::FAILURE;
        }

        $failed = false;
        try {
            $logger->info('opsfour S3 Server starting', [
                'storage' => "{$storageDriver}" . ($storagePath ? ":{$storagePath}" : ''),
                'region' => $region,
                'metadata' => $metadataDriver,
            ]);
            $signal = (new TerminationSignalTrap())->startAndWait(
                static fn() => $runtime->server->start(),
            );
            $logger->info('Received signal {signal}, shutting down...', [
                'signal' => $signal === \SIGINT ? 'SIGINT' : 'SIGTERM',
            ]);
        } catch (\Throwable $e) {
            $logger->error('S3 server runtime failed.', ['exception' => $e::class]);
            $output->writeln('<error>Error: ' . ServeFailureMessage::format($e, 'runtime') . '</error>');
            $failed = true;
        } finally {
            $shutdownError = ServeRuntimeShutdown::stop($runtime, $logger);
            if ($shutdownError !== null) {
                $output->writeln('<error>Error: ' . $shutdownError . '</error>');
                $failed = true;
            }
        }

        if ($failed) {
            return Command::FAILURE;
        }

        $logger->info('Server stopped gracefully.');

        return Command::SUCCESS;
    }

    private function configFromInput(InputInterface $input, ?string $storagePath): S3ServerConfig
    {
        return new S3ServerConfig(
            host: (string) $input->getOption('host'),
            port: $this->integerOption($input, 'port'),
            region: (string) $input->getOption('region'),
            storagePath: $storagePath ?? (string) $input->getOption('storage-temp-dir'),
            allowUnfencedStorageForTests: $this->booleanOption($input, 'allow-unfenced-storage-for-tests'),
            metadataDriver: (string) $input->getOption('metadata-driver'),
            metadataDsn: (string) $input->getOption('metadata-dsn'),
            tlsCertPath: $this->nullableString($input->getOption('tls-cert')),
            tlsKeyPath: $this->nullableString($input->getOption('tls-key')),
            trustedProxyCidrs: TrustedProxyMiddleware::parseCidrs((string) $input->getOption('trusted-proxy-cidrs')),
            maxConcurrentConnections: $this->integerOption($input, 'max-connections'),
            connectionIdleTimeout: $this->integerOption($input, 'connection-idle-timeout'),
            requestBodySizeLimit: $this->integerOption($input, 'body-size-limit'),
            postSpoolBudgetBytes: $this->integerOption($input, 'post-spool-budget'),
            readTimeout: $this->integerOption($input, 'read-timeout'),
            writeTimeout: $this->integerOption($input, 'write-timeout'),
            perClientRateLimit: $this->integerOption($input, 'rate-limit'),
            baseDomain: $this->nullableString($input->getOption('base-domain')),
            strictBucketNaming: $this->booleanOption($input, 'strict-bucket-naming'),
            websiteHostPattern: $this->nullableString($input->getOption('website-host-pattern')),
            masterKeyProvider: (string) $input->getOption('master-key-provider'),
            enforceMinPartSize: $this->booleanOption($input, 'enforce-min-part-size'),
            maxEncryptedObjectSize: $this->integerOption($input, 'max-encrypted-object-size'),
            maxSelectObjectSize: $this->integerOption($input, 'max-select-object-size'),
            shutdownDrainTimeout: $this->integerOption($input, 'shutdown-drain-timeout'),
            notificationRequireHttps: $this->booleanOption($input, 'notification-require-https'),
            notificationWorkerEnabled: $this->booleanOption($input, 'notification-worker'),
            sqliteWorkerPoolSize: $this->sqliteWorkerPoolSize($input),
            encryptionWorkerPoolSize: $this->integerOption($input, 'encryption-workers'),
            selectWorkerPoolSize: $input->getOption('select-workers') === null
                ? null
                : $this->integerOption($input, 'select-workers'),
            requestBodySpoolWorkerPoolSize: $this->integerOption($input, 'request-body-spool-workers'),
            encryptionParallelThreshold: $this->integerOption($input, 'encryption-threshold'),
            quota: new QuotaConfig(
                maxBucketsPerOwner: $this->integerOption($input, 'quota-max-buckets-per-owner'),
                maxObjectsPerBucket: $this->integerOption($input, 'quota-max-objects-per-bucket'),
                maxBytesPerBucket: $this->integerOption($input, 'quota-max-bytes-per-bucket'),
                maxBytesPerOwner: $this->integerOption($input, 'quota-max-bytes-per-owner'),
                maxMultipartUploadsPerBucket: $this->integerOption($input, 'quota-max-multipart-uploads-per-bucket'),
                maxMultipartUploadsPerOwner: $this->integerOption($input, 'quota-max-multipart-uploads-per-owner'),
                maxMultipartBytesPerBucket: $this->integerOption($input, 'quota-max-multipart-bytes-per-bucket'),
                maxMultipartBytesPerOwner: $this->integerOption($input, 'quota-max-multipart-bytes-per-owner'),
            ),
            lifecycleIntervalSeconds: $this->floatOption($input, 'lifecycle-interval-seconds'),
            lifecycleBatchSize: $this->integerOption($input, 'lifecycle-batch-size'),
            lifecycleMaxActionsPerRun: $this->integerOption($input, 'lifecycle-max-actions-per-run'),
            lifecycleLockTtlSeconds: $this->integerOption($input, 'lifecycle-lock-ttl-seconds'),
            multipartMaxAgeSeconds: $this->integerOption($input, 'multipart-max-age-seconds'),
            metricsBearerToken: $this->optionalTokenOption($input, 'metrics-bearer-token'),
        );
    }

    private function integerOption(InputInterface $input, string $name): int
    {
        $raw = $input->getOption($name);
        if (! is_string($raw) && ! is_int($raw)) {
            throw new \InvalidArgumentException("--{$name} must be a decimal integer.");
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException("--{$name} must be a decimal integer within the supported range.");
        }

        return $value;
    }

    private function floatOption(InputInterface $input, string $name): float
    {
        $raw = $input->getOption($name);
        if ((! is_string($raw) && ! is_int($raw) && ! is_float($raw)) || ! is_numeric($raw)) {
            throw new \InvalidArgumentException("--{$name} must be a finite number.");
        }

        $value = (float) $raw;
        if (! is_finite($value)) {
            throw new \InvalidArgumentException("--{$name} must be a finite number.");
        }

        return $value;
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

            $workers = $this->integerOption($input, 'flysystem-workers');
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
                    connectTimeoutSeconds: $this->integerOption($input, 'backing-connect-timeout'),
                    readTimeoutSeconds: $this->integerOption($input, 'backing-read-timeout'),
                    requestTimeoutSeconds: $this->integerOption($input, 'backing-request-timeout'),
                    allowInsecureHttpForTests: $this->booleanOption($input, 'backing-allow-insecure-http-for-tests'),
                ),
                'worker_pool_size' => $workers,
                'read_first_byte_timeout_seconds' => $this->integerOption($input, 'flysystem-first-byte-timeout'),
                'temp_dir' => $tempDir,
                'temp_budget_bytes' => $this->integerOption($input, 'storage-temp-budget'),
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
            'worker_pool_size' => $this->sqliteWorkerPoolSize($input),
        ];
        if ($metadataDriver === 'sqlite') {
            $path = SqliteMetadataPathResolver::resolve(
                $storagePath ?? (string) $input->getOption('storage-temp-dir'),
                $this->nullableString($input->getOption('metadata-path')),
            );
            $this->ensureDirectory(dirname($path), 'SQLite metadata');
            $metadataConfig['path'] = $path;
        }

        $metadata = MetadataStoreFactory::create($metadataDriver, $metadataConfig, metrics: $metrics, migrate: $metadataDriver === 'sqlite');
        $cacheTtl = $this->floatOption($input, 'metadata-cache-ttl');
        if ($cacheTtl < 0) {
            throw new \InvalidArgumentException('--metadata-cache-ttl must be >= 0.');
        }
        if ($cacheTtl > 0) {
            $metadata = new CachedMetadataStoreDecorator($metadata, $cacheTtl);
        }

        return new ObservedMetadataStore($metadata, $metrics, $metadataDriver);
    }

    private function sqliteWorkerPoolSize(InputInterface $input): int
    {
        if ((string) $input->getOption('metadata-driver') === 'sqlite'
            && $this->nullableString($input->getOption('metadata-path')) === ':memory:') {
            return 0;
        }

        return $this->integerOption($input, 'sqlite-workers');
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

    private function optionalTokenOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new \InvalidArgumentException("--{$name} must be a string.");
        }

        return $value;
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
            return CredentialProviderFactory::createForServing(
                $driver,
                $config,
                $this->integerOption($input, 'credentials-workers'),
                $this->floatOption($input, 'credentials-operation-timeout'),
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>Error: ' . ServeFailureMessage::format($e, 'bootstrap') . '</error>');

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function externalIamConfigFromEnv(): array
    {
        return [
            'enabled' => self::environmentValue('S3_EXTERNAL_IAM_ENABLED', false),
            'admin_token' => self::environmentValue('S3_EXTERNAL_IAM_ADMIN_TOKEN'),
            'issuer' => self::environmentValue('S3_EXTERNAL_IAM_ISSUER'),
            'audience' => self::environmentValue('S3_EXTERNAL_IAM_AUDIENCE'),
            'jwks_path' => self::environmentValue('S3_EXTERNAL_IAM_JWKS_PATH'),
            'public_key_path' => self::environmentValue('S3_EXTERNAL_IAM_PUBLIC_KEY_PATH'),
            'public_key' => self::environmentValue('S3_EXTERNAL_IAM_PUBLIC_KEY'),
            'owner_claim' => self::environmentValue('S3_EXTERNAL_IAM_OWNER_CLAIM', 'sub'),
            'display_name_claim' => self::environmentValue('S3_EXTERNAL_IAM_DISPLAY_NAME_CLAIM', 'preferred_username'),
            'groups_claim' => self::environmentValue('S3_EXTERNAL_IAM_GROUPS_CLAIM', 'groups'),
            'policy_names_claim' => self::environmentValue('S3_EXTERNAL_IAM_POLICY_NAMES_CLAIM'),
            'allowed_prefixes_claim' => self::environmentValue('S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM'),
            'owner_prefix' => self::environmentValue('S3_EXTERNAL_IAM_OWNER_PREFIX', ''),
            'clock_skew_seconds' => self::environmentValue('S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS', 60),
        ];
    }

    private static function environmentValue(string $name, string|bool|int|null $default = null): string|bool|int|null
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }

    private function adminApiTokenFromEnv(): ?string
    {
        $token = getenv('S3_ADMIN_API_TOKEN');
        if ($token !== false) {
            return trim($token);
        }

        $externalIamToken = getenv('S3_EXTERNAL_IAM_ADMIN_TOKEN');
        if ($externalIamToken !== false && trim($externalIamToken) !== '') {
            return trim($externalIamToken);
        }

        return null;
    }
}
