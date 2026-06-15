<?php

declare(strict_types=1);

namespace OpsFour\S3Server;

use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * Immutable configuration DTO for the S3 server.
 *
 * Uses PHP 8.4 property hooks for validation on construction.
 * All properties are readonly via constructor promotion.
 */
final class S3ServerConfig
{
    /** @var list<string> Allowed metadata driver values. */
    private const array ALLOWED_METADATA_DRIVERS = ['sqlite', 'postgres', 'mysql'];

    public string $host {
        get => $this->host;
    }

    public int $port {
        get => $this->port;
    }

    public string $region {
        get => $this->region;
    }

    public string $storagePath {
        get => $this->storagePath;
    }

    /** @var array<string, mixed> */
    public array $storageTiers {
        get => $this->storageTiers;
    }

    public string $metadataDriver {
        get => $this->metadataDriver;
    }

    public string $metadataDsn {
        get => $this->metadataDsn;
    }

    public ?string $tlsCertPath {
        get => $this->tlsCertPath;
    }

    public ?string $tlsKeyPath {
        get => $this->tlsKeyPath;
    }

    public int $maxConcurrentConnections {
        get => $this->maxConcurrentConnections;
    }

    /** @internal Reserved for future use. */
    public int $connectionIdleTimeout {
        get => $this->connectionIdleTimeout;
    }

    public int $requestBodySizeLimit {
        get => $this->requestBodySizeLimit;
    }

    /** @internal Reserved for future use. */
    public int $readTimeout {
        get => $this->readTimeout;
    }

    /** @internal Reserved for future use. */
    public int $writeTimeout {
        get => $this->writeTimeout;
    }

    public int $perClientRateLimit {
        get => $this->perClientRateLimit;
    }

    public ?string $baseDomain {
        get => $this->baseDomain;
    }

    public bool $strictBucketNaming {
        get => $this->strictBucketNaming;
    }

    public ?string $websiteHostPattern {
        get => $this->websiteHostPattern;
    }

    /** @internal Reserved for future use. */
    public string $masterKeyProvider {
        get => $this->masterKeyProvider;
    }

    public bool $enforceMinPartSize {
        get => $this->enforceMinPartSize;
    }

    public int $maxEncryptedObjectSize {
        get => $this->maxEncryptedObjectSize;
    }

    public int $maxSelectObjectSize {
        get => $this->maxSelectObjectSize;
    }

    public int $shutdownDrainTimeout {
        get => $this->shutdownDrainTimeout;
    }

    public int $sqliteWorkerPoolSize {
        get => $this->sqliteWorkerPoolSize;
    }

    public int $encryptionWorkerPoolSize {
        get => $this->encryptionWorkerPoolSize;
    }

    public int $encryptionParallelThreshold {
        get => $this->encryptionParallelThreshold;
    }

    public QuotaConfig $quota {
        get => $this->quota;
    }

    public float $lifecycleIntervalSeconds {
        get => $this->lifecycleIntervalSeconds;
    }

    public int $lifecycleBatchSize {
        get => $this->lifecycleBatchSize;
    }

    public int $lifecycleMaxActionsPerRun {
        get => $this->lifecycleMaxActionsPerRun;
    }

