<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

/**
 * Backed string enum representing every S3 API operation.
 *
 * Case names use PascalCase matching the official AWS operation names.
 * The string value is identical to the case name for serialization clarity.
 */
enum S3Operation: string
{
    // ── Service-level ────────────────────────────────────────────────
    case ListBuckets = 'ListBuckets';

    // ── Bucket operations ────────────────────────────────────────────
    case CreateBucket = 'CreateBucket';
    case DeleteBucket = 'DeleteBucket';
    case HeadBucket = 'HeadBucket';
    case ListObjects = 'ListObjects';
    case ListObjectsV2 = 'ListObjectsV2';
    case ListObjectVersions = 'ListObjectVersions';
    case GetBucketLocation = 'GetBucketLocation';
    case GetBucketVersioning = 'GetBucketVersioning';
    case PutBucketVersioning = 'PutBucketVersioning';
    case GetBucketAcl = 'GetBucketAcl';
    case PutBucketAcl = 'PutBucketAcl';
    case GetBucketPolicy = 'GetBucketPolicy';
    case PutBucketPolicy = 'PutBucketPolicy';
    case DeleteBucketPolicy = 'DeleteBucketPolicy';
    case GetBucketCors = 'GetBucketCors';
    case PutBucketCors = 'PutBucketCors';
    case DeleteBucketCors = 'DeleteBucketCors';
    case GetBucketTagging = 'GetBucketTagging';
    case PutBucketTagging = 'PutBucketTagging';
    case DeleteBucketTagging = 'DeleteBucketTagging';
    case GetBucketLifecycle = 'GetBucketLifecycle';
    case PutBucketLifecycle = 'PutBucketLifecycle';
    case DeleteBucketLifecycle = 'DeleteBucketLifecycle';
    case GetBucketNotification = 'GetBucketNotification';
    case PutBucketNotification = 'PutBucketNotification';
    case GetBucketEncryption = 'GetBucketEncryption';
    case PutBucketEncryption = 'PutBucketEncryption';
    case DeleteBucketEncryption = 'DeleteBucketEncryption';
    case GetObjectLockConfig = 'GetObjectLockConfig';
    case PutObjectLockConfig = 'PutObjectLockConfig';
    case ListMultipartUploads = 'ListMultipartUploads';
    case DeleteObjects = 'DeleteObjects';
    case PostObject = 'PostObject';
    case GetBucketWebsite = 'GetBucketWebsite';
    case PutBucketWebsite = 'PutBucketWebsite';
    case DeleteBucketWebsite = 'DeleteBucketWebsite';
    case GetPublicAccessBlock = 'GetPublicAccessBlock';
    case PutPublicAccessBlock = 'PutPublicAccessBlock';
    case DeletePublicAccessBlock = 'DeletePublicAccessBlock';
    case GetBucketPolicyStatus = 'GetBucketPolicyStatus';
    case GetBucketLogging = 'GetBucketLogging';
    case PutBucketLogging = 'PutBucketLogging';

    // ── Object operations ────────────────────────────────────────────
    case PutObject = 'PutObject';
    case GetObject = 'GetObject';
    case HeadObject = 'HeadObject';
    case DeleteObject = 'DeleteObject';
    case CopyObject = 'CopyObject';
    case GetObjectAcl = 'GetObjectAcl';
    case PutObjectAcl = 'PutObjectAcl';
    case GetObjectTagging = 'GetObjectTagging';
    case PutObjectTagging = 'PutObjectTagging';
    case DeleteObjectTagging = 'DeleteObjectTagging';
    case GetObjectRetention = 'GetObjectRetention';
    case PutObjectRetention = 'PutObjectRetention';
    case GetObjectLegalHold = 'GetObjectLegalHold';
    case PutObjectLegalHold = 'PutObjectLegalHold';
    case SelectObjectContent = 'SelectObjectContent';
    case GetObjectAttributes = 'GetObjectAttributes';
    case RestoreObject = 'RestoreObject';

    // ── Multipart operations ─────────────────────────────────────────
    case CreateMultipartUpload = 'CreateMultipartUpload';
    case UploadPart = 'UploadPart';
    case UploadPartCopy = 'UploadPartCopy';
    case CompleteMultipartUpload = 'CompleteMultipartUpload';
    case AbortMultipartUpload = 'AbortMultipartUpload';
    case ListParts = 'ListParts';
}
