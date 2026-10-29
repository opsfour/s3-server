<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony;

use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Runtime\PostgresApiDriverGuard;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;

final class S3ServerConfigFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): S3ServerConfig
    {
        $server = $config['server'];
        $storage = $config['storage'];
        $metadata = $config['metadata'];
        $parallel = $config['parallel'];
        $quotas = $config['quotas'];
        $lifecycle = $config['lifecycle'];
        $notifications = $config['notifications'];
        $masterKeyProvider = $server['master_key_provider'];
        if ($masterKeyProvider === null) {
            $environmentProvider = getenv('S3_MASTER_KEY_PROVIDER');
            $masterKeyProvider = $environmentProvider === false ? 'config' : $environmentProvider;
        }
        if (! is_string($masterKeyProvider)) {
            throw new \InvalidArgumentException('masterKeyProvider must be a string.');
        }

        $serverConfig = new S3ServerConfig(
            host: $server['host'],
            port: $server['port'],
            region: $server['region'],
            clusterId: $server['cluster_id'],
            clusterNodeId: $server['cluster_node_id'],
            clusterDatabaseId: $server['cluster_database_id'],
            clusterRoles: $server['cluster_roles'],
            clusterRequiredRoles: $server['cluster_required_roles'],
            storagePath: $storage['path'],
            storageTiers: $storage['tiers'],
            allowUnfencedStorageForTests: $server['allow_unfenced_storage_for_tests'],
            metadataDriver: $metadata['driver'],
            metadataDsn: $metadata['dsn'],
            tlsCertPath: $server['tls_cert_path'],
            tlsKeyPath: $server['tls_key_path'],
            maxConcurrentConnections: $server['max_connections'],
            connectionIdleTimeout: $server['connection_idle_timeout'],
            requestBodySizeLimit: $server['body_size_limit'],
            postSpoolBudgetBytes: $server['post_spool_budget'] ?? null,
            storageTempDir: $storage['temp_dir'],
            storageTempBudgetBytes: $storage['temp_budget_bytes'],
            storageTempReserveBytes: $storage['temp_reserve_bytes'],
            localWriteWorkerTimeoutSeconds: $server['local_write_worker_timeout_seconds'] ?? 300.0,
            readTimeout: $server['read_timeout'],
            writeTimeout: $server['write_timeout'],
            perClientRateLimit: $server['rate_limit'],
            trustedProxyCidrs: TrustedProxyMiddleware::parseCidrs($server['trusted_proxy_cidrs']),
            baseDomain: $server['base_domain'],
            strictBucketNaming: $server['strict_bucket_naming'],
            websiteHostPattern: $server['website_host_pattern'],
            masterKeyProvider: $masterKeyProvider,
            enforceMinPartSize: $server['enforce_min_part_size'],
            maxEncryptedObjectSize: $server['max_encrypted_object_size'],
            maxSelectObjectSize: $server['max_select_object_size'],
            shutdownDrainTimeout: $server['shutdown_drain_timeout'],
            shutdownReadinessDelay: $server['shutdown_readiness_delay'],
            metricsBearerToken: $server['metrics_bearer_token'],
            notificationRequireHttps: $notifications['require_https'],
            notificationWorkerEnabled: $notifications['worker_enabled'],
            sqliteWorkerPoolSize: $parallel['sqlite_workers'],
            encryptionWorkerPoolSize: $parallel['encryption_workers'],
            selectWorkerPoolSize: $parallel['select_workers'],
            requestBodySpoolWorkerPoolSize: $parallel['request_body_spool_workers'],
            encryptionParallelThreshold: $parallel['encryption_threshold'],
            quota: new QuotaConfig(
                maxBucketsPerOwner: $quotas['max_buckets_per_owner'],
                maxObjectsPerBucket: $quotas['max_objects_per_bucket'],
                maxBytesPerBucket: $quotas['max_bytes_per_bucket'],
                maxBytesPerOwner: $quotas['max_bytes_per_owner'],
                maxMultipartUploadsPerBucket: $quotas['max_multipart_uploads_per_bucket'],
                maxMultipartUploadsPerOwner: $quotas['max_multipart_uploads_per_owner'],
                maxMultipartBytesPerBucket: $quotas['max_multipart_bytes_per_bucket'],
                maxMultipartBytesPerOwner: $quotas['max_multipart_bytes_per_owner'],
            ),
            lifecycleIntervalSeconds: $lifecycle['interval_seconds'],
            lifecycleBatchSize: $lifecycle['batch_size'],
            lifecycleMaxActionsPerRun: $lifecycle['max_actions_per_run'],
            lifecycleLockTtlSeconds: $lifecycle['lock_ttl_seconds'],
            multipartMaxAgeSeconds: $lifecycle['multipart_max_age_seconds'],
        );

        PostgresApiDriverGuard::assertAvailable($serverConfig);

        return $serverConfig;
    }
}
