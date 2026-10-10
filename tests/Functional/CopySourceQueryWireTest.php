<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class CopySourceQueryWireTest extends S3FunctionalTestCase
{
    public function test_signed_copy_and_part_copy_accept_encoded_path_separators(): void
    {
        $source = 'copy-source-' . bin2hex(random_bytes(4));
        $destination = 'copy-destination-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $source]);
        self::$s3->createBucket(['Bucket' => $destination]);
        self::$s3->putObject(['Bucket' => $source, 'Key' => 'nested/key', 'Body' => 'encoded source']);
        $copySource = '%2F' . $source . '%2Fnested%2Fkey';

        try {
            $response = $this->signedPut("/{$destination}/copied", ['x-amz-copy-source' => $copySource]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('encoded source', (string) self::$s3->getObject(['Bucket' => $destination, 'Key' => 'copied'])['Body']);

            $uploadId = self::$s3->createMultipartUpload(['Bucket' => $destination, 'Key' => 'part-copy'])['UploadId'];
            try {
                $part = $this->signedPut(
                    "/{$destination}/part-copy?partNumber=1&uploadId=" . rawurlencode($uploadId),
                    ['x-amz-copy-source' => $copySource],
                );
                self::assertSame(200, $part->getStatusCode(), (string) $part->getBody());
                $etag = (string) (new \SimpleXMLElement((string) $part->getBody()))->ETag;
                self::$s3->completeMultipartUpload([
                    'Bucket' => $destination,
                    'Key' => 'part-copy',
                    'UploadId' => $uploadId,
                    'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $etag]]],
                ]);
                self::assertSame('encoded source', (string) self::$s3->getObject(['Bucket' => $destination, 'Key' => 'part-copy'])['Body']);
            } finally {
                try {
                    self::$s3->abortMultipartUpload(['Bucket' => $destination, 'Key' => 'part-copy', 'UploadId' => $uploadId]);
                } catch (\Throwable) {
                    // Completion already consumed the upload.
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $destination, 'Key' => 'copied']);
            self::$s3->deleteObject(['Bucket' => $destination, 'Key' => 'part-copy']);
            self::$s3->deleteObject(['Bucket' => $source, 'Key' => 'nested/key']);
            self::$s3->deleteBucket(['Bucket' => $destination]);
            self::$s3->deleteBucket(['Bucket' => $source]);
        }
    }

    public function test_signed_copy_and_part_copy_reject_empty_source_components_without_writes(): void
    {
        $source = 'copy-source-' . bin2hex(random_bytes(4));
        $destination = 'copy-destination-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $source]);
        self::$s3->createBucket(['Bucket' => $destination]);
        $uploadId = self::$s3->createMultipartUpload(['Bucket' => $destination, 'Key' => 'part-copy'])['UploadId'];

        try {
            foreach (["/{$source}/", '/'] as $copySource) {
                $copy = $this->signedPut("/{$destination}/copied", ['x-amz-copy-source' => $copySource]);
                self::assertSame(400, $copy->getStatusCode(), (string) $copy->getBody());
                self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $copy->getBody());

                $part = $this->signedPut(
                    "/{$destination}/part-copy?partNumber=1&uploadId=" . rawurlencode($uploadId),
                    ['x-amz-copy-source' => $copySource],
                );
                self::assertSame(400, $part->getStatusCode(), (string) $part->getBody());
                self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $part->getBody());
            }

            self::assertEmpty(self::$s3->listParts([
                'Bucket' => $destination,
                'Key' => 'part-copy',
                'UploadId' => $uploadId,
            ])['Parts'] ?? []);
            self::assertEmpty(self::$s3->listObjectsV2(['Bucket' => $destination])['Contents'] ?? []);
        } finally {
            self::$s3->abortMultipartUpload(['Bucket' => $destination, 'Key' => 'part-copy', 'UploadId' => $uploadId]);
            self::$s3->deleteBucket(['Bucket' => $destination]);
            self::$s3->deleteBucket(['Bucket' => $source]);
        }
    }

    public function test_signed_copy_and_part_copy_use_first_copy_source_version_id(): void
    {
        $source = 'copy-source-' . bin2hex(random_bytes(4));
        $destination = 'copy-destination-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $source]);
        self::$s3->createBucket(['Bucket' => $destination]);
        self::$s3->putBucketVersioning([
            'Bucket' => $source,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $first = self::$s3->putObject(['Bucket' => $source, 'Key' => 'key', 'Body' => 'first'])['VersionId'];
        $second = self::$s3->putObject(['Bucket' => $source, 'Key' => 'key', 'Body' => 'second'])['VersionId'];
        $copySource = '/' . $source . '/key?versionId=' . rawurlencode($first) . '&versionId=' . rawurlencode($second);

        try {
            $response = $this->signedPut("/{$destination}/copied", ['x-amz-copy-source' => $copySource]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('first', (string) self::$s3->getObject(['Bucket' => $destination, 'Key' => 'copied'])['Body']);

            $uploadId = self::$s3->createMultipartUpload(['Bucket' => $destination, 'Key' => 'part-copy'])['UploadId'];
            try {
                $part = $this->signedPut(
                    "/{$destination}/part-copy?partNumber=1&uploadId=" . rawurlencode($uploadId),
                    ['x-amz-copy-source' => $copySource],
                );
                self::assertSame(200, $part->getStatusCode(), (string) $part->getBody());
                $etag = (string) (new \SimpleXMLElement((string) $part->getBody()))->ETag;
                self::$s3->completeMultipartUpload([
                    'Bucket' => $destination,
                    'Key' => 'part-copy',
                    'UploadId' => $uploadId,
                    'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $etag]]],
                ]);
                self::assertSame('first', (string) self::$s3->getObject(['Bucket' => $destination, 'Key' => 'part-copy'])['Body']);
            } finally {
                try {
                    self::$s3->abortMultipartUpload(['Bucket' => $destination, 'Key' => 'part-copy', 'UploadId' => $uploadId]);
                } catch (\Throwable) {
                    // Completion already consumed the upload.
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $destination, 'Key' => 'copied']);
            self::$s3->deleteObject(['Bucket' => $destination, 'Key' => 'part-copy']);
            self::$s3->deleteObject(['Bucket' => $source, 'Key' => 'key', 'VersionId' => $first]);
            self::$s3->deleteObject(['Bucket' => $source, 'Key' => 'key', 'VersionId' => $second]);
            self::$s3->deleteBucket(['Bucket' => $destination]);
            self::$s3->deleteBucket(['Bucket' => $source]);
        }
    }

    /** @param array<string, string> $headers */
    private function signedPut(string $path, array $headers): ResponseInterface
    {
        $unsigned = new Request('PUT', sprintf('http://%s:%d%s', self::$host, self::$port, $path), $headers);
        $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            $unsigned,
            new Credentials(self::$accessKey, self::$secretKey),
        );

        return (new Client(['http_errors' => false, 'timeout' => 5]))->send($signed);
    }
}
