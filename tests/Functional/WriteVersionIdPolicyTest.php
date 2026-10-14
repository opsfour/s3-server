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
    public function test_unsupported_destination_version_query_fails_closed_before_write_policy_conditions(): void
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
            $currentVersions = [];
            foreach (['put', 'copy', 'complete'] as $key) {
                $oldVersions[$key] = (string) self::$s3->putObject([
                    'Bucket' => $bucket, 'Key' => $key, 'Body' => 'old',
                    'Tagging' => 'classification=ordinary',
                ])['VersionId'];
                $currentVersions[$key] = (string) self::$s3->putObject([
                    'Bucket' => $bucket, 'Key' => $key, 'Body' => 'protected',
                    'Tagging' => 'classification=protected',
                ])['VersionId'];
            }
            $uploadId = (string) self::$s3->createMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'complete',
            ])['UploadId'];
            $part = self::$s3->uploadPart([
                'Bucket' => $bucket, 'Key' => 'complete', 'UploadId' => $uploadId,
                'PartNumber' => 1, 'Body' => 'replacement',
            ]);
            $protectedPolicy = $this->denyPolicy($bucket, [
                'StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected'],
            ]);
            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => $protectedPolicy,
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
            foreach ([$put, $copy, $complete] as $response) {
                self::assertSame(405, $response->getStatusCode(), (string) $response->getBody());
                self::assertStringContainsString('<Code>MethodNotAllowed</Code>', (string) $response->getBody());
            }
            $validPut = $this->signedPut("/{$bucket}/put", 'replacement');
            self::assertSame(403, $validPut->getStatusCode(), (string) $validPut->getBody());
            self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $validPut->getBody());
            foreach (['put', 'copy', 'complete'] as $key) {
                $current = self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
                self::assertSame('protected', (string) $current['Body']);
                self::assertSame($currentVersions[$key], $current['VersionId']);
                self::assertSame('old', (string) self::$s3->getObject([
                    'Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersions[$key],
                ])['Body']);
            }
            self::assertSame([1], array_column(self::$s3->listParts([
                'Bucket' => $bucket, 'Key' => 'complete', 'UploadId' => $uploadId,
            ])['Parts'], 'PartNumber'));
            self::assertSame($protectedPolicy, (string) self::$s3->getBucketPolicy(['Bucket' => $bucket])['Policy']);

            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            $oldVersion = (string) self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'null-condition', 'Body' => 'original',
            ])['VersionId'];
            $nullPolicy = $this->denyPolicy($bucket, ['Null' => ['s3:VersionId' => 'true']]);
            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => $nullPolicy,
            ]);
            $put = $this->signedPut("/{$bucket}/null-condition?versionId=" . rawurlencode($oldVersion), 'replacement');
            self::assertSame(405, $put->getStatusCode(), (string) $put->getBody());
            self::assertStringContainsString('<Code>MethodNotAllowed</Code>', (string) $put->getBody());
            $validPut = $this->signedPut("/{$bucket}/null-condition", 'replacement');
            self::assertSame(403, $validPut->getStatusCode(), (string) $validPut->getBody());
            self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $validPut->getBody());
            $current = self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'null-condition', 'VersionId' => $oldVersion,
            ]);
            self::assertSame('original', (string) $current['Body']);
            self::assertSame($oldVersion, $current['VersionId']);
            self::assertSame($nullPolicy, (string) self::$s3->getBucketPolicy(['Bucket' => $bucket])['Policy']);
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
