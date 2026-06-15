<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony\Command;

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'opsfour:s3:serve', description: 'Start the OpsFour S3-compatible server')]
final class S3ServerServeCommand extends Command
{
    /**
     * @param array<string, mixed> $externalIamConfig
     * @param list<array{pattern: string, listener: callable}> $notificationListeners
     */
    public function __construct(
        private readonly S3ServerConfig $config,
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly CredentialProvider $credentialProvider,
        private readonly MetricsCollector $metrics,
        private readonly StorageTierRegistry $storageTiers,
        private readonly S3ServerRuntimeFactory $runtimeFactory,
        private readonly array $externalIamConfig = [],
        private readonly ?string $adminToken = null,
        private readonly array $notificationListeners = [],
        private readonly ?EncryptionServiceInterface $encryption = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen address override')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port override')
            ->addOption('admin-token', null, InputOption::VALUE_REQUIRED, 'Admin API token override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->configWithOverrides($input);
        $adminToken = $input->getOption('admin-token');
        $adminToken = is_string($adminToken) && trim($adminToken) !== ''
            ? trim($adminToken)
            : $this->adminToken;

        $logger = $this->logger();

        try {
            $runtime = $this->runtimeFactory->create(
                config: $config,
                metadata: $this->metadata,
                storage: $this->storage,
                credentialProvider: $this->credentialProvider,
                logger: $logger,
                metrics: $this->metrics,
                storageTiers: $this->storageTiers,
                externalIamConfig: $this->externalIamConfig,
                adminToken: $adminToken,
                encryption: $this->encryption,
                notificationListeners: $this->notificationListeners,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $output->writeln("OpsFour S3 Server starting on {$config->host}:{$config->port}");
        $output->writeln("Storage: {$config->storagePath}");
        if ($config->sqliteWorkerPoolSize > 0 && $config->metadataDriver === 'sqlite') {
            $output->writeln("SQLite worker pool: {$config->sqliteWorkerPoolSize} workers");
        }
        if ($config->encryptionWorkerPoolSize > 0) {
            $output->writeln("Encryption worker pool: {$config->encryptionWorkerPoolSize} workers");
        }
        $output->writeln('Press Ctrl+C to stop.');

        $runtime->server->start();

        $signal = \Amp\trapSignal([\SIGINT, \SIGTERM]);
        $logger->info('Received signal {signal}, shutting down...', [
            'signal' => $signal === \SIGINT ? 'SIGINT' : 'SIGTERM',
        ]);

        $runtime->stop();

        $output->writeln('Server stopped.');

        return Command::SUCCESS;
    }

    private function configWithOverrides(InputInterface $input): S3ServerConfig
    {
        $overrides = [];

        $host = $input->getOption('host');
        if (is_string($host) && $host !== '') {
            $overrides['host'] = $host;
        }

        $port = $input->getOption('port');
        if ($port !== null && $port !== '') {
            $overrides['port'] = (int) $port;
        }

        return $overrides === [] ? $this->config : $this->config->with($overrides);
    }

    private function logger(): Logger
    {
        $logHandler = new StreamHandler(ByteStream\getStdout());
        $logHandler->pushProcessor(new PsrLogMessageProcessor);
        $logHandler->setFormatter(new ConsoleFormatter);

        $logger = new Logger('s3-server');
        $logger->pushHandler($logHandler);

        return $logger;
    }
}
