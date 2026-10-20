<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Functional tests for Phase 3: ListObjectsV2, ListObjects v1,
 * DeleteObjects batch, and CopyObject.
 */
final class ListingAndCopyTest extends S3FunctionalTestCase
{
    private const array TRANSIENT_KEYS = [
        'batch-delete-0.txt',
        'batch-delete-1.txt',
        'batch-delete-2.txt',
        'batch-delete-3.txt',
        'batch-delete-4.txt',
        'file1-copy.txt',
        'file1-replace.txt',
        'meta-copy.txt',
        'meta-source.txt',
        'quiet-delete.txt',
        'ambiguous-delete.txt',
        'missing-delete-md5.txt',
        'grouped/a/001',
        'grouped/a/002',
        'grouped/b/001',
        'tag-source.txt',
        'tag-copy.txt',
        'tag-replace.txt',
        'self-copy.txt',
    ];

    private static string $bucket = 'test-listing-bucket';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        // Create bucket and seed test objects.
        self::$s3->createBucket(['Bucket' => self::$bucket]);

        $objects = [
            'file1.txt' => 'content1',
            'file2.txt' => 'content2',
            'dir1/file3.txt' => 'content3',
            'dir1/file4.txt' => 'content4',
            'dir1/sub/file5.txt' => 'content5',
            'dir2/file6.txt' => 'content6',
            'photos/2024/jan.jpg' => 'jan-photo',
            'photos/2024/feb.jpg' => 'feb-photo',
            'photos/2025/mar.jpg' => 'mar-photo',
        ];