    public int $lifecycleLockTtlSeconds {
        get => $this->lifecycleLockTtlSeconds;
    }

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
     * @param  int  $connectionIdleTimeout  Seconds before idle connection is closed.
     * @param  int  $requestBodySizeLimit  Maximum request body size in bytes (default 5 GiB).
     * @param  int  $readTimeout  Read timeout in seconds.
     * @param  int  $writeTimeout  Write timeout in seconds.
     * @param  int  $perClientRateLimit  Per-client rate limit (0 = unlimited).
     * @param  string|null  $baseDomain  Base domain for virtual-hosted-style requests.
     * @param  bool  $strictBucketNaming  Enforce strict S3 bucket naming rules.
     * @param  string|null  $websiteHostPattern  Glob pattern for website hosting endpoints (e.g., '*.s3-website.example.com').
     * @param  string  $masterKeyProvider  Master key provider type: 'config', 'redis', or 'vault'.
     * @param  bool  $enforceMinPartSize  Enforce 5 MiB minimum part size on CompleteMultipartUpload (default true, matches AWS S3).
     * @param  int  $maxEncryptedObjectSize  Maximum size in bytes for objects encrypted with SSE (default 256 MiB).
     * @param  int  $maxSelectObjectSize  Maximum size in bytes for S3 Select queries (default 256 MiB).
     * @param  int  $shutdownDrainTimeout  Seconds to wait for in-flight requests on shutdown (default 30).
     * @param  int  $sqliteWorkerPoolSize  Number of amphp/parallel workers for SQLite (0 = blocking mode).
     * @param  int  $encryptionWorkerPoolSize  Number of amphp/parallel workers for encryption (0 = disable).
     * @param  int  $encryptionParallelThreshold  Payloads below this size run inline (bytes).
     * @param  float  $lifecycleIntervalSeconds  Seconds between lifecycle sweeps.
     * @param  int  $lifecycleBatchSize  Maximum rows fetched per lifecycle query.
     * @param  int  $lifecycleMaxActionsPerRun  Maximum destructive lifecycle actions per sweep.
     * @param  int  $lifecycleLockTtlSeconds  Seconds before a lifecycle lease is considered stale.
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
        bool $strictBucketNaming = false,
        ?string $websiteHostPattern = null,
        string $masterKeyProvider = 'config',
        bool $enforceMinPartSize = false,
        int $maxEncryptedObjectSize = 268_435_456,
        int $maxSelectObjectSize = 268_435_456,
        int $shutdownDrainTimeout = 30,
        int $sqliteWorkerPoolSize = 0,
        int $encryptionWorkerPoolSize = 0,
        int $encryptionParallelThreshold = 65_536,
        ?QuotaConfig $quota = null,
        float $lifecycleIntervalSeconds = 60.0,
        int $lifecycleBatchSize = 1000,
        int $lifecycleMaxActionsPerRun = 1000,
        int $lifecycleLockTtlSeconds = 300,
    ) {
        if ($storagePath === '') {
            throw new \InvalidArgumentException('storagePath is required and cannot be empty.');
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

        if ($perClientRateLimit < 0) {
            throw new \InvalidArgumentException(
                "perClientRateLimit must be >= 0, got {$perClientRateLimit}.",
            );
        }

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

        if ($lifecycleIntervalSeconds <= 0.0) {
            throw new \InvalidArgumentException('lifecycleIntervalSeconds must be > 0.');
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

        $this->host = $host;
        $this->port = $port;
        $this->region = $region;
        $this->storagePath = rtrim($storagePath, '/');
        $this->storageTiers = $storageTiers;
        $this->metadataDriver = $metadataDriver;
        $this->metadataDsn = $metadataDsn;
        $this->tlsCertPath = $tlsCertPath;
        $this->tlsKeyPath = $tlsKeyPath;
        $this->maxConcurrentConnections = $maxConcurrentConnections;
        $this->connectionIdleTimeout = $connectionIdleTimeout;
        $this->requestBodySizeLimit = $requestBodySizeLimit;
        $this->readTimeout = $readTimeout;
        $this->writeTimeout = $writeTimeout;
        $this->perClientRateLimit = $perClientRateLimit;
        $this->baseDomain = $baseDomain;
        $this->strictBucketNaming = $strictBucketNaming;
        $this->websiteHostPattern = $websiteHostPattern;
        $this->masterKeyProvider = $masterKeyProvider;
        $this->enforceMinPartSize = $enforceMinPartSize;
        $this->maxEncryptedObjectSize = $maxEncryptedObjectSize;
        $this->maxSelectObjectSize = $maxSelectObjectSize;
        $this->shutdownDrainTimeout = $shutdownDrainTimeout;
        $this->sqliteWorkerPoolSize = $sqliteWorkerPoolSize;
        $this->encryptionWorkerPoolSize = $encryptionWorkerPoolSize;
        $this->encryptionParallelThreshold = $encryptionParallelThreshold;
        $this->quota = $quota ?? new QuotaConfig();
        $this->lifecycleIntervalSeconds = $lifecycleIntervalSeconds;
        $this->lifecycleBatchSize = $lifecycleBatchSize;
        $this->lifecycleMaxActionsPerRun = $lifecycleMaxActionsPerRun;
        $this->lifecycleLockTtlSeconds = $lifecycleLockTtlSeconds;
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
            'metadataDriver' => $this->metadataDriver,
            'metadataDsn' => $this->metadataDsn,
            'tlsCertPath' => $this->tlsCertPath,
            'tlsKeyPath' => $this->tlsKeyPath,
            'maxConcurrentConnections' => $this->maxConcurrentConnections,
            'connectionIdleTimeout' => $this->connectionIdleTimeout,
            'requestBodySizeLimit' => $this->requestBodySizeLimit,
            'readTimeout' => $this->readTimeout,
            'writeTimeout' => $this->writeTimeout,
            'perClientRateLimit' => $this->perClientRateLimit,
            'baseDomain' => $this->baseDomain,
            'strictBucketNaming' => $this->strictBucketNaming,
            'websiteHostPattern' => $this->websiteHostPattern,
            'masterKeyProvider' => $this->masterKeyProvider,
            'enforceMinPartSize' => $this->enforceMinPartSize,
            'maxEncryptedObjectSize' => $this->maxEncryptedObjectSize,
            'maxSelectObjectSize' => $this->maxSelectObjectSize,
            'shutdownDrainTimeout' => $this->shutdownDrainTimeout,
            'sqliteWorkerPoolSize' => $this->sqliteWorkerPoolSize,
            'encryptionWorkerPoolSize' => $this->encryptionWorkerPoolSize,
            'encryptionParallelThreshold' => $this->encryptionParallelThreshold,
            'quota' => $this->quota,
            'lifecycleIntervalSeconds' => $this->lifecycleIntervalSeconds,
            'lifecycleBatchSize' => $this->lifecycleBatchSize,
            'lifecycleMaxActionsPerRun' => $this->lifecycleMaxActionsPerRun,
            'lifecycleLockTtlSeconds' => $this->lifecycleLockTtlSeconds,
        ];

        $merged = array_merge($defaults, $overrides);

        return new self(...$merged);
    }
}
