<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use OpsFour\S3Server\Handler\Bucket\CreateBucketHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketCorsHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketEncryptionHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketLifecycleHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketPolicyHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketTaggingHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketWebsiteHandler;
use OpsFour\S3Server\Handler\Bucket\DeletePublicAccessBlockHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketAclHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketCorsHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketEncryptionHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketLifecycleHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketLocationHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketLoggingHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketNotificationHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketPolicyHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketPolicyStatusHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketTaggingHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketVersioningHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketWebsiteHandler;
use OpsFour\S3Server\Handler\Bucket\GetObjectLockConfigHandler;
use OpsFour\S3Server\Handler\Bucket\GetPublicAccessBlockHandler;
use OpsFour\S3Server\Handler\Bucket\HeadBucketHandler;
use OpsFour\S3Server\Handler\Bucket\ListBucketsHandler;
use OpsFour\S3Server\Handler\Bucket\ListObjectsHandler;
use OpsFour\S3Server\Handler\Bucket\ListObjectsV2Handler;
use OpsFour\S3Server\Handler\Bucket\ListObjectVersionsHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketAclHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketCorsHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketEncryptionHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketLifecycleHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketLoggingHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketNotificationHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketPolicyHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketTaggingHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketVersioningHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketWebsiteHandler;
use OpsFour\S3Server\Handler\Bucket\PutObjectLockConfigHandler;
use OpsFour\S3Server\Handler\Bucket\PutPublicAccessBlockHandler;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\ListMultipartUploadsHandler;
use OpsFour\S3Server\Handler\Multipart\ListPartsHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectTaggingHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAclHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectLegalHoldHandler;
use OpsFour\S3Server\Handler\Object\GetObjectRetentionHandler;
use OpsFour\S3Server\Handler\Object\GetObjectTaggingHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectAclHandler;
use OpsFour\S3Server\Handler\Object\PostObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectLegalHoldHandler;
use OpsFour\S3Server\Handler\Object\PutObjectRetentionHandler;
use OpsFour\S3Server\Handler\Object\PutObjectTaggingHandler;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Routing\HandlerRegistry;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Registers all handler classes with the HandlerRegistry.
 *
 * This class wires up the handler instances with their dependencies
 * (MetadataStore, StorageBackend, S3ServerConfig) and maps each to
 * its corresponding S3Operation enum case.
 *
 * Usage:
 *     $registry = $server->getHandlerRegistry();
 *     HandlerRegistrar::registerAll($registry, $metadata, $storage, $config);
 *     $server->start();
 */
