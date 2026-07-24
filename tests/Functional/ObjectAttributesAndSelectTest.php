<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class ObjectAttributesAndSelectTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'attributes-select-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    public function test_get_object_attributes_for_regular_object(): void
    {
        $body = 'object attributes body';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'attrs.txt',
            'Body' => $body,
            'StorageClass' => 'STANDARD_IA',
            'ChecksumAlgorithm' => 'SHA256',
        ]);

        $result = self::$s3->getObjectAttributes([
            'Bucket' => self::$bucket,
            'Key' => 'attrs.txt',
            'ObjectAttributes' => ['ETag', 'ObjectSize', 'StorageClass', 'Checksum'],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame(strlen($body), (int) $result['ObjectSize']);
        $this->assertSame('STANDARD_IA', $result['StorageClass']);
        $this->assertNotEmpty($result['ETag']);
        $this->assertNotEmpty($result['Checksum']['ChecksumSHA256'] ?? null);
    }

    public function test_get_object_attributes_for_multipart_object_reports_part_count(): void
    {
        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
        ]);
        $uploadId = $create['UploadId'];

        $part1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => str_repeat('A', 5 * 1024 * 1024),
        ]);
        $part2 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'Body' => 'tail',
        ]);

        self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part1['ETag']],
                    ['PartNumber' => 2, 'ETag' => $part2['ETag']],
                ],
            ],
        ]);

        $result = self::$s3->getObjectAttributes([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'ObjectAttributes' => ['ETag', 'ObjectSize', 'ObjectParts'],
        ]);

        $this->assertSame(5 * 1024 * 1024 + 4, (int) $result['ObjectSize']);
        $this->assertSame(2, $result['ObjectParts']['TotalPartsCount']);
    }

    public function test_select_object_content_over_csv_via_aws_sdk(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'people.csv',
            'Body' => "name,age\nAda,37\nBob,41\nCyd,29\n",
            'ContentType' => 'text/csv',
        ]);

        $result = self::$s3->selectObjectContent([
            'Bucket' => self::$bucket,
            'Key' => 'people.csv',
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT name FROM s3object s WHERE age > 35',
            'InputSerialization' => [
                'CSV' => [
                    'FileHeaderInfo' => 'USE',
                    'RecordDelimiter' => "\n",
                    'FieldDelimiter' => ',',
                ],
            ],
            'OutputSerialization' => [
                'CSV' => [
                    'RecordDelimiter' => "\n",
                    'FieldDelimiter' => ',',
                ],
            ],
        ]);

        $records = '';
        foreach ($result['Payload'] as $event) {
            if (isset($event['Records']['Payload'])) {
                $records .= (string) $event['Records']['Payload'];
            }
        }

        $this->assertStringContainsString('Ada', $records);
        $this->assertStringContainsString('Bob', $records);
        $this->assertStringNotContainsString('Cyd', $records);
    }

    public function test_restore_object_sdk_route_rejects_non_archive_objects(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'standard-restore.txt',
            'Body' => 'not archived',
        ]);

        try {
            self::$s3->restoreObject([
                'Bucket' => self::$bucket,
                'Key' => 'standard-restore.txt',
                'RestoreRequest' => ['Days' => 1],
            ]);
            self::fail('Expected RestoreObject to reject a STANDARD object.');
        } catch (S3Exception $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('InvalidObjectState', $e->getAwsErrorCode());
        }
    }
}
