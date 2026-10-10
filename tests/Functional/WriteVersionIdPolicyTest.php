<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class WriteVersionIdPolicyTest extends S3FunctionalTestCase
{
    public function test_ignored_destination_version_query_cannot_redirect_write_policy_conditions(): void
    {
        $bucket = 'write-version-policy-' . bin2hex(random_bytes(5));
        $uploadId = null;
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning([
                'Bucket' => $bucket,
                'VersioningConfiguration' => ['Status' => 'Enabled'],
            ]);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'source', 'Body' => 'source']);
            $oldVersions = [];
            foreach (['put', 'copy', 'complete'] as $key) {
                $oldVersions[$key] = (string) self::$s3->putObject([
                    'Bucket' => $bucket, 'Key' => $key, 'Body' => 'old',
                    'Tagging' => 'classification=ordinary',
                ])['VersionId'];
                self::$s3->putObject([
                    'Bucket' => $bucket, 'Key' => $key, 'Body' => 'protected',
                    'Tagging' => 'classification=protected',
                ]);
            }
            $uploadId = (string) self::$s3->createMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'complete',
            ])['UploadId'];
            $part = self::$s3->uploadPart([
                'Bucket' => $bucket, 'Key' => 'complete', 'UploadId' => $uploadId,
                'PartNumber' => 1, 'Body' => 'replacement',
            ]);
            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => $this->denyPolicy($bucket, [
                    'StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected'],
                ]),
            ]);

            $put = $this->signedPut("/{$bucket}/put?versionId=" . rawurlencode($oldVersions['put']), 'replacement');
            $copy = $this->signedPut("/{$bucket}/copy?versionId=" . rawurlencode($oldVersions['copy']), '', [
                'x-amz-copy-source' => "/{$bucket}/source",
            ]);
            $completeXml = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
                . htmlspecialchars((string) $part['ETag'], ENT_XML1)
                . '</ETag></Part></CompleteMultipartUpload>';
            $complete = $this->signedRequest(
                'POST',
                "/{$bucket}/complete?uploadId=" . rawurlencode($uploadId)
                    . '&versionId=' . rawurlencode($oldVersions['complete']),
                $completeXml,
            );
            self::assertSame(403, $put->getStatusCode(), (string) $put->getBody());
            self::assertSame(403, $copy->getStatusCode(), (string) $copy->getBody());
            self::assertSame(403, $complete->getStatusCode(), (string) $complete->getBody());
            self::assertSame('protected', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'put'])['Body']);
            self::assertSame('protected', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'copy'])['Body']);
            self::assertSame('protected', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'complete'])['Body']);

            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            $oldVersion = (string) self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'null-condition', 'Body' => 'original',
            ])['VersionId'];
            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => $this->denyPolicy($bucket, ['Null' => ['s3:VersionId' => 'true']]),
            ]);
            $put = $this->signedPut("/{$bucket}/null-condition?versionId=" . rawurlencode($oldVersion), 'replacement');
            self::assertSame(403, $put->getStatusCode(), (string) $put->getBody());
            self::assertSame('original', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'null-condition', 'VersionId' => $oldVersion,
            ])['Body']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            if ($uploadId !== null) {
                try {
                    self::$s3->abortMultipartUpload([
                        'Bucket' => $bucket, 'Key' => 'complete', 'UploadId' => $uploadId,
                    ]);
                } catch (\Throwable) {
                    // The denied completion did not consume the upload; keep
                    // cleanup tolerant if a future implementation does.
                }
            }
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $bucket, 'Key' => $version['Key'], 'VersionId' => $version['VersionId'],
                ]);
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    /** @param array<string, array<string, string>> $condition */
    private function denyPolicy(string $bucket, array $condition): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => "arn:aws:s3:::{$bucket}/*",
                'Condition' => $condition,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $headers */
    private function signedPut(string $path, string $body, array $headers = []): ResponseInterface
    {
        return $this->signedRequest('PUT', $path, $body, $headers);
    }

    /** @param array<string, string> $headers */
    private function signedRequest(string $method, string $path, string $body, array $headers = []): ResponseInterface
    {
        $request = new Request($method, sprintf('http://%s:%d%s', self::$host, self::$port, $path), $headers, $body);
        $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            $request,
            new Credentials(self::$accessKey, self::$secretKey),
        );

        return (new Client(['http_errors' => false, 'timeout' => 5]))->send($signed);
    }
}