        foreach ($objects as $key => $body) {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => $body,
            ]);
        }

        self::$seeded = true;
    }

    protected function tearDown(): void
    {
        try {
            self::$s3->deleteObjects([
                'Bucket' => self::$bucket,
                'Delete' => [
                    'Quiet' => true,
                    'Objects' => array_map(
                        static fn(string $key): array => ['Key' => $key],
                        self::TRANSIENT_KEYS,
                    ),
                ],
            ]);
        } catch (\Throwable) {
            // Preserve the original test failure when the server is unavailable.
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // ListObjectsV2
    // -----------------------------------------------------------------

    public function test_list_objects_v2_basic(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame(9, $result['KeyCount']);
        $this->assertFalse($result['IsTruncated']);
        $this->assertCount(9, $result['Contents']);
    }

    public function test_list_objects_v2_with_prefix(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'dir1/',
        ]);

        $this->assertSame(3, $result['KeyCount']);

        $keys = array_map(fn($c) => $c['Key'], $result['Contents']);
        sort($keys);
        $this->assertSame(['dir1/file3.txt', 'dir1/file4.txt', 'dir1/sub/file5.txt'], $keys);
    }

    public function test_list_objects_v2_with_delimiter(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Delimiter' => '/',
        ]);

        // Top-level files: file1.txt, file2.txt
        $keys = array_map(fn($c) => $c['Key'], $result['Contents'] ?? []);
        sort($keys);
        $this->assertSame(['file1.txt', 'file2.txt'], $keys);

        // Common prefixes: dir1/, dir2/, photos/
        $prefixes = array_map(fn($p) => $p['Prefix'], $result['CommonPrefixes'] ?? []);
        sort($prefixes);
        $this->assertSame(['dir1/', 'dir2/', 'photos/'], $prefixes);
    }

    public function test_list_objects_v2_with_prefix_and_delimiter(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'photos/',
            'Delimiter' => '/',
        ]);

        // No direct objects under photos/ (they're all under photos/year/)
        $this->assertEmpty($result['Contents'] ?? []);

        // Common prefixes: photos/2024/, photos/2025/
        $prefixes = array_map(fn($p) => $p['Prefix'], $result['CommonPrefixes'] ?? []);
        sort($prefixes);
        $this->assertSame(['photos/2024/', 'photos/2025/'], $prefixes);
    }

    public function test_list_objects_v2_pagination(): void
    {
        $result1 = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'MaxKeys' => 3,
        ]);

        $this->assertTrue($result1['IsTruncated']);
        $this->assertSame(3, $result1['KeyCount']);
        $this->assertNotEmpty($result1['NextContinuationToken']);

        // Get second page.
        $result2 = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'MaxKeys' => 3,
            'ContinuationToken' => $result1['NextContinuationToken'],
        ]);

        $this->assertSame(3, $result2['KeyCount']);

        // Keys from page 1 and page 2 should not overlap.
        $keys1 = array_map(fn($c) => $c['Key'], $result1['Contents']);
        $keys2 = array_map(fn($c) => $c['Key'], $result2['Contents']);
        $this->assertEmpty(array_intersect($keys1, $keys2));

        // Collect all keys across all pages.
        $allKeys = $keys1;
        $token = $result2['NextContinuationToken'] ?? null;
        $allKeys = array_merge($allKeys, $keys2);

        while ($token !== null) {
            $page = self::$s3->listObjectsV2([
                'Bucket' => self::$bucket,
                'MaxKeys' => 3,
                'ContinuationToken' => $token,
            ]);
            $allKeys = array_merge(
                $allKeys,
                array_map(fn($c) => $c['Key'], $page['Contents'] ?? []),
            );
            $token = $page['NextContinuationToken'] ?? null;
        }

        $this->assertCount(9, $allKeys);
    }

    public function test_list_objects_v2_rejects_malformed_continuation_tokens_even_for_empty_pages(): void
    {
        foreach (['@@@', '', 'YQ', base64_encode("\xFF"), base64_encode(str_repeat('a', 1025))] as $token) {
            try {
                self::$s3->listObjectsV2([
                    'Bucket' => self::$bucket,
                    'MaxKeys' => 0,
                    'ContinuationToken' => $token,
                ]);
                self::fail('Malformed continuation token was accepted.');
            } catch (S3Exception $exception) {
                self::assertSame('InvalidArgument', $exception->getAwsErrorCode());
                self::assertSame(400, $exception->getStatusCode());
            }
        }
    }

    public function test_delimited_pages_do_not_repeat_a_common_prefix(): void
    {
        foreach (['grouped/a/001', 'grouped/a/002', 'grouped/b/001'] as $key) {
            self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'x']);
        }

        $first = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'grouped/',
            'Delimiter' => '/',
            'MaxKeys' => 1,
        ]);
        $this->assertSame(['grouped/a/'], array_column($first['CommonPrefixes'], 'Prefix'));
        $this->assertTrue($first['IsTruncated']);

        $second = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'grouped/',
            'Delimiter' => '/',
            'MaxKeys' => 1,
            'ContinuationToken' => $first['NextContinuationToken'],
        ]);
        $this->assertSame(['grouped/b/'], array_column($second['CommonPrefixes'], 'Prefix'));
        $this->assertFalse($second['IsTruncated']);

        $legacyFirst = self::$s3->listObjects([
            'Bucket' => self::$bucket,
            'Prefix' => 'grouped/',
            'Delimiter' => '/',
            'MaxKeys' => 1,
        ]);
        $this->assertSame('grouped/a/', $legacyFirst['NextMarker']);
        $legacySecond = self::$s3->listObjects([
            'Bucket' => self::$bucket,
            'Prefix' => 'grouped/',
            'Delimiter' => '/',
            'MaxKeys' => 1,
            'Marker' => $legacyFirst['NextMarker'],
        ]);
        $this->assertSame(['grouped/b/'], array_column($legacySecond['CommonPrefixes'], 'Prefix'));
        $this->assertFalse($legacySecond['IsTruncated']);
    }

    // -----------------------------------------------------------------
    // ListObjects v1
    // -----------------------------------------------------------------

    public function test_list_objects_v1_basic(): void
    {
        $result = self::$s3->listObjects([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertFalse($result['IsTruncated']);
        $this->assertCount(9, $result['Contents']);
    }

    public function test_list_objects_v1_with_marker_pagination(): void
    {
        $result1 = self::$s3->listObjects([
            'Bucket' => self::$bucket,
            'MaxKeys' => 4,
        ]);

        $this->assertTrue($result1['IsTruncated']);
        $this->assertCount(4, $result1['Contents']);

        $marker = $result1['NextMarker']
            ?? $result1['Contents'][count($result1['Contents']) - 1]['Key'];

        $result2 = self::$s3->listObjects([
            'Bucket' => self::$bucket,
            'MaxKeys' => 4,
            'Marker' => $marker,
        ]);

        $this->assertGreaterThanOrEqual(1, count($result2['Contents']));
        $this->assertLessThanOrEqual(5, count($result2['Contents']));

        // Ensure no overlap.
        $keys1 = array_map(fn($c) => $c['Key'], $result1['Contents']);
        $keys2 = array_map(fn($c) => $c['Key'], $result2['Contents']);
        $this->assertEmpty(array_intersect($keys1, $keys2));
    }

    // -----------------------------------------------------------------
    // DeleteObjects (batch)
    // -----------------------------------------------------------------

    public function test_delete_objects_batch(): void
    {
        // Create objects to delete.
        $keysToDelete = [];
        for ($i = 0; $i < 5; $i++) {
            $key = 'batch-delete-' . $i . '.txt';
            $keysToDelete[] = $key;
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => 'delete me ' . $i,
            ]);
        }

        // Batch delete.
        $result = self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => [
                'Objects' => array_map(fn($k) => ['Key' => $k], $keysToDelete),
            ],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertCount(5, $result['Deleted']);

        $deletedKeys = array_map(fn($d) => $d['Key'], $result['Deleted']);
        sort($deletedKeys);
        sort($keysToDelete);
        $this->assertSame($keysToDelete, $deletedKeys);

        // Verify objects are gone.
        foreach ($keysToDelete as $key) {
            try {
                self::$s3->headObject([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                ]);
                $this->fail("Expected 404 for deleted key: {$key}");
            } catch (S3Exception $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_delete_objects_quiet_mode(): void
    {
        $key = 'quiet-delete.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'quiet delete',
        ]);

        $result = self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => [
                'Quiet' => true,
                'Objects' => [['Key' => $key]],
            ],
        ]);

        // In quiet mode, Deleted list should be empty (only errors shown).
        $this->assertEmpty($result['Deleted'] ?? []);
        $this->assertEmpty($result['Errors'] ?? []);
    }

    public function test_delete_objects_non_existent_keys(): void
    {
        // Deleting non-existent keys should succeed silently.
        $result = self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => [
                'Objects' => [
                    ['Key' => 'nonexistent-1-' . uniqid()],
                    ['Key' => 'nonexistent-2-' . uniqid()],
                ],
            ],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertCount(2, $result['Deleted']);
        $this->assertEmpty($result['Errors'] ?? []);
    }

    public function test_signed_delete_objects_without_content_md5_preserves_object(): void
    {
        $key = 'missing-delete-md5.txt';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'preserved']);

        $xml = '<Delete><Object><Key>' . $key . '</Key></Object></Delete>';
        $url = sprintf('http://%s:%d/%s?delete', self::$host, self::$port, self::$bucket);
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $client = new Client(['http_errors' => false]);

        foreach ([
            [['Content-Type' => 'application/xml'], 'InvalidRequest'],
            [['Content-Type' => 'application/xml', 'x-amz-sdk-checksum-algorithm' => 'CRC32'], 'InvalidRequest'],
            [['Content-Type' => 'application/xml', 'x-amz-checksum-crc32' => base64_encode("\0\0\0\0")], 'BadDigest'],
        ] as [$headers, $errorCode]) {
            $request = new Request('POST', $url, $headers, $xml);
            $response = $client->send($signer->signRequest($request, $credentials));

            self::assertSame(400, $response->getStatusCode());
            self::assertStringContainsString("<Code>{$errorCode}</Code>", (string) $response->getBody());
            self::assertSame('preserved', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ])['Body']);
        }

        $validChecksum = base64_encode(pack('H*', hash('crc32b', $xml)));
        $valid = new Request('POST', $url, [
            'Content-Type' => 'application/xml',
            'x-amz-checksum-crc32' => $validChecksum,
        ], $xml);
        $response = $client->send($signer->signRequest($valid, $credentials));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<Deleted>', (string) $response->getBody());
    }

    public function test_signed_ambiguous_delete_xml_rejects_before_mutation(): void
    {
        $key = 'ambiguous-delete.txt';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'preserved']);
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $client = new Client(['http_errors' => false]);
        $url = sprintf('http://%s:%d/%s?delete', self::$host, self::$port, self::$bucket);

        foreach ([
            '<Delete><Object><Key>ambiguous-delete.txt</Key><Key>other.txt</Key></Object></Delete>',
            '<Delete><Object><Key>ambiguous-delete.txt<Other>other.txt</Other></Key></Object></Delete>',
            '<Delete xmlns:x="urn:unexpected"><Object><Key>ambiguous-delete.txt</Key><x:VersionId>fake</x:VersionId></Object></Delete>',
            '<Delete xmlns="urn:unexpected"><Object><Key>ambiguous-delete.txt</Key></Object></Delete>',
        ] as $xml) {
            $request = new Request('POST', $url, [
                'Content-Type' => 'application/xml',
                'Content-MD5' => base64_encode(md5($xml, true)),
            ], $xml);
            $response = $client->send($signer->signRequest($request, $credentials));

            self::assertSame(400, $response->getStatusCode());
            self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $response->getBody());
            self::assertSame('preserved', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ])['Body']);
        }
    }

    // -----------------------------------------------------------------
    // CopyObject
    // -----------------------------------------------------------------

    public function test_copy_object_same_bucket(): void
    {
        // Source already exists from seeding: file1.txt
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-copy.txt',
            'CopySource' => self::$bucket . '/file1.txt',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertNotEmpty($result['CopyObjectResult']['ETag'] ?? $result['ETag'] ?? '');

        // Verify the copy has the same content.
        $getResult = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-copy.txt',
        ]);
        $this->assertSame('content1', (string) $getResult['Body']);
    }

    public function test_copy_object_cross_bucket(): void
    {
        $dstBucket = 'test-copy-dst-bucket';
        self::$s3->createBucket(['Bucket' => $dstBucket]);

        $result = self::$s3->copyObject([
            'Bucket' => $dstBucket,
            'Key' => 'copied-file.txt',
            'CopySource' => self::$bucket . '/dir1/file3.txt',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Verify the copy.
        $getResult = self::$s3->getObject([
            'Bucket' => $dstBucket,
            'Key' => 'copied-file.txt',
        ]);
        $this->assertSame('content3', (string) $getResult['Body']);

        // Clean up.
        self::$s3->deleteObject(['Bucket' => $dstBucket, 'Key' => 'copied-file.txt']);
        self::$s3->deleteBucket(['Bucket' => $dstBucket]);
    }

    public function test_copy_object_with_replace_metadata(): void
    {
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-replace.txt',
            'CopySource' => self::$bucket . '/file1.txt',
            'MetadataDirective' => 'REPLACE',
            'ContentType' => 'text/html',
            'Metadata' => [
                'custom-key' => 'custom-value',
            ],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Verify the copy has new metadata.
        $headResult = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-replace.txt',
        ]);
        $this->assertSame('text/html', $headResult['ContentType']);
        $this->assertSame('custom-value', $headResult['Metadata']['custom-key'] ?? null);
    }

    public function test_copy_object_preserves_metadata_by_default(): void
    {
        // Put object with metadata.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'meta-source.txt',
            'Body' => 'meta test',
            'ContentType' => 'text/csv',
            'Metadata' => [
                'origin' => 'test',
            ],
        ]);

        // Copy without metadata directive (defaults to COPY).
        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'meta-copy.txt',
            'CopySource' => self::$bucket . '/meta-source.txt',
        ]);

        $headResult = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'meta-copy.txt',
        ]);
        $this->assertSame('text/csv', $headResult['ContentType']);
        $this->assertSame('test', $headResult['Metadata']['origin'] ?? null);
    }

    public function test_copy_object_copies_or_replaces_tags_independently_of_metadata(): void
    {
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => 'tag-source.txt', 'Body' => 'tagged']);
        self::$s3->putObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'tag-source.txt',
            'Tagging' => ['TagSet' => [['Key' => 'source', 'Value' => 'yes']]],
        ]);

        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'tag-copy.txt',
            'CopySource' => self::$bucket . '/tag-source.txt',
        ]);
        $copied = self::$s3->getObjectTagging(['Bucket' => self::$bucket, 'Key' => 'tag-copy.txt']);
        $this->assertSame([['Key' => 'source', 'Value' => 'yes']], $copied['TagSet']);

        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'tag-replace.txt',
            'CopySource' => self::$bucket . '/tag-source.txt',
            'TaggingDirective' => 'REPLACE',
            'Tagging' => 'replacement=ok',
        ]);
        $replaced = self::$s3->getObjectTagging(['Bucket' => self::$bucket, 'Key' => 'tag-replace.txt']);
        $this->assertSame([['Key' => 'replacement', 'Value' => 'ok']], $replaced['TagSet']);
    }

    public function test_copy_object_rejects_unknown_directives_before_writing(): void
    {
        foreach (['MetadataDirective', 'TaggingDirective'] as $field) {
            try {
                $command = self::$s3->getCommand('CopyObject', [
                    'Bucket' => self::$bucket,
                    'Key' => 'invalid-directive.txt',
                    'CopySource' => self::$bucket . '/file1.txt',
                    $field => 'INVALID',
                ]);
                self::$s3->execute($command);
                $this->fail("Expected {$field} to be rejected.");
            } catch (S3Exception $e) {
                $this->assertSame(400, $e->getStatusCode());
            }
        }
    }

    public function test_copy_object_defaults_destination_storage_class_to_standard(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'storage-class-source.txt',
            'Body' => 'copy-storage-class',
            'StorageClass' => 'STANDARD_IA',
        ]);

        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'storage-class-copy.txt',
            'CopySource' => self::$bucket . '/storage-class-source.txt',
        ]);

        self::assertSame('STANDARD', self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'storage-class-copy.txt',
        ])['StorageClass']);
    }

    public function test_self_copy_uses_the_effective_default_storage_class_for_change_detection(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'self-copy.txt',
            'Body' => 'self-copy',
        ]);

        $changed = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'self-copy.txt',
            'CopySource' => self::$bucket . '/self-copy.txt',
            'MetadataDirective' => 'COPY',
            'StorageClass' => 'STANDARD_IA',
        ]);
        self::assertSame(200, $changed['@metadata']['statusCode']);
        self::assertSame('STANDARD_IA', self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'self-copy.txt',
        ])['StorageClass']);

        $defaulted = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'self-copy.txt',
            'CopySource' => self::$bucket . '/self-copy.txt',
            'MetadataDirective' => 'COPY',
        ]);
        self::assertSame(200, $defaulted['@metadata']['statusCode']);
        self::assertSame('STANDARD', self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'self-copy.txt',
        ])['StorageClass']);

        foreach ([[], ['StorageClass' => 'STANDARD']] as $unchangedOptions) {
            try {
                $command = self::$s3->getCommand('CopyObject', [
                    'Bucket' => self::$bucket,
                    'Key' => 'self-copy.txt',
                    'CopySource' => self::$bucket . '/self-copy.txt',
                    'MetadataDirective' => 'COPY',
                    ...$unchangedOptions,
                ]);
                self::$s3->execute($command);
                self::fail('An unchanged current-version self-copy must be rejected.');
            } catch (S3Exception $exception) {
                self::assertSame(400, $exception->getStatusCode());
                self::assertSame('InvalidRequest', $exception->getAwsErrorCode());
                self::assertSame(
                    'This copy request is illegal because it is trying to copy an object to itself '
                    . 'without changing its metadata, storage class, or encryption attributes.',
                    $exception->getAwsErrorMessage(),
                );
            }
        }
    }
}
