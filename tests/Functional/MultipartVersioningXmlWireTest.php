<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class MultipartVersioningXmlWireTest extends S3FunctionalTestCase
{
    public function test_signed_versioning_rejects_ambiguous_or_foreign_status_without_changing_bucket(): void
    {
        $bucket = 'versioning-xml-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            foreach ([
                '<VersioningConfiguration><Status>Enabled</Status><Status>Suspended</Status></VersioningConfiguration>',
                '<VersioningConfiguration xmlns:x="urn:other"><Status>Enabled</Status><x:Status>Suspended</x:Status></VersioningConfiguration>',
                '<VersioningConfiguration xmlns:x="urn:other"><Status>Enabled<x:Ignored/></Status></VersioningConfiguration>',
            ] as $xml) {
                $response = $this->signedXml('PUT', "/{$bucket}?versioning", $xml);
                self::assertSame(400, $response->getStatusCode(), $xml);
                self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $response->getBody());
                self::assertEmpty(self::$s3->getBucketVersioning(['Bucket' => $bucket])['Status'] ?? '');
            }
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_signed_multipart_completion_rejects_ambiguous_part_xml_without_consuming_upload(): void
    {
        $bucket = 'multipart-xml-' . bin2hex(random_bytes(4));
        $key = 'object';
        self::$s3->createBucket(['Bucket' => $bucket]);
        $uploadId = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key])['UploadId'];
        try {
            $etag = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => 'part',
            ])['ETag'];
            foreach ([
                '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><PartNumber>2</PartNumber><ETag>' . $etag . '</ETag></Part></CompleteMultipartUpload>',
                '<CompleteMultipartUpload xmlns:x="urn:other"><Part><PartNumber>1</PartNumber><ETag>' . $etag . '</ETag><x:ETag>wrong</x:ETag></Part></CompleteMultipartUpload>',
                '<CompleteMultipartUpload xmlns:x="urn:other"><Part><PartNumber>1<x:Ignored/></PartNumber><ETag>' . $etag . '</ETag></Part></CompleteMultipartUpload>',
            ] as $xml) {
                $response = $this->signedXml('POST', "/{$bucket}/{$key}?uploadId=" . rawurlencode($uploadId), $xml);
                self::assertSame(400, $response->getStatusCode(), $xml);
                self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $response->getBody());
                self::assertCount(1, self::$s3->listParts([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ])['Parts']);
            }
        } finally {
            try {
                self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            } catch (\Throwable) {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_signed_multipart_query_rejects_conflicting_upload_id_and_part_number(): void
    {
        $bucket = 'multipart-query-' . bin2hex(random_bytes(4));
        $key = 'object';
        self::$s3->createBucket(['Bucket' => $bucket]);
        $uploadId = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key])['UploadId'];
        try {
            foreach (['uploadId=', 'uploadId', 'uploadId=&uploadId=' . rawurlencode($uploadId)] as $query) {
                $response = $this->signedXml('GET', "/{$bucket}/{$key}?{$query}", '');
                $conflicting = str_contains($query, '&uploadId=');
                self::assertSame($conflicting ? 403 : 404, $response->getStatusCode(), $query);
                self::assertStringContainsString(
                    $conflicting ? '<Code>AccessDenied</Code>' : '<Code>NoSuchUpload</Code>',
                    (string) $response->getBody(),
                );
            }

            $badPart = $this->signedXml(
                'PUT',
                "/{$bucket}/{$key}?partNumber=&partNumber=1&uploadId=" . rawurlencode($uploadId),
                'part',
            );
            self::assertSame(403, $badPart->getStatusCode());
            self::assertEmpty(self::$s3->listParts([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ])['Parts'] ?? []);
        } finally {
            self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    private function signedXml(string $method, string $path, string $xml): ResponseInterface
    {
        $unsigned = new Request(
            $method,
            sprintf('http://%s:%d%s', self::$host, self::$port, $path),
            [
                'Content-Type' => 'application/xml',
                'Content-MD5' => base64_encode(md5($xml, true)),
            ],
            $xml,
        );
        $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            $unsigned,
            new Credentials(self::$accessKey, self::$secretKey),
        );

        return (new Client(['http_errors' => false, 'timeout' => 5]))->send($signed);
    }
}
