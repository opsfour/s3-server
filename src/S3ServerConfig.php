<?php

declare(strict_types=1);

namespace OpsFour\S3Server;

use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;

/**
 * Immutable configuration DTO for the S3 server.
 *
 * Values are validated during construction. Use with() to derive another config.
 */
final readonly class S3ServerConfig
{
    /** @var list<string> Allowed metadata driver values. */
    private const array ALLOWED_METADATA_DRIVERS = ['sqlite', 'postgres', 'mysql'];

    public string $host;

    public int $port;

    public string $region;

    public string $storagePath;

    /** @var array<string, mixed> */
    public array $storageTiers;

    public bool $allowUnfencedStorageForTests;

    /** Explicit, isolated PUT/Copy fence wiring exercise; not a cluster admission. */
    public ?string $localObjectWriteTestScratchPath;


    public string $metadataDriver;

    public string $metadataDsn;

    public ?string $tlsCertPath;

    public ?string $tlsKeyPath;

    public int $maxConcurrentConnections;

    public int $connectionIdleTimeout;

    public int $requestBodySizeLimit;

    public int $postSpoolBudgetBytes;

    public int $readTimeout;

    public int $writeTimeout;

    public int $perClientRateLimit;

    /** @var list<string> */
    public array $trustedProxyCidrs;

    public ?string $baseDomain;

    public bool $strictBucketNaming;

    public ?string $websiteHostPattern;

    public string $masterKeyProvider;

    public bool $enforceMinPartSize;

    public int $maxEncryptedObjectSize;

    public int $maxSelectObjectSize;

    public int $shutdownDrainTimeout;

    public bool $notificationRequireHttps;

    public bool $notificationWorkerEnabled;

    public int $sqliteWorkerPoolSize;

    public int $encryptionWorkerPoolSize;

    public int $selectWorkerPoolSize;

    public int $requestBodySpoolWorkerPoolSize;

    public int $encryptionParallelThreshold;

    public QuotaConfig $quota;

    public float $lifecycleIntervalSeconds;

    public int $lifecycleBatchSize;

    public int $lifecycleMaxActionsPerRun;

    public int $lifecycleLockTtlSeconds;

    public int $multipartMaxAgeSeconds;

    public ?string $metricsBearerToken;

    /**
     * @param  string  $host  Listen address.
     * @param  int  $port  Listen port.
     * @param  string  $region  AWS region identifier.
     * @param  string  $storagePath  Root path for object storage on disk.
     * @param  array<string, mixed>  $storageTiers  Optional physical storage tier configuration.
     * @param  string  $metadataDriver  One of: sqlite, postgres, mysql.
     * @param  string  $metadataDsn  DSN for the metadata database.
     * @param  string|null  $tlsCertPath  Path to TLS certificate file.
     * @param  string|null  $tlsKeyPath  Path to TLS private key file.
     * @param  int  $maxConcurrentConnections  Maximum simultaneous connections.
     * @param  int  $connectionIdleTimeout  HTTP/2 idle timeout in seconds; Amp HTTP/1 uses the stream inactivity timeout.
     * @param  int  $requestBodySizeLimit  Maximum request body size in bytes (default 5 GiB).
     * @param  int  $postSpoolBudgetBytes  Concurrent browser POST temporary bytes per node sharing a local temp directory (default 10 GiB).
     * @param  int  $readTimeout  Read timeout in seconds.
     * @param  int  $writeTimeout  Write timeout in seconds.
     * @param  int  $perClientRateLimit  Per-client rate limit (0 = unlimited).
     * @param  list<string>  $trustedProxyCidrs  Proxy peer CIDRs allowed to supply forwarded transport and client IP.
     * @param  string|null  $baseDomain  Base domain for virtual-hosted-style requests.
     * @param  bool  $strictBucketNaming  Reject AWS-reserved bucket name prefixes and suffixes.
     * @param  string|null  $websiteHostPattern  Glob pattern for website hosting endpoints (e.g., '*.s3-website.example.com').
     * @param  string  $masterKeyProvider  Master key provider type: 'config', 'redis', or 'vault'.
     * @param  bool  $enforceMinPartSize  Enforce 5 MiB minimum part size on CompleteMultipartUpload (default true, matches AWS S3).
     * @param  int  $maxEncryptedObjectSize  Maximum size in bytes for objects encrypted with SSE (default 256 MiB).
     * @param  int  $maxSelectObjectSize  Maximum size in bytes for buffered S3 Select queries (default 64 MiB).
     * @param  int  $shutdownDrainTimeout  Maximum seconds to drain in-flight shutdown work (default 30).
     * @param  bool  $notificationRequireHttps  Reject non-HTTPS webhook destinations.
     * @param  int  $sqliteWorkerPoolSize  Number of amphp/parallel workers for SQLite (0 = blocking mode).
     * @param  int  $encryptionWorkerPoolSize  Number of amphp/parallel workers for encryption (0 = disable).
     * @param  int  $requestBodySpoolWorkerPoolSize  Number of workers for request-body checksum spooling.
     * @param  int  $encryptionParallelThreshold  Payloads below this size run inline (bytes).
     * @param  float  $lifecycleIntervalSeconds  Seconds between lifecycle sweeps.
     * @param  int  $lifecycleBatchSize  Maximum rows fetched per lifecycle query.
     * @param  int  $lifecycleMaxActionsPerRun  Maximum destructive lifecycle actions per sweep.
     * @param  int  $lifecycleLockTtlSeconds  Seconds before a lifecycle lease is considered stale.
     * @param  int  $multipartMaxAgeSeconds  Global maximum age for incomplete multipart uploads (0 disables cleanup).
     * @param  int|null  $selectWorkerPoolSize  Number of amphp/parallel workers for S3 Select (null defaults to one isolated worker).
     * @param  bool  $notificationWorkerEnabled  Process queued webhook deliveries on this server instance.
     * @param  bool  $allowUnfencedStorageForTests  Unsafe opt-in for storage fault tests; never enable in production.
     * @param  string|null  $localObjectWriteTestScratchPath  Private node-local scratch for the isolated PUT/Copy fence test profile.
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 9000,
        string $region = 'us-east-1',
        string $storagePath = '',
        array $storageTiers = [],
        string $metadataDriver = 'sqlite',
        string $metadataDsn = '',
        ?string $tlsCertPath = null,
        ?string $tlsKeyPath = null,
        int $maxConcurrentConnections = 10000,
        int $connectionIdleTimeout = 60,
        int $requestBodySizeLimit = 5_368_709_120,
        int $readTimeout = 300,
        int $writeTimeout = 300,
        int $perClientRateLimit = 1000,
        ?string $baseDomain = null,
        bool $strictBucketNaming = true,
        ?string $websiteHostPattern = null,
        string $masterKeyProvider = 'config',
        bool $enforceMinPartSize = true,
        int $maxEncryptedObjectSize = 268_435_456,
        int $maxSelectObjectSize = 67_108_864,
        int $shutdownDrainTimeout = 30,
        bool $notificationRequireHttps = true,
        int $sqliteWorkerPoolSize = 0,
        int $encryptionWorkerPoolSize = 0,
        int $requestBodySpoolWorkerPoolSize = 8,
        int $encryptionParallelThreshold = 65_536,
        ?QuotaConfig $quota = null,
        float $lifecycleIntervalSeconds = 60.0,
        int $lifecycleBatchSize = 1000,
        int $lifecycleMaxActionsPerRun = 1000,
        int $lifecycleLockTtlSeconds = 300,
        int $multipartMaxAgeSeconds = 604_800,
        ?string $metricsBearerToken = null,
        ?int $selectWorkerPoolSize = null,
        bool $notificationWorkerEnabled = true,
        int $postSpoolBudgetBytes = 10_737_418_240,
        array $trustedProxyCidrs = [],
        bool $allowUnfencedStorageForTests = false,
        ?string $localObjectWriteTestScratchPath = null,
    ) {
        if ($storagePath === '') {
            throw new \InvalidArgumentException('storagePath is required and cannot be empty.');
        }

        if ($baseDomain !== null && trim($baseDomain) === '') {
            throw new \InvalidArgumentException('baseDomain must be a non-empty domain when configured.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                "Port must be between 1 and 65535, got {$port}.",
            );
        }

        if (! in_array($metadataDriver, self::ALLOWED_METADATA_DRIVERS, true)) {
            throw new \InvalidArgumentException(
                'metadataDriver must be one of: ' . implode(', ', self::ALLOWED_METADATA_DRIVERS)
                . ", got '{$metadataDriver}'.",
            );
        }

        if ($host === '') {
            throw new \InvalidArgumentException('host cannot be empty.');
        }

        if ($region === '') {
            throw new \InvalidArgumentException('region cannot be empty.');
        }

        if (! in_array($masterKeyProvider, ['config', 'redis', 'vault'], true)) {
            throw new \InvalidArgumentException('masterKeyProvider must be one of: config, redis, vault.');
        }

        if ($maxConcurrentConnections < 1) {
            throw new \InvalidArgumentException(
                "maxConcurrentConnections must be >= 1, got {$maxConcurrentConnections}.",
            );
        }

        if ($connectionIdleTimeout < 0) {
            throw new \InvalidArgumentException(
                "connectionIdleTimeout must be >= 0, got {$connectionIdleTimeout}.",
            );
        }

        if ($requestBodySizeLimit < 0) {
            throw new \InvalidArgumentException(
                "requestBodySizeLimit must be >= 0, got {$requestBodySizeLimit}.",
            );
        }

        if ($postSpoolBudgetBytes < 1) {
            throw new \InvalidArgumentException('postSpoolBudgetBytes must be positive.');
        }

        if ($localObjectWriteTestScratchPath !== null && (
            trim($localObjectWriteTestScratchPath) === '' || ! $allowUnfencedStorageForTests
        )) {
            throw new \InvalidArgumentException('Local object write fencing is an isolated test profile and requires the unsafe test-only storage override plus a scratch path.');
        }


        if ($metricsBearerToken !== null && trim($metricsBearerToken) === '') {
            throw new \InvalidArgumentException('metricsBearerToken must not be empty when configured.');
        }

        if ($readTimeout < 0) {
            throw new \InvalidArgumentException(
                "readTimeout must be >= 0, got {$readTimeout}.",
            );
        }

        if ($writeTimeout < 0) {
            throw new \InvalidArgumentException(
                "writeTimeout must be >= 0, got {$writeTimeout}.",
            );
        }

        if ($shutdownDrainTimeout < 1) {
            throw new \InvalidArgumentException(
                "shutdownDrainTimeout must be >= 1, got {$shutdownDrainTimeout}.",
            );
        }

        if ($requestBodySpoolWorkerPoolSize < 1) {
            throw new \InvalidArgumentException(
                "requestBodySpoolWorkerPoolSize must be >= 1, got {$requestBodySpoolWorkerPoolSize}.",
            );
        }

        if ($sqliteWorkerPoolSize < 0) {
            throw new \InvalidArgumentException(
                "sqliteWorkerPoolSize must be >= 0, got {$sqliteWorkerPoolSize}.",
            );
        }

        if ($encryptionWorkerPoolSize < 0) {
            throw new \InvalidArgumentException(
                "encryptionWorkerPoolSize must be >= 0, got {$encryptionWorkerPoolSize}.",
            );
        }

        if ($selectWorkerPoolSize !== null && $selectWorkerPoolSize < 0) {
            throw new \InvalidArgumentException(
                "selectWorkerPoolSize must be >= 0, got {$selectWorkerPoolSize}.",
            );
        }

        if ($encryptionParallelThreshold < 0) {
            throw new \InvalidArgumentException(
                "encryptionParallelThreshold must be >= 0, got {$encryptionParallelThreshold}.",
            );
        }

        if ($maxEncryptedObjectSize < 1) {
            throw new \InvalidArgumentException(
                "maxEncryptedObjectSize must be >= 1, got {$maxEncryptedObjectSize}.",
            );
        }

        if ($maxSelectObjectSize < 1) {
            throw new \InvalidArgumentException(
                "maxSelectObjectSize must be >= 1, got {$maxSelectObjectSize}.",
            );
        }

        if ($perClientRateLimit < 0) {
            throw new \InvalidArgumentException(
                "perClientRateLimit must be >= 0, got {$perClientRateLimit}.",
            );
        }

        new TrustedProxyMiddleware($trustedProxyCidrs);

        if ($tlsCertPath !== null && $tlsKeyPath === null) {
            throw new \InvalidArgumentException(
                'tlsKeyPath is required when tlsCertPath is set.',
            );
        }

        if ($tlsKeyPath !== null && $tlsCertPath === null) {
            throw new \InvalidArgumentException(
                'tlsCertPath is required when tlsKeyPath is set.',
            );
        }

        if (! is_finite($lifecycleIntervalSeconds) || $lifecycleIntervalSeconds <= 0.0) {
            throw new \InvalidArgumentException('lifecycleIntervalSeconds must be finite and > 0.');
        }

        if ($lifecycleBatchSize < 1) {
            throw new \InvalidArgumentException('lifecycleBatchSize must be >= 1.');
        }

        if ($lifecycleMaxActionsPerRun < 1) {
            throw new \InvalidArgumentException('lifecycleMaxActionsPerRun must be >= 1.');
        }

        if ($lifecycleLockTtlSeconds < 1) {
            throw new \InvalidArgumentException('lifecycleLockTtlSeconds must be >= 1.');
        }

        if ($multipartMaxAgeSeconds < 0) {
            throw new \InvalidArgumentException('multipartMaxAgeSeconds must be >= 0.');
        }

        $this->host = $host;
        $this->port = $port;
        $this->region = $region;
        $this->storagePath = rtrim($storagePath, '/') ?: '/';
        $this->storageTiers = $storageTiers;
        $this->allowUnfencedStorageForTests = $allowUnfencedStorageForTests;
        $this->localObjectWriteTestScratchPath = $localObjectWriteTestScratchPath;
        $this->metadataDriver = $metadataDriver;
        $this->metadataDsn = $metadataDsn;
        $this->tlsCertPath = $tlsCertPath;
        $this->tlsKeyPath = $tlsKeyPath;
        $this->maxConcurrentConnections = $maxConcurrentConnections;
        $this->connectionIdleTimeout = $connectionIdleTimeout;
        $this->requestBodySizeLimit = $requestBodySizeLimit;
        $this->postSpoolBudgetBytes = $postSpoolBudgetBytes;
        $this->readTimeout = $readTimeout;
        $this->writeTimeout = $writeTimeout;
        $this->perClientRateLimit = $perClientRateLimit;
        $this->trustedProxyCidrs = $trustedProxyCidrs;
        $this->baseDomain = $baseDomain;
        $this->strictBucketNaming = $strictBucketNaming;
        $this->websiteHostPattern = $websiteHostPattern;
        $this->masterKeyProvider = $masterKeyProvider;
        $this->enforceMinPartSize = $enforceMinPartSize;
        $this->maxEncryptedObjectSize = $maxEncryptedObjectSize;
        $this->maxSelectObjectSize = $maxSelectObjectSize;
        $this->shutdownDrainTimeout = $shutdownDrainTimeout;
        $this->notificationRequireHttps = $notificationRequireHttps;
        $this->notificationWorkerEnabled = $notificationWorkerEnabled;
        $this->sqliteWorkerPoolSize = $sqliteWorkerPoolSize;
        $this->encryptionWorkerPoolSize = $encryptionWorkerPoolSize;
        $this->selectWorkerPoolSize = $selectWorkerPoolSize ?? 1;
        $this->requestBodySpoolWorkerPoolSize = $requestBodySpoolWorkerPoolSize;
        $this->encryptionParallelThreshold = $encryptionParallelThreshold;
        $this->quota = $quota ?? new QuotaConfig();
        $this->lifecycleIntervalSeconds = $lifecycleIntervalSeconds;
        $this->lifecycleBatchSize = $lifecycleBatchSize;
        $this->lifecycleMaxActionsPerRun = $lifecycleMaxActionsPerRun;
        $this->lifecycleLockTtlSeconds = $lifecycleLockTtlSeconds;
        $this->multipartMaxAgeSeconds = $multipartMaxAgeSeconds;
        $this->metricsBearerToken = $metricsBearerToken !== null ? trim($metricsBearerToken) : null;
    }

    /**
     * Whether TLS is enabled.
     */
    public function isTlsEnabled(): bool
    {
        return $this->tlsCertPath !== null && $this->tlsKeyPath !== null;
    }

    /**
     * Whether virtual-hosted-style bucket addressing is enabled.
     */
    public function isVirtualHostedStyle(): bool
    {
        return $this->baseDomain !== null;
    }

    /**
     * Create a new config with overridden values.
     *
     * @param  array<string, mixed>  $overrides  Key-value pairs matching constructor parameter names.
     * @return self A new config instance with the overrides applied.
     */
    public function with(array $overrides): self
    {
        $defaults = [
            'host' => $this->host,
            'port' => $this->port,
            'region' => $this->region,
            'storagePath' => $this->storagePath,
            'storageTiers' => $this->storageTiers,
            'allowUnfencedStorageForTests' => $this->allowUnfencedStorageForTests,
            'localObjectWriteTestScratchPath' => $this->localObjectWriteTestScratchPath,
            'metadataDriver' => $this->metadataDriver,
            'metadataDsn' => $this->metadataDsn,
            'tlsCertPath' => $this->tlsCertPath,
            'tlsKeyPath' => $this->tlsKeyPath,
            'maxConcurrentConnections' => $this->maxConcurrentConnections,
            'connectionIdleTimeout' => $this->connectionIdleTimeout,
            'requestBodySizeLimit' => $this->requestBodySizeLimit,
            'postSpoolBudgetBytes' => $this->postSpoolBudgetBytes,
            'readTimeout' => $this->readTimeout,
            'writeTimeout' => $this->writeTimeout,
            'perClientRateLimit' => $this->perClientRateLimit,
            'trustedProxyCidrs' => $this->trustedProxyCidrs,
            'baseDomain' => $this->baseDomain,
            'strictBucketNaming' => $this->strictBucketNaming,
            'websiteHostPattern' => $this->websiteHostPattern,
            'masterKeyProvider' => $this->masterKeyProvider,
            'enforceMinPartSize' => $this->enforceMinPartSize,
            'maxEncryptedObjectSize' => $this->maxEncryptedObjectSize,
            'maxSelectObjectSize' => $this->maxSelectObjectSize,
            'shutdownDrainTimeout' => $this->shutdownDrainTimeout,
            'notificationRequireHttps' => $this->notificationRequireHttps,
            'sqliteWorkerPoolSize' => $this->sqliteWorkerPoolSize,
            'encryptionWorkerPoolSize' => $this->encryptionWorkerPoolSize,
            'selectWorkerPoolSize' => $this->selectWorkerPoolSize,
            'requestBodySpoolWorkerPoolSize' => $this->requestBodySpoolWorkerPoolSize,
            'encryptionParallelThreshold' => $this->encryptionParallelThreshold,
            'quota' => $this->quota,
            'lifecycleIntervalSeconds' => $this->lifecycleIntervalSeconds,
            'lifecycleBatchSize' => $this->lifecycleBatchSize,
            'lifecycleMaxActionsPerRun' => $this->lifecycleMaxActionsPerRun,
            'lifecycleLockTtlSeconds' => $this->lifecycleLockTtlSeconds,
            'multipartMaxAgeSeconds' => $this->multipartMaxAgeSeconds,
            'metricsBearerToken' => $this->metricsBearerToken,
            'notificationWorkerEnabled' => $this->notificationWorkerEnabled,
        ];

        $merged = array_merge($defaults, $overrides);

        return new self(...$merged);
    }
}
