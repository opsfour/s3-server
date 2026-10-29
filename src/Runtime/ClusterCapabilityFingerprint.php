<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Encryption\ClusterEncryptionIdentity;
use OpsFour\S3Server\Auth\ClusterAuthenticationIdentityProvider;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ClusterSafeStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/** Canonical, secret-free categories used by PostgreSQL cluster admission. */
final readonly class ClusterCapabilityFingerprint
{
    private const int MAX_CAPABILITY_IDENTITY_BYTES = 1024;

    public const string PROTOCOL = 'opsfour-s3-cluster-v1';

    public const string ROLES = 'all-in-one-v1';

    /** @param array<string, string> $digests */
    private function __construct(
        public string $clusterId,
        public string $nodeId,
        public string $incarnationId,
        public string $databaseIdentity,
        private array $digests,
    ) {}

    public static function fromRuntime(
        S3ServerConfig $config,
        StorageTierRegistry $tiers,
        ?EncryptionServiceInterface $encryption,
        string $databaseSchema,
        string $databaseIdentity,
        ?ClusterAuthenticationIdentityProvider $credentialProvider = null,
        ?string $externalAuthenticationIdentity = null,
    ): self {
        if (! $config->clusterEnabled() || $config->clusterId === null || $config->clusterNodeId === null
            || $config->clusterIncarnationId === null) {
            throw new \LogicException('Cluster fingerprint requires enabled cluster identities.');
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $databaseIdentity) !== 1) {
            throw new \RuntimeException('Cluster fingerprint requires a valid stable database identity.');
        }
        if ($config->clusterDatabaseId === null || ! hash_equals($config->clusterDatabaseId, $databaseIdentity)) {
            throw new \RuntimeException('Configured cluster database identity does not match the live PostgreSQL metadata database.');
        }
        if ($credentialProvider === null) {
            throw new \RuntimeException('Cluster mode requires an explicit authentication and owner-mapping identity.');
        }

        $tierCapabilities = [];
        foreach ($tiers->all() as $tier) {
            $tierCapabilities[] = [
                'name' => $tier->name,
                'identity' => self::storageIdentity($tier->backend),
                'restore_required' => $tier->restoreRequired,
                'default' => $tier->defaultWriteTier || $tiers->defaultTier()->name === $tier->name,
            ];
        }
        usort(
            $tierCapabilities,
            static fn(array $left, array $right): int => strcmp($left['name'], $right['name']),
        );

        $encryptionIdentity = self::validateCapabilityIdentity(match (true) {
            $encryption === null => 'disabled-v1',
            $encryption instanceof ClusterEncryptionIdentity => $encryption->clusterEncryptionIdentity(),
            default => throw new \RuntimeException('Cluster mode requires an encryption service with an explicit cluster identity capability.'),
        }, 'encryption');

        $trustedProxyCidrs = array_values(array_unique($config->trustedProxyCidrs));
        sort($trustedProxyCidrs, SORT_STRING);

        $categories = [
            'protocol' => ['protocol' => self::PROTOCOL],
            'database' => [
                'engine' => 'postgres',
                'identity' => $databaseIdentity,
                'schema' => $databaseSchema,
                'schema_version' => PostgresSchema::VERSION,
            ],
            'authentication' => [
                'credential_store' => self::validateCapabilityIdentity(
                    $credentialProvider->clusterAuthenticationIdentity(),
                    'authentication',
                ),
                'external_oidc' => $externalAuthenticationIdentity === null
                    ? 'disabled-v1'
                    : self::validateCapabilityIdentity($externalAuthenticationIdentity, 'authentication'),
                'owner_mapping_protocol' => 'credential-row-or-normalized-oidc-v1',
            ],
            'storage' => [
                'default_tier' => $tiers->defaultTier()->name,
                'identity' => self::storageIdentity($tiers->defaultBackend()),
                'capability_protocol' => 'cluster-safe-storage-v1',
            ],
            'tiers' => $tierCapabilities,
            'encryption' => ['identity' => $encryptionIdentity],
            'compatibility' => [
                'region' => $config->region,
                'per_client_rate_limit' => $config->perClientRateLimit,
                'trusted_proxy_cidrs' => $trustedProxyCidrs,
                'strict_bucket_naming' => $config->strictBucketNaming,
                'base_domain' => $config->baseDomain,
                'website_host_pattern' => $config->websiteHostPattern,
                'minimum_multipart_part' => $config->enforceMinPartSize,
                'request_body_size_limit' => $config->requestBodySizeLimit,
                'max_encrypted_object_size' => $config->maxEncryptedObjectSize,
                'max_select_object_size' => $config->maxSelectObjectSize,
                'notification_https' => $config->notificationRequireHttps,
                'notification_worker_enabled' => $config->notificationWorkerEnabled,
                'multipart_max_age_seconds' => $config->multipartMaxAgeSeconds,
                'lifecycle_lock_ttl_seconds' => $config->lifecycleLockTtlSeconds,
                'quota' => [
                    'max_buckets_per_owner' => $config->quota->maxBucketsPerOwner,
                    'max_objects_per_bucket' => $config->quota->maxObjectsPerBucket,
                    'max_bytes_per_bucket' => $config->quota->maxBytesPerBucket,
                    'max_bytes_per_owner' => $config->quota->maxBytesPerOwner,
                    'max_multipart_uploads_per_bucket' => $config->quota->maxMultipartUploadsPerBucket,
                    'max_multipart_uploads_per_owner' => $config->quota->maxMultipartUploadsPerOwner,
                    'max_multipart_bytes_per_bucket' => $config->quota->maxMultipartBytesPerBucket,
                    'max_multipart_bytes_per_owner' => $config->quota->maxMultipartBytesPerOwner,
                ],
            ],
            'roles' => ['roles' => self::ROLES],
        ];

        $digests = [];
        foreach ($categories as $category => $value) {
            $digests[$category] = hash('sha256', self::canonicalJson($value));
        }

        return new self(
            $config->clusterId,
            $config->clusterNodeId,
            $config->clusterIncarnationId,
            $databaseIdentity,
            $digests,
        );
    }

    public function digest(string $category): string
    {
        return $this->digests[$category] ?? throw new \InvalidArgumentException('Unknown fingerprint category.');
    }

    /** @return array<string, string> */
    public function digests(): array
    {
        return $this->digests;
    }

    private static function storageIdentity(StorageBackend $backend): string
    {
        while ($backend instanceof \OpsFour\S3Server\Observability\ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if (! $backend instanceof ClusterSafeStorageBackend) {
            throw new \RuntimeException('Cluster mode requires every storage tier to expose an explicit qualified cluster capability.');
        }

        return self::validateCapabilityIdentity($backend->clusterStorageIdentity(), 'storage');
    }

    public static function validateCapabilityIdentity(string $identity, string $capability): string
    {
        $bytes = strlen($identity);
        if ($bytes < 1 || $bytes > self::MAX_CAPABILITY_IDENTITY_BYTES) {
            throw new \RuntimeException("Cluster {$capability} capability identity must contain 1-1024 bytes.");
        }

        return $identity;
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }

        return $value;
    }
}