final class HandlerRegistrar
{
    /**
     * Register all handlers with the given registry.
     *
     * @param  HandlerRegistry  $registry  The handler registry to populate.
     * @param  MetadataStore  $metadata  The metadata store implementation.
     * @param  StorageBackend  $storage  The storage backend implementation.
     * @param  S3ServerConfig  $config  The server configuration.
     * @param  EncryptionServiceInterface|null  $encryption  Optional encryption service for SSE-S3/SSE-C.
     * @param  NotificationDispatcher|null  $notifications  Optional notification dispatcher.
     * @param  WorkerPool|null  $selectWorkerPool  Optional worker pool for S3 Select offloading.
     * @param  MetricsCollector|null  $metrics  Optional metrics collector for worker task instrumentation.
     */
    public static function registerAll(
        HandlerRegistry $registry,
        MetadataStore $metadata,
        StorageBackend $storage,
        S3ServerConfig $config,
        ?EncryptionServiceInterface $encryption = null,
        ?NotificationDispatcher $notifications = null,
        ?WorkerPool $selectWorkerPool = null,
        ?\OpsFour\S3Server\Contracts\CredentialProvider $credentialProvider = null,
        ?MetricsCollector $metrics = null,
        ?StorageTierRegistry $storageTiers = null,
    ): void {
        $quotas = QuotaManager::fromGlobalConfig($metadata, $config->quota);
        $storageTiers ??= StorageTierRegistry::single($storage);

        // Bucket operations.
        $registry->register(
            S3Operation::CreateBucket,
            new CreateBucketHandler($metadata, $storage, $config, $quotas),
        );

        $registry->register(
            S3Operation::DeleteBucket,
            new DeleteBucketHandler($metadata, $storage),
        );

        $registry->register(
            S3Operation::ListBuckets,
            new ListBucketsHandler($metadata),
        );

        $registry->register(
            S3Operation::HeadBucket,
            new HeadBucketHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketLocation,
            new GetBucketLocationHandler($metadata),
        );

        // Object operations.
        $registry->register(
            S3Operation::PutObject,
            new PutObjectHandler($metadata, $storage, $encryption, $notifications, $config->maxEncryptedObjectSize, $quotas),
        );

        $registry->register(
            S3Operation::GetObject,
            new GetObjectHandler($metadata, $storage, $encryption, $config->maxEncryptedObjectSize, $storageTiers),
        );

        $registry->register(
            S3Operation::HeadObject,
            new HeadObjectHandler($metadata),
        );

        $registry->register(
            S3Operation::RestoreObject,
            new RestoreObjectHandler($metadata, $storageTiers),
        );

        $registry->register(
            S3Operation::DeleteObject,
            new DeleteObjectHandler($metadata, $storage, $notifications),
        );

        // Phase 3: Listing, batch, and copy operations.
        $registry->register(
            S3Operation::ListObjectsV2,
            new ListObjectsV2Handler($metadata),
        );

        $registry->register(
            S3Operation::ListObjects,
            new ListObjectsHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteObjects,
            new DeleteObjectsHandler($metadata, $storage, $notifications),
        );

        $registry->register(
            S3Operation::PostObject,
            new PostObjectHandler($metadata, $storage, $credentialProvider),
        );

        $registry->register(
            S3Operation::CopyObject,
            new CopyObjectHandler($metadata, $storage, $encryption, $notifications, $config->maxEncryptedObjectSize, $quotas),
        );

        // Phase 4: Multipart upload operations.
        $registry->register(
            S3Operation::CreateMultipartUpload,
            new CreateMultipartUploadHandler($metadata, $encryption),
        );

        $registry->register(
            S3Operation::UploadPart,
            new UploadPartHandler($metadata, $storage),
        );

        $registry->register(
            S3Operation::UploadPartCopy,
            new UploadPartCopyHandler($metadata, $storage, $encryption, $config->maxEncryptedObjectSize),
        );

        $registry->register(
            S3Operation::CompleteMultipartUpload,
            new CompleteMultipartUploadHandler($metadata, $storage, $encryption, $notifications, $config->enforceMinPartSize, $config->maxEncryptedObjectSize, $quotas),
        );

        $registry->register(
            S3Operation::AbortMultipartUpload,
            new AbortMultipartUploadHandler($metadata, $storage, $notifications),
        );

        $registry->register(
            S3Operation::ListParts,
            new ListPartsHandler($metadata),
        );

        $registry->register(
            S3Operation::ListMultipartUploads,
            new ListMultipartUploadsHandler($metadata),
        );

        // Phase 5: Versioning + Object Lock operations.
        $registry->register(
            S3Operation::GetBucketVersioning,
            new GetBucketVersioningHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketVersioning,
            new PutBucketVersioningHandler($metadata),
        );

        $registry->register(
            S3Operation::ListObjectVersions,
            new ListObjectVersionsHandler($metadata),
        );

        $registry->register(
            S3Operation::GetObjectLockConfig,
            new GetObjectLockConfigHandler($metadata),
        );

        $registry->register(
            S3Operation::PutObjectLockConfig,
            new PutObjectLockConfigHandler($metadata),
        );

        $registry->register(
            S3Operation::GetObjectRetention,
            new GetObjectRetentionHandler($metadata),
        );

        $registry->register(
            S3Operation::PutObjectRetention,
            new PutObjectRetentionHandler($metadata),
        );

        $registry->register(
            S3Operation::GetObjectLegalHold,
            new GetObjectLegalHoldHandler($metadata),
        );

        $registry->register(
            S3Operation::PutObjectLegalHold,
            new PutObjectLegalHoldHandler($metadata),
        );

        // Phase 6: ACLs, Policies, Tagging, CORS operations.
        $registry->register(
            S3Operation::GetBucketAcl,
            new GetBucketAclHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketAcl,
            new PutBucketAclHandler($metadata),
        );

        $registry->register(
            S3Operation::GetObjectAcl,
            new GetObjectAclHandler($metadata),
        );

        $registry->register(
            S3Operation::PutObjectAcl,
            new PutObjectAclHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketPolicy,
            new GetBucketPolicyHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketPolicy,
            new PutBucketPolicyHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteBucketPolicy,
            new DeleteBucketPolicyHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketTagging,
            new GetBucketTaggingHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketTagging,
            new PutBucketTaggingHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteBucketTagging,
            new DeleteBucketTaggingHandler($metadata),
        );

        $registry->register(
            S3Operation::GetObjectTagging,
            new GetObjectTaggingHandler($metadata),
        );

        $registry->register(
            S3Operation::PutObjectTagging,
            new PutObjectTaggingHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteObjectTagging,
            new DeleteObjectTaggingHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketCors,
            new GetBucketCorsHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketCors,
            new PutBucketCorsHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteBucketCors,
            new DeleteBucketCorsHandler($metadata),
        );

        // Phase 7: Encryption, Lifecycle, Notification operations.
        $registry->register(
            S3Operation::GetBucketEncryption,
            new GetBucketEncryptionHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketEncryption,
            new PutBucketEncryptionHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteBucketEncryption,
            new DeleteBucketEncryptionHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketLifecycle,
            new GetBucketLifecycleHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketLifecycle,
            new PutBucketLifecycleHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteBucketLifecycle,
            new DeleteBucketLifecycleHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketNotification,
            new GetBucketNotificationHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketNotification,
            new PutBucketNotificationHandler($metadata),
        );

        // Phase 8: Website Hosting operations.
        $registry->register(
            S3Operation::GetBucketWebsite,
            new GetBucketWebsiteHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketWebsite,
            new PutBucketWebsiteHandler($metadata),
        );

        $registry->register(
            S3Operation::DeleteBucketWebsite,
            new DeleteBucketWebsiteHandler($metadata),
        );

        // Production-ready: Public Access Block, Policy Status, Logging, SelectObjectContent, GetObjectAttributes.
        $registry->register(
            S3Operation::GetPublicAccessBlock,
            new GetPublicAccessBlockHandler($metadata),
        );

        $registry->register(
            S3Operation::PutPublicAccessBlock,
            new PutPublicAccessBlockHandler($metadata),
        );

        $registry->register(
            S3Operation::DeletePublicAccessBlock,
            new DeletePublicAccessBlockHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketPolicyStatus,
            new GetBucketPolicyStatusHandler($metadata),
        );

        $registry->register(
            S3Operation::GetBucketLogging,
            new GetBucketLoggingHandler($metadata),
        );

        $registry->register(
            S3Operation::PutBucketLogging,
            new PutBucketLoggingHandler($metadata),
        );

        $registry->register(
            S3Operation::SelectObjectContent,
            new SelectObjectContentHandler($metadata, $storage, $config->maxSelectObjectSize, $selectWorkerPool, $metrics),
        );

        $registry->register(
            S3Operation::GetObjectAttributes,
            new GetObjectAttributesHandler($metadata),
        );
    }
}
