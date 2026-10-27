<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Acl\ObjectWriteOwnershipDecision;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartStorageClassListingBackendTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function metadataDrivers(): iterable
    {
        yield 'sqlite' => ['sqlite', null];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('metadataDrivers')]
    public function test_multipart_upload_metadata_reaches_flat_and_delimited_pages(string $driver, ?string $dsnEnv): void
    {
        $path = null;
        if ($dsnEnv !== null) {
            $dsn = getenv($dsnEnv);
            if ($dsn === false || trim($dsn) === '') {
                self::markTestSkipped("Set {$dsnEnv} to run the {$driver} multipart listing test.");
            }
            $config = ['dsn' => trim($dsn)];
        } else {
            $path = sys_get_temp_dir() . '/s3-multipart-class-' . bin2hex(random_bytes(6)) . '.sqlite';
            $config = ['path' => $path];
        }

        $store = MetadataStoreFactory::create($driver, $config);
        $bucket = 'class-list-' . bin2hex(random_bytes(6));
        $uploadId = bin2hex(random_bytes(16));
        $owner = 'class-list-owner';
        $key = 'docs/example.txt';
        $aclGrants = AclGrantResolver::privateAcl($owner);
        $ownershipSnapshot = MultipartDestinationOwnershipSnapshot::fromDecision(
            new ObjectWriteOwnershipDecision(
                $owner,
                $aclGrants,
                $owner,
                BucketOwnership::OBJECT_WRITER,
            ),
            null,
        )->encode();
        $userMetadata = [
            '__mpu-storage-class' => 'STANDARD_IA',
            '__mpu-acl-grants' => json_encode($aclGrants, JSON_THROW_ON_ERROR),
            MultipartDestinationOwnershipSnapshot::METADATA_KEY => $ownershipSnapshot,
        ];
        $store->createBucket($owner, $bucket, 'us-east-1');
        try {
            $store->createMultipartUpload($uploadId, $bucket, $key, $owner, userMetadata: $userMetadata);

            $upload = $store->getMultipartUpload($uploadId);
            self::assertSame('STANDARD_IA', $upload['user_metadata']['__mpu-storage-class'] ?? null);
            self::assertSame(
                $ownershipSnapshot,
                $upload['user_metadata'][MultipartDestinationOwnershipSnapshot::METADATA_KEY] ?? null,
            );

            foreach ([null, '/'] as $delimiter) {
                $listing = $store->listMultipartUploads($bucket, prefix: 'docs/', delimiter: $delimiter);
                self::assertSame([$uploadId], array_column($listing['uploads'], 'upload_id'));
                $metadata = json_decode($listing['uploads'][0]['user_metadata'] ?? '', true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('STANDARD_IA', $metadata['__mpu-storage-class']);
                self::assertSame(
                    $ownershipSnapshot,
                    $metadata[MultipartDestinationOwnershipSnapshot::METADATA_KEY] ?? null,
                );
            }
        } finally {
            $store->deleteMultipartUpload($uploadId);
            $store->deleteBucket($owner, $bucket);
            if ($path !== null) {
                @unlink($path);
                @unlink($path . '-wal');
                @unlink($path . '-shm');
            }
        }
    }
}
