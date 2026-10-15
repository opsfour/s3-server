<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;

final class MultiAccountAuthorizationTest extends S3FunctionalTestCase
{
    private static string $credentialsPath = '';

    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    private static S3Client $writer;

    private static S3Client $stranger;

    public static function setUpBeforeClass(): void
    {
        self::$credentialsPath = tempnam(sys_get_temp_dir(), 's3-multi-account-') ?: '';
        file_put_contents(self::$credentialsPath, json_encode([
            ['accessKeyId' => self::$accessKey, 'secretAccessKey' => self::$secretKey, 'ownerId' => 'bucket-owner'],
            ['accessKeyId' => 'writerAccessKey', 'secretAccessKey' => 'writerSecretKey', 'ownerId' => 'object-writer'],
            ['accessKeyId' => 'strangerAccessKey', 'secretAccessKey' => 'strangerSecretKey', 'ownerId' => 'stranger'],
        ], JSON_THROW_ON_ERROR));

        foreach (['S3_CREDENTIALS_DRIVER' => 'file', 'S3_CREDENTIALS_PATH' => self::$credentialsPath] as $key => $value) {
            self::$previousEnv[$key] = getenv($key);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        parent::setUpBeforeClass();
        self::$writer = self::client('writerAccessKey', 'writerSecretKey');
        self::$stranger = self::client('strangerAccessKey', 'strangerSecretKey');
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        foreach (['bucket-owner', 'object-writer', 'stranger'] as $ownerId) {
            $metadata->putAccountPolicy($ownerId, self::allowAllIdentityPolicy());
        }
    }

    public static function tearDownAfterClass(): void
    {
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        foreach (['bucket-owner', 'object-writer', 'stranger'] as $ownerId) {
            $metadata->deleteAccountPolicy($ownerId);
        }
        parent::tearDownAfterClass();
        foreach (self::$previousEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        self::$previousEnv = [];
        @unlink(self::$credentialsPath);
    }

    public function test_foreign_account_cannot_read_bucket_policy_even_with_explicit_grants(): void
    {
        $bucket = 'policy-owner-only-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => 's3:GetBucketPolicy',
            'Resource' => "arn:aws:s3:::{$bucket}",
        ]]);

