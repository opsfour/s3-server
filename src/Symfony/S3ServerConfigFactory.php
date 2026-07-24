<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony;

use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\S3ServerConfig;

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

        return new S3ServerConfig(
            host: $server['host'],
            port: $server['port'],
            region: $server['region'],
            storagePath: $storage['path'],
            storageTiers: $storage['tiers'],
            metadataDriver: $metadata['driver'],
            metadataDsn: $metadata['dsn'],
            tlsCertPath: $server['tls_cert_path'],
            tlsKeyPath: $server['tls_key_path'],
            maxConcurrentConnections: $server['max_connections'],
            connectionIdleTimeout: $server['connection_idle_timeout'],
            requestBodySizeLimit: $server['body_size_limit'],
            readTimeout: $server['read_timeout'],
            writeTimeout: $server['write_timeout'],
            perClientRateLimit: $server['rate_limit'],
            baseDomain: $server['base_domain'],
            strictBucketNaming: $server['strict_bucket_naming'],
            websiteHostPattern: $server['website_host_pattern'],
            masterKeyProvider: $server['master_key_provider'],
            enforceMinPartSize: $server['enforce_min_part_size'],
            maxEncryptedObjectSize: $server['max_encrypted_object_size'],
            maxSelectObjectSize: $server['max_select_object_size'],
            shutdownDrainTimeout: $server['shutdown_drain_timeout'],
            metricsBearerToken: $server['metrics_bearer_token'],
            notificationRequireHttps: $notifications['require_https'],
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
    }
}