        self::assertNotEmpty(self::$s3->getBucketPolicy(['Bucket' => $bucket])['Policy']);
        self::assertAccessDenied(fn() => self::$writer->getBucketPolicy(['Bucket' => $bucket]));
        try {
            self::$stranger->getBucketPolicy(['Bucket' => $bucket]);
            self::fail('A foreign account read the bucket policy.');
        } catch (S3Exception $error) {
            self::assertSame(405, $error->getStatusCode());
            self::assertSame('MethodNotAllowed', $error->getAwsErrorCode());
        }
    }

    public function test_foreign_object_owner_and_acl_grants_over_signed_http(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'foreign.txt', 'Body' => 'writer data']);

        $this->assertSame('writer data', (string) self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'foreign.txt'])['Body']);
        self::assertAccessDenied(fn() => self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'foreign.txt']));
        self::assertAccessDenied(fn() => self::$s3->headObject(['Bucket' => $bucket, 'Key' => 'foreign.txt']));
        self::assertAccessDenied(fn() => self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'foreign.txt']));
        self::assertAccessDenied(fn() => self::$s3->putObjectAcl(['Bucket' => $bucket, 'Key' => 'foreign.txt', 'ACL' => 'public-read']));

        self::$writer->putObjectAcl(['Bucket' => $bucket, 'Key' => 'foreign.txt', 'ACL' => 'bucket-owner-read']);
        $this->assertSame('writer data', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'foreign.txt'])['Body']);
        self::assertAccessDenied(fn() => self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'foreign.txt']));
        self::assertAccessDenied(fn() => self::$stranger->getObject(['Bucket' => $bucket, 'Key' => 'foreign.txt']));

        self::$writer->putObjectAcl([
            'Bucket' => $bucket,
            'Key' => 'foreign.txt',
            'GrantReadACP' => 'id="bucket-owner"',
        ]);
        $this->assertSame('object-writer', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'foreign.txt'])['Owner']['ID']);
    }

    public function test_missing_current_object_requires_effective_list_bucket_permission(): void
    {
        $bucket = 'missing-visibility-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'present.txt', 'Body' => 'present']);
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        $objectActions = ['s3:GetObject', 's3:GetObjectAttributes'];
        $objectStatement = [
            'Effect' => 'Allow',
            'Action' => $objectActions,
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ];

        try {
            $this->putPolicy($bucket, [$objectStatement + ['Principal' => ['AWS' => 'stranger']]]);
            $metadata->putAccountPolicy('stranger', json_encode([
                'Statement' => [$objectStatement],
            ], JSON_THROW_ON_ERROR));

            self::assertSame('present', (string) self::$stranger->getObject([
                'Bucket' => $bucket, 'Key' => 'present.txt',
            ])['Body']);
            self::$stranger->getObjectAttributes([
                'Bucket' => $bucket, 'Key' => 'present.txt', 'ObjectAttributes' => ['ETag'],
            ]);
            self::assertAccessDenied(fn() => self::$stranger->listObjectsV2(['Bucket' => $bucket]));
            $this->assertMissingObjectStatus($bucket, 403);

            $listStatement = [
                'Effect' => 'Allow', 'Action' => 's3:ListBucket',
                'Resource' => "arn:aws:s3:::{$bucket}",
            ];
            // Identity permission alone cannot grant a foreign account access
            // to the owner's bucket.
            $metadata->putAccountPolicy('stranger', json_encode([
                'Statement' => [$objectStatement, $listStatement],
            ], JSON_THROW_ON_ERROR));
            $this->assertMissingObjectStatus($bucket, 403);

            // A bucket READ ACL is another valid bucket-side consent path.
            self::$s3->putBucketAcl(['Bucket' => $bucket, 'GrantRead' => 'id="stranger"']);
            self::$stranger->listObjectsV2(['Bucket' => $bucket]);
            $this->assertMissingObjectStatus($bucket, 404);
            self::$s3->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'private']);

            $this->putPolicy($bucket, [
                $objectStatement + ['Principal' => ['AWS' => 'stranger']],
                $listStatement + ['Principal' => ['AWS' => 'stranger']],
            ]);
            self::$stranger->listObjectsV2(['Bucket' => $bucket]);
            $this->assertMissingObjectStatus($bucket, 404);

            $metadata->putAccountPolicy('bucket-owner', json_encode([
                'Statement' => [
                    ['Effect' => 'Allow', 'Action' => 's3:*', 'Resource' => '*'],
                    ['Effect' => 'Deny', 'Action' => 's3:ListBucket', 'Resource' => "arn:aws:s3:::{$bucket}"],
                ],
            ], JSON_THROW_ON_ERROR));
            self::assertSame('present', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'present.txt',
            ])['Body']);
            $this->assertMissingObjectStatusFor(self::$s3, $bucket, 403);
        } finally {
            $metadata->putAccountPolicy('stranger', self::allowAllIdentityPolicy());
            $metadata->putAccountPolicy('bucket-owner', self::allowAllIdentityPolicy());
        }
    }

    public function test_missing_copy_source_requires_effective_source_list_bucket_permission(): void
    {
        $sourceBucket = 'copy-source-visibility-' . bin2hex(random_bytes(5));
        $destinationBucket = 'copy-destination-visibility-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $sourceBucket]);
        self::$stranger->createBucket(['Bucket' => $destinationBucket]);
        $uploadId = null;

        try {
            self::$s3->putObject(['Bucket' => $sourceBucket, 'Key' => 'private.txt', 'Body' => 'private']);
            $uploadId = (string) self::$stranger->createMultipartUpload([
                'Bucket' => $destinationBucket, 'Key' => 'part.txt',
            ])['UploadId'];

            $copy = static fn(string $key) => self::$stranger->copyObject([
                'Bucket' => $destinationBucket,
                'Key' => 'copy.txt',
                'CopySource' => "{$sourceBucket}/{$key}",
                'ExpectedBucketOwner' => 'stranger',
                'ExpectedSourceBucketOwner' => 'bucket-owner',
            ]);
            $partCopy = static fn(string $key) => self::$stranger->uploadPartCopy([
                'Bucket' => $destinationBucket,
                'Key' => 'part.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'CopySource' => "{$sourceBucket}/{$key}",
                'ExpectedBucketOwner' => 'stranger',
                'ExpectedSourceBucketOwner' => 'bucket-owner',
            ]);

            self::assertAccessDenied(fn() => $copy('private.txt'));
            self::assertAccessDenied(fn() => $partCopy('private.txt'));
            self::assertAccessDenied(fn() => $copy('missing.txt'));
            self::assertAccessDenied(fn() => $partCopy('missing.txt'));

            $this->putPolicy($sourceBucket, [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'stranger'],
                'Action' => 's3:ListBucket',
                'Resource' => "arn:aws:s3:::{$sourceBucket}",
            ]]);
            self::$stranger->listObjectsV2(['Bucket' => $sourceBucket]);
            foreach ([$copy, $partCopy] as $operation) {
                try {
                    $operation('missing.txt');
                    self::fail('Expected NoSuchKey for a missing copy source.');
                } catch (S3Exception $exception) {
                    self::assertSame(404, $exception->getStatusCode());
                    self::assertSame('NoSuchKey', $exception->getAwsErrorCode());
                }
            }
        } finally {
            if ($uploadId !== null) {
                self::$stranger->abortMultipartUpload([
                    'Bucket' => $destinationBucket, 'Key' => 'part.txt', 'UploadId' => $uploadId,
                ]);
            }
            self::$s3->deleteBucketPolicy(['Bucket' => $sourceBucket]);
            self::$s3->deleteObject(['Bucket' => $sourceBucket, 'Key' => 'private.txt']);
            self::$s3->deleteBucket(['Bucket' => $sourceBucket]);
            self::$stranger->deleteBucket(['Bucket' => $destinationBucket]);
        }
    }

    private function assertMissingObjectStatus(string $bucket, int $status): void
    {
        $this->assertMissingObjectStatusFor(self::$stranger, $bucket, $status);
    }

    private function assertMissingObjectStatusFor(S3Client $client, string $bucket, int $status): void
    {
        foreach ([
            fn() => $client->getObject(['Bucket' => $bucket, 'Key' => 'missing.txt']),
            fn() => $client->headObject(['Bucket' => $bucket, 'Key' => 'missing.txt']),
            fn() => $client->getObjectAttributes([
                'Bucket' => $bucket, 'Key' => 'missing.txt', 'ObjectAttributes' => ['ETag'],
            ]),
        ] as $operation) {
            try {
                $operation();
                self::fail("Expected HTTP {$status} for a missing object.");
            } catch (S3Exception $exception) {
                self::assertSame($status, $exception->getStatusCode());
            }
        }
    }

    public function test_bucket_owner_enforced_applies_to_existing_and_new_objects(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $this->assertSame('ObjectWriter', self::$s3->getBucketOwnershipControls(['Bucket' => $bucket])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
        self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'old.txt', 'Body' => 'old writer object']);
        self::$writer->putObjectAcl(['Bucket' => $bucket, 'Key' => 'old.txt', 'ACL' => 'public-read']);
        $this->assertSame('old writer object', (string) self::$stranger->getObject(['Bucket' => $bucket, 'Key' => 'old.txt'])['Body']);

        try {
            self::$s3->putBucketOwnershipControls([
                'Bucket' => $bucket,
                'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
            ]);
            self::fail('Expected a nonprivate bucket ACL to block BucketOwnerEnforced.');
        } catch (S3Exception $exception) {
            self::assertSame('InvalidBucketAclWithObjectOwnership', $exception->getAwsErrorCode());
        }

        self::$s3->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'private']);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => ['s3:PutObject', 's3:PutObjectAcl'],
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ], [
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => 's3:GetObject',
            'Resource' => "arn:aws:s3:::{$bucket}/new.txt",
        ]]);
        self::$s3->putBucketOwnershipControls([
            'Bucket' => $bucket,
            'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
        ]);
        $this->assertSame('BucketOwnerEnforced', self::$s3->getBucketOwnershipControls(['Bucket' => $bucket])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
        self::assertAccessDenied(fn() => self::$writer->getBucketOwnershipControls(['Bucket' => $bucket]));
        $this->assertSame('old writer object', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'old.txt'])['Body']);
        self::assertAccessDenied(fn() => self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'old.txt']));
        self::assertAccessDenied(fn() => self::$stranger->getObject(['Bucket' => $bucket, 'Key' => 'old.txt']));
        $this->assertSame('bucket-owner', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'old.txt'])['Owner']['ID']);

        self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'new.txt', 'Body' => 'new writer object', 'ACL' => 'bucket-owner-full-control']);
        $this->assertSame('bucket-owner', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'new.txt'])['Owner']['ID']);
        $this->assertSame('new writer object', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'new.txt'])['Body']);
        self::$writer->copyObject(['Bucket' => $bucket, 'Key' => 'copy.txt', 'CopySource' => "{$bucket}/new.txt"]);
        $this->assertSame('bucket-owner', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'copy.txt'])['Owner']['ID']);
        $upload = self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'parts.txt']);
        $part = self::$writer->uploadPart([
            'Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $upload['UploadId'],
            'PartNumber' => 1, 'Body' => 'multipart data',
        ]);
        self::$writer->completeMultipartUpload([
            'Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $upload['UploadId'],
            'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
        ]);
        $this->assertSame('bucket-owner', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'parts.txt'])['Owner']['ID']);
        foreach ([
            fn() => self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'bad.txt', 'Body' => 'bad', 'ACL' => 'public-read']),
            fn() => self::$s3->putObjectAcl(['Bucket' => $bucket, 'Key' => 'new.txt', 'ACL' => 'private']),
            fn() => self::$s3->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'private']),
            fn() => self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'bad-parts.txt', 'ACL' => 'public-read']),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected ACLs to be disabled.');
            } catch (S3Exception $exception) {
                self::assertSame('AccessControlListNotSupported', $exception->getAwsErrorCode());
            }
        }

        self::$s3->deleteBucketOwnershipControls(['Bucket' => $bucket]);
        $this->assertSame('object-writer', self::$writer->getObjectAcl(['Bucket' => $bucket, 'Key' => 'old.txt'])['Owner']['ID']);
        $this->assertSame('bucket-owner', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'new.txt'])['Owner']['ID']);
        try {
            self::$s3->getBucketOwnershipControls(['Bucket' => $bucket]);
            self::fail('Expected deleted controls to be absent.');
        } catch (S3Exception $exception) {
            self::assertSame('OwnershipControlsNotFoundError', $exception->getAwsErrorCode());
        }
    }

    public function test_create_bucket_can_explicitly_start_with_bucket_owner_enforced(): void
    {
        $invalidBucket = 'enforced-invalid-' . bin2hex(random_bytes(5));
        try {
            self::$s3->createBucket([
                'Bucket' => $invalidBucket,
                'ObjectOwnership' => 'BucketOwnerEnforced',
                'ACL' => 'public-read',
            ]);
            self::fail('Expected an incompatible create-time bucket ACL to fail.');
        } catch (S3Exception $exception) {
            self::assertSame('InvalidBucketAclWithObjectOwnership', $exception->getAwsErrorCode());
        }
        $bucket = 'enforced-create-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectOwnership' => 'BucketOwnerEnforced']);
        $this->assertSame('BucketOwnerEnforced', self::$s3->getBucketOwnershipControls(['Bucket' => $bucket])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'owner.txt', 'Body' => 'body']);
        $this->assertSame('bucket-owner', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'owner.txt'])['Owner']['ID']);
    }

    public function test_bucket_policy_can_delegate_ownership_control_read(): void
    {
        $bucket = 'ownership-read-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::assertAccessDenied(fn() => self::$writer->getBucketOwnershipControls(['Bucket' => $bucket]));
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => 's3:GetBucketOwnershipControls',
            'Resource' => "arn:aws:s3:::{$bucket}",
        ]]);
        $this->assertSame('ObjectWriter', self::$writer->getBucketOwnershipControls(['Bucket' => $bucket])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
        self::assertAccessDenied(fn() => self::$writer->putBucketOwnershipControls([
            'Bucket' => $bucket,
            'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
        ]));
    }

    public function test_cross_account_policy_requires_identity_allow_when_an_identity_policy_is_configured(): void
    {
        $bucket = 'dual-consent-' . bin2hex(random_bytes(5));
        $copyBucket = 'dual-consent-copy-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$stranger->createBucket(['Bucket' => $copyBucket]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'owner.txt', 'Body' => 'owner data']);
        self::$s3->putBucketAcl(['Bucket' => $bucket, 'GrantRead' => 'id="stranger"']);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => ['s3:GetObject', 's3:PutObject', 's3:PutObjectAcl', 's3:DeleteObject'],
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ]]);
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();

        try {
            $metadata->deleteAccountPolicy('stranger');
            self::assertAccessDenied(fn() => self::$stranger->getObject(['Bucket' => $bucket, 'Key' => 'owner.txt']));
            self::assertAccessDenied(fn() => self::$stranger->listObjectsV2(['Bucket' => $bucket]));
            self::assertAccessDenied(fn() => self::$stranger->copyObject([
                'Bucket' => $copyBucket, 'Key' => 'copied.txt', 'CopySource' => "{$bucket}/owner.txt",
            ]));
            $metadata->putAccountPolicy('stranger', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => 's3:ListAllMyBuckets', 'Resource' => '*',
                ]],
            ], JSON_THROW_ON_ERROR));

            self::assertAccessDenied(fn() => self::$stranger->getObject(['Bucket' => $bucket, 'Key' => 'owner.txt']));
            self::assertAccessDenied(fn() => self::$stranger->putObject(['Bucket' => $bucket, 'Key' => 'new.txt', 'Body' => 'new']));
            self::assertAccessDenied(fn() => self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => 'owner.txt']));
            $batch = self::$stranger->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => [['Key' => 'owner.txt']]],
            ]);
            self::assertSame('AccessDenied', $batch['Errors'][0]['Code']);
            self::assertSame('owner data', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'owner.txt'])['Body']);

            $metadata->putAccountPolicy('stranger', json_encode([
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Action' => ['s3:GetObject', 's3:PutObject', 's3:DeleteObject'],
                        'Resource' => "arn:aws:s3:::{$bucket}/*",
                    ],
                    [
                        'Effect' => 'Allow', 'Action' => 's3:ListBucket', 'Resource' => "arn:aws:s3:::{$bucket}",
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
            self::assertSame('owner data', (string) self::$stranger->getObject(['Bucket' => $bucket, 'Key' => 'owner.txt'])['Body']);
            self::assertSame('owner.txt', self::$stranger->listObjectsV2(['Bucket' => $bucket])['Contents'][0]['Key']);
            self::$stranger->copyObject([
                'Bucket' => $copyBucket, 'Key' => 'copied.txt', 'CopySource' => "{$bucket}/owner.txt",
            ]);
            self::assertSame('owner data', (string) self::$stranger->getObject(['Bucket' => $copyBucket, 'Key' => 'copied.txt'])['Body']);
            self::$stranger->putObject(['Bucket' => $bucket, 'Key' => 'new.txt', 'Body' => 'new']);
            self::assertAccessDenied(fn() => self::$stranger->putObject([
                'Bucket' => $bucket, 'Key' => 'acl.txt', 'Body' => 'acl', 'ACL' => 'bucket-owner-full-control',
            ]));
            $batch = self::$stranger->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => [['Key' => 'owner.txt']]],
            ]);
            self::assertSame('owner.txt', $batch['Deleted'][0]['Key']);
            $metadata->putAccountPolicy('stranger', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Action' => ['s3:PutObject', 's3:PutObjectAcl'],
                    'Resource' => "arn:aws:s3:::{$bucket}/*",
                ]],
            ], JSON_THROW_ON_ERROR));
            self::$stranger->putObject([
                'Bucket' => $bucket, 'Key' => 'acl.txt', 'Body' => 'acl', 'ACL' => 'bucket-owner-full-control',
            ]);
            self::assertSame('stranger', self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'acl.txt'])['Owner']['ID']);
        } finally {
            $metadata->putAccountPolicy('stranger', self::allowAllIdentityPolicy());
            self::$stranger->deleteObject(['Bucket' => $copyBucket, 'Key' => 'copied.txt']);
            self::$stranger->deleteBucket(['Bucket' => $copyBucket]);
        }
    }

    public function test_foreign_object_ownership_does_not_bypass_identity_permission(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        try {
            $metadata->deleteAccountPolicy('object-writer');
            self::assertAccessDenied(fn() => self::$writer->putObject([
                'Bucket' => $bucket, 'Key' => 'own.txt', 'Body' => 'writer data',
            ]));
            $metadata->putAccountPolicy('object-writer', self::allowAllIdentityPolicy());
            self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'own.txt', 'Body' => 'writer data']);
            $metadata->deleteAccountPolicy('object-writer');
            self::assertAccessDenied(fn() => self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'own.txt']));
        } finally {
            $metadata->putAccountPolicy('object-writer', self::allowAllIdentityPolicy());
        }
        self::assertSame('writer data', (string) self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'own.txt'])['Body']);
    }

    private static function allowAllIdentityPolicy(): string
    {
        return json_encode([
            'Statement' => [[
                'Effect' => 'Allow', 'Action' => 's3:*', 'Resource' => '*',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    public function test_enforced_objects_count_against_bucket_owner_storage_quota(): void
    {
        $bucket = 'enforced-quota-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectOwnership' => 'BucketOwnerEnforced']);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => 's3:PutObject',
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ]]);
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        $ownerBytesBefore = $metadata->getOwnerStorageStats('bucket-owner')['bytesUsed'];
        $writerBytesBefore = $metadata->getOwnerStorageStats('object-writer')['bytesUsed'];
        $metadata->putAccountQuota('bucket-owner', new QuotaConfig(maxBytesPerOwner: $ownerBytesBefore + 4));
        try {
            self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'first.txt', 'Body' => '1234']);
            $this->assertSame($ownerBytesBefore + 4, $metadata->getOwnerStorageStats('bucket-owner')['bytesUsed']);
            $this->assertSame($writerBytesBefore, $metadata->getOwnerStorageStats('object-writer')['bytesUsed']);
            try {
                self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'second.txt', 'Body' => '5']);
                self::fail('Expected bucket-owner quota to reject a foreign writer.');
            } catch (S3Exception $exception) {
                self::assertSame('QuotaExceeded', $exception->getAwsErrorCode());
            }
            $upload = self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'multipart.txt']);
            $part = self::$writer->uploadPart([
                'Bucket' => $bucket, 'Key' => 'multipart.txt', 'UploadId' => $upload['UploadId'],
                'PartNumber' => 1, 'Body' => 'more',
            ]);
            try {
                self::$writer->completeMultipartUpload([
                    'Bucket' => $bucket, 'Key' => 'multipart.txt', 'UploadId' => $upload['UploadId'],
                    'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
                ]);
                self::fail('Foreign multipart staging must not reduce the bucket owner storage total.');
            } catch (S3Exception $exception) {
                self::assertSame('QuotaExceeded', $exception->getAwsErrorCode());
            }
            self::$writer->abortMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'multipart.txt', 'UploadId' => $upload['UploadId'],
            ]);
            $this->assertSame($ownerBytesBefore + 4, $metadata->getOwnerStorageStats('bucket-owner')['bytesUsed']);
        } finally {
            $metadata->deleteAccountQuota('bucket-owner');
        }
    }

    public function test_multipart_completion_uses_mode_active_at_commit(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $upload = self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'switch.txt']);
        $part = self::$writer->uploadPart([
            'Bucket' => $bucket, 'Key' => 'switch.txt', 'UploadId' => $upload['UploadId'],
            'PartNumber' => 1, 'Body' => 'before mode switch',
        ]);
        self::$s3->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'private']);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => 's3:PutObject',
            'Resource' => "arn:aws:s3:::{$bucket}/switch.txt",
        ]]);
        self::$s3->putBucketOwnershipControls([
            'Bucket' => $bucket,
            'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
        ]);
        self::$writer->completeMultipartUpload([
            'Bucket' => $bucket, 'Key' => 'switch.txt', 'UploadId' => $upload['UploadId'],
            'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
        ]);
        $this->assertSame('bucket-owner', $this->objectOwner($bucket, 'switch.txt'));
        self::$s3->deleteBucketOwnershipControls(['Bucket' => $bucket]);
        $this->assertSame('bucket-owner', $this->objectOwner($bucket, 'switch.txt'));
    }

    public function test_copy_delete_and_multipart_require_the_right_account(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'source.txt', 'Body' => 'copy source']);

        self::assertAccessDenied(fn() => self::$s3->copyObject([
            'Bucket' => $bucket, 'Key' => 'owner-copy.txt', 'CopySource' => "{$bucket}/source.txt",
        ]));
        self::$writer->copyObject([
            'Bucket' => $bucket, 'Key' => 'writer-copy.txt', 'CopySource' => "{$bucket}/source.txt",
        ]);
        $this->assertSame('copy source', (string) self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'writer-copy.txt'])['Body']);

        // Bucket WRITE must not erase another account's existing object.
        self::$s3->putBucketAcl([
            'Bucket' => $bucket,
            'GrantWrite' => 'id="object-writer",id="stranger"',
            'GrantRead' => 'id="stranger"',
        ]);
        self::assertAccessDenied(fn() => self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => 'source.txt']));
        $batch = self::$stranger->deleteObjects([
            'Bucket' => $bucket,
            'Delete' => ['Objects' => [['Key' => 'source.txt']]],
        ]);
        $this->assertSame('AccessDenied', $batch['Errors'][0]['Code']);
        self::$writer->headObject(['Bucket' => $bucket, 'Key' => 'source.txt']);

        $upload = self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'parts.txt']);
        $uploadId = $upload['UploadId'];
        self::$writer->uploadPart(['Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $uploadId, 'PartNumber' => 1, 'Body' => 'part data']);
        $this->assertCount(1, self::$writer->listParts(['Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $uploadId])['Parts']);
        $this->assertCount(1, self::$s3->listParts(['Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $uploadId])['Parts']);
        $this->assertCount(1, self::$stranger->listMultipartUploads(['Bucket' => $bucket])['Uploads']);
        try {
            self::$stranger->abortMultipartUpload(['Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $uploadId]);
            self::fail('Expected the upload to be hidden from an unrelated account.');
        } catch (S3Exception $exception) {
            self::assertSame('NoSuchUpload', $exception->getAwsErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        }
        $this->assertCount(1, self::$writer->listParts(['Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $uploadId])['Parts']);
        self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => 'parts.txt', 'UploadId' => $uploadId]);
        $this->assertSame([], self::$s3->listMultipartUploads(['Bucket' => $bucket])['Uploads'] ?? []);
    }

    public function test_bucket_policy_cannot_grant_foreign_owned_object_and_version_access(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $oldVersion = self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'Body' => 'writer version'])['VersionId'];
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'Body' => 'owner version']);

        self::$s3->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => 'bucket-owner'],
                    'Action' => ['s3:GetObject', 's3:GetObjectVersion', 's3:GetObjectAcl'],
                    'Resource' => "arn:aws:s3:::{$bucket}/*",
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame('owner version', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'versioned.txt'])['Body']);
        self::assertAccessDenied(fn() => self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'VersionId' => $oldVersion]));
        self::assertAccessDenied(fn() => self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'VersionId' => $oldVersion]));
        $this->assertSame('writer version', (string) self::$writer->getObject([
            'Bucket' => $bucket,
            'Key' => 'versioned.txt',
            'VersionId' => $oldVersion,
        ])['Body']);
    }

    public function test_bucket_policy_delete_grant_cannot_transfer_foreign_object_ownership(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        foreach (['owner-single.txt', 'owner-batch.txt'] as $key) {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'bucket owner']);
        }
        foreach (['foreign-single.txt', 'foreign-batch.txt'] as $key) {
            self::$writer->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'object writer']);
        }
        self::$s3->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => 'stranger'],
                    'Action' => 's3:DeleteObject',
                    'Resource' => "arn:aws:s3:::{$bucket}/*",
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => 'owner-single.txt']);
        self::assertAccessDenied(fn() => self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => 'foreign-single.txt']));
        $this->assertSame('object writer', (string) self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'foreign-single.txt'])['Body']);

        $batch = self::$stranger->deleteObjects([
            'Bucket' => $bucket,
            'Delete' => ['Objects' => [['Key' => 'owner-batch.txt'], ['Key' => 'foreign-batch.txt']]],
        ]);
        $this->assertSame('owner-batch.txt', $batch['Deleted'][0]['Key']);
        $this->assertSame('foreign-batch.txt', $batch['Errors'][0]['Key']);
        $this->assertSame('AccessDenied', $batch['Errors'][0]['Code']);
        $this->assertSame('object writer', (string) self::$writer->getObject(['Bucket' => $bucket, 'Key' => 'foreign-batch.txt'])['Body']);

        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $ownerVersion = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'owner-version.txt', 'Body' => 'owner'])['VersionId'];
        $foreignVersion = self::$writer->putObject(['Bucket' => $bucket, 'Key' => 'foreign-version.txt', 'Body' => 'writer'])['VersionId'];
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => ['s3:DeleteObject', 's3:DeleteObjectVersion'],
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ]]);
        self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => 'owner-version.txt', 'VersionId' => $ownerVersion]);
        self::assertAccessDenied(fn() => self::$stranger->deleteObject([
            'Bucket' => $bucket, 'Key' => 'foreign-version.txt', 'VersionId' => $foreignVersion,
        ]));
        $this->assertSame('writer', (string) self::$writer->getObject([
            'Bucket' => $bucket, 'Key' => 'foreign-version.txt', 'VersionId' => $foreignVersion,
        ])['Body']);
        self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'foreign-version.txt', 'VersionId' => $foreignVersion]);
    }

    public function test_suspended_delete_cannot_replace_foreign_null_version_behind_owner_version(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $key = 'foreign-null-single.txt';
        $this->createForeignNullVersionBehindOwnerVersion($bucket, $key);

        self::assertAccessDenied(fn() => self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => $key]));
        self::assertSame('writer null data', (string) self::$writer->getObject([
            'Bucket' => $bucket, 'Key' => $key, 'VersionId' => 'null',
        ])['Body']);
    }

    public function test_suspended_batch_delete_cannot_replace_foreign_null_version_behind_owner_version(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $key = 'foreign-null-batch.txt';
        $this->createForeignNullVersionBehindOwnerVersion($bucket, $key);

        $result = self::$stranger->deleteObjects([
            'Bucket' => $bucket,
            'Delete' => ['Objects' => [['Key' => $key]]],
        ]);
        self::assertSame('AccessDenied', $result['Errors'][0]['Code'] ?? null);
        self::assertSame('writer null data', (string) self::$writer->getObject([
            'Bucket' => $bucket, 'Key' => $key, 'VersionId' => 'null',
        ])['Body']);
    }

    public function test_suspended_delete_without_null_version_checks_current_owner_for_marker_creation(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $ownerVersion = self::$s3->putObject([
            'Bucket' => $bucket, 'Key' => 'owner-numbered.txt', 'Body' => 'owner numbered',
        ])['VersionId'];
        $writerVersion = self::$writer->putObject([
            'Bucket' => $bucket, 'Key' => 'writer-numbered.txt', 'Body' => 'writer numbered',
        ])['VersionId'];
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Suspended'],
        ]);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => 's3:DeleteObject',
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ]]);

        self::$stranger->deleteObject(['Bucket' => $bucket, 'Key' => 'owner-numbered.txt']);
        self::assertAccessDenied(fn() => self::$stranger->deleteObject([
            'Bucket' => $bucket, 'Key' => 'writer-numbered.txt',
        ]));
        self::assertSame('owner numbered', (string) self::$s3->getObject([
            'Bucket' => $bucket, 'Key' => 'owner-numbered.txt', 'VersionId' => $ownerVersion,
        ])['Body']);
        self::assertSame('writer numbered', (string) self::$writer->getObject([
            'Bucket' => $bucket, 'Key' => 'writer-numbered.txt', 'VersionId' => $writerVersion,
        ])['Body']);
    }

    private function createForeignNullVersionBehindOwnerVersion(string $bucket, string $key): void
    {
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Suspended'],
        ]);
        self::$writer->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'writer null data']);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'owner numbered data']);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Suspended'],
        ]);
        $this->putPolicy($bucket, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => 's3:DeleteObject',
            'Resource' => "arn:aws:s3:::{$bucket}/*",
        ]]);
    }

    public function test_bucket_policy_grants_and_denies_multipart_access_by_key(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $upload = self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'granted.txt']);
        $uploadId = $upload['UploadId'];
        self::$writer->uploadPart([
            'Bucket' => $bucket,
            'Key' => 'granted.txt',
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'kept until authorized abort',
        ]);

        $other = self::$writer->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'other.txt']);
        $otherId = $other['UploadId'];
        self::assertNoSuchUpload(fn() => self::$stranger->listParts([
            'Bucket' => $bucket, 'Key' => 'granted.txt', 'UploadId' => $uploadId,
        ]));

        $allow = [
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => ['s3:ListMultipartUploadParts', 's3:AbortMultipartUpload'],
            'Resource' => "arn:aws:s3:::{$bucket}/granted.txt",
        ];
        $this->putPolicy($bucket, [$allow]);
        $this->assertCount(1, self::$stranger->listParts([
            'Bucket' => $bucket, 'Key' => 'granted.txt', 'UploadId' => $uploadId,
        ])['Parts']);
        self::assertNoSuchUpload(fn() => self::$stranger->listParts([
            'Bucket' => $bucket, 'Key' => 'other.txt', 'UploadId' => $otherId,
        ]));

        $this->putPolicy($bucket, [$allow, [
            'Effect' => 'Deny',
            'Principal' => ['AWS' => 'stranger'],
            'Action' => 's3:AbortMultipartUpload',
            'Resource' => "arn:aws:s3:::{$bucket}/granted.txt",
        ]]);
        self::assertAccessDenied(fn() => self::$stranger->abortMultipartUpload([
            'Bucket' => $bucket, 'Key' => 'granted.txt', 'UploadId' => $uploadId,
        ]));
        $this->assertCount(1, self::$writer->listParts([
            'Bucket' => $bucket, 'Key' => 'granted.txt', 'UploadId' => $uploadId,
        ])['Parts']);

        $this->putPolicy($bucket, [$allow]);
        self::$stranger->abortMultipartUpload(['Bucket' => $bucket, 'Key' => 'granted.txt', 'UploadId' => $uploadId]);
        self::assertNoSuchUpload(fn() => self::$writer->listParts([
            'Bucket' => $bucket, 'Key' => 'granted.txt', 'UploadId' => $uploadId,
        ]));
        self::$writer->abortMultipartUpload(['Bucket' => $bucket, 'Key' => 'other.txt', 'UploadId' => $otherId]);
    }

    public function test_bucket_logging_configuration_remains_owner_only_despite_policy_allow(): void
    {
        $source = 'logging-source-' . bin2hex(random_bytes(5));
        $target = 'logging-target-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $source]);
        self::$s3->createBucket(['Bucket' => $target]);
        $this->putPolicy($source, [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => 's3:PutBucketLogging',
            'Resource' => "arn:aws:s3:::{$source}",
        ]]);

        $status = ['LoggingEnabled' => ['TargetBucket' => $target, 'TargetPrefix' => 'access/']];
        self::assertAccessDenied(fn() => self::$writer->putBucketLogging([
            'Bucket' => $source,
            'BucketLoggingStatus' => $status,
        ]));
        self::assertArrayNotHasKey('LoggingEnabled', self::$s3->getBucketLogging(['Bucket' => $source])->toArray());

        self::$s3->putBucketLogging(['Bucket' => $source, 'BucketLoggingStatus' => $status]);
        self::assertSame($target, self::$s3->getBucketLogging(['Bucket' => $source])['LoggingEnabled']['TargetBucket']);
    }

    private function bucketWithWriterGrant(): string
    {
        $bucket = 'multi-auth-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketAcl(['Bucket' => $bucket, 'GrantWrite' => 'id="object-writer"']);

        return $bucket;
    }

    public function test_foreign_multipart_writer_cannot_complete_after_tagging_grant_is_revoked(): void
    {
        $bucket = $this->bucketWithWriterGrant();
        $key = 'foreign-multipart-tags.txt';
        $allow = [
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => ['s3:PutObject', 's3:PutObjectTagging'],
            'Resource' => "arn:aws:s3:::{$bucket}/{$key}",
        ];
        $this->putPolicy($bucket, [$allow]);
        $uploadId = self::$writer->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'Tagging' => 'scope=writer',
        ])['UploadId'];
        $part = self::$writer->uploadPart([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'writer payload',
        ]);
        $this->putPolicy($bucket, [$allow, [
            'Effect' => 'Deny',
            'Principal' => ['AWS' => 'object-writer'],
            'Action' => 's3:PutObjectTagging',
            'Resource' => "arn:aws:s3:::{$bucket}/{$key}",
        ]]);
        try {
            self::assertAccessDenied(fn() => self::$writer->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
            ]));
            self::assertCount(1, self::$writer->listParts([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ])['Parts']);
        } finally {
            self::$writer->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    /** @phpstan-impure */
    private function objectOwner(string $bucket, string $key): string
    {
        return (string) (self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => $key])['Owner']['ID'] ?? '');
    }

    /**
     * @param list<array<string, mixed>> $statements
     */
    private function putPolicy(string $bucket, array $statements): void
    {
        self::$s3->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => $statements,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private static function client(string $key, string $secret): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $key, 'secret' => $secret],
            'http' => ['connect_timeout' => 5, 'timeout' => 30],
        ]);
    }

    private static function assertAccessDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected AccessDenied.');
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() !== null) {
                self::assertSame('AccessDenied', $exception->getAwsErrorCode());
            }
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    private static function assertNoSuchUpload(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected NoSuchUpload.');
        } catch (S3Exception $exception) {
            self::assertSame('NoSuchUpload', $exception->getAwsErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        }
    }
}
