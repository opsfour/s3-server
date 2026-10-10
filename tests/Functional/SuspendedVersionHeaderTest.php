<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

final class SuspendedVersionHeaderTest extends S3FunctionalTestCase
{
    public function test_signed_raw_version_headers_distinguish_unversioned_and_suspended_objects(): void
    {
        $bucket = 'null-version-header-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $unversioned = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'unversioned']);
            self::assertNull(self::versionHeader($unversioned));
            $unversionedCopy = self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'copied', 'CopySource' => $bucket . '/key',
            ]);
            self::assertNull(self::versionHeader($unversionedCopy));
            self::assertNull(self::versionHeader(self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'key'])));
            self::assertNull(self::versionHeader(self::$s3->headObject(['Bucket' => $bucket, 'Key' => 'key'])));

            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            $numbered = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'numbered']);
            self::assertNotSame('null', self::versionHeader($numbered));
            foreach (['getObject', 'headObject'] as $operation) {
                $notModified = self::$s3->{$operation}([
                    'Bucket' => $bucket,
                    'Key' => 'key',
                    'VersionId' => $numbered['VersionId'],
                    'IfNoneMatch' => $numbered['ETag'],
                ]);
                self::assertSame(304, $notModified['@metadata']['statusCode']);
                self::assertSame($numbered['VersionId'], self::versionHeader($notModified));
            }
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
            $suspended = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'suspended']);
            self::assertSame('null', self::versionHeader($suspended));
            foreach (['getObject', 'headObject'] as $operation) {
                $notModified = self::$s3->{$operation}([
                    'Bucket' => $bucket,
                    'Key' => 'key',
                    'VersionId' => 'null',
                    'IfNoneMatch' => $suspended['ETag'],
                ]);
                self::assertSame(304, $notModified['@metadata']['statusCode']);
                self::assertSame('null', self::versionHeader($notModified));
            }
            $signer = new S3SignatureV4('s3', 'us-east-1');
            $credentials = new Credentials(self::$accessKey, self::$secretKey);
            $http = new Client(['http_errors' => false, 'timeout' => 5]);
            foreach ([
                ['versionId' => 'null', 'etag' => $suspended['ETag']],
                ['versionId' => $numbered['VersionId'], 'etag' => $numbered['ETag']],
            ] as $case) {
                $url = sprintf(
                    'http://%s:%d/%s/key?attributes&versionId=%s',
                    self::$host,
                    self::$port,
                    $bucket,
                    rawurlencode((string) $case['versionId']),
                );
                $request = $signer->signRequest(new Request('GET', $url, [
                    'if-none-match' => $case['etag'],
                    'x-amz-object-attributes' => 'ETag,ObjectSize',
                ]), $credentials);
                $response = $http->send($request);

                self::assertSame(304, $response->getStatusCode(), (string) $response->getBody());
                self::assertSame($case['versionId'], $response->getHeaderLine('x-amz-version-id'));
            }
            $suspendedCopy = self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'copied', 'CopySource' => $bucket . '/key',
            ]);
            self::assertSame('null', self::versionHeader($suspendedCopy));
            // Keep this version-header contract independent from the SDK's
            // optional automatic multipart checksum extension.
            $multipartClient = self::makeClient(requestChecksumCalculation: 'when_required');
            $multipart = $multipartClient->createMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'multipart',
            ]);
            $part = $multipartClient->uploadPart([
                'Bucket' => $bucket,
                'Key' => 'multipart',
                'UploadId' => $multipart['UploadId'],
                'PartNumber' => 1,
                'Body' => 'multipart-suspended',
            ]);
            $completed = $multipartClient->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => 'multipart',
                'UploadId' => $multipart['UploadId'],
                'MultipartUpload' => ['Parts' => [[
                    'PartNumber' => 1,
                    'ETag' => $part['ETag'],
                ]]],
            ]);
            self::assertSame('null', self::versionHeader($completed));
            self::assertSame('suspended', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'copied',
            ])['Body']);

            foreach ([
                ['getObject', []],
                ['getObject', ['VersionId' => 'null']],
                ['headObject', []],
                ['headObject', ['VersionId' => 'null']],
            ] as [$operation, $extra]) {
                $result = self::$s3->{$operation}(['Bucket' => $bucket, 'Key' => 'key', ...$extra]);
                self::assertSame('null', self::versionHeader($result), $operation . ' ' . json_encode($extra));
            }
            self::assertSame('suspended', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'key', 'VersionId' => 'null',
            ])['Body']);

            foreach ([
                ['getObjectAcl', []],
                ['putObjectAcl', ['ACL' => 'private']],
                ['getObjectTagging', []],
                ['putObjectTagging', ['Tagging' => ['TagSet' => [['Key' => 'state', 'Value' => 'suspended']]]]],
                ['deleteObjectTagging', []],
            ] as [$operation, $operationArgs]) {
                foreach ([[], ['VersionId' => 'null']] as $versionArgs) {
                    $result = self::$s3->{$operation}([
                        'Bucket' => $bucket,
                        'Key' => 'key',
                        ...$operationArgs,
                        ...$versionArgs,
                    ]);
                    self::assertSame('null', self::versionHeader($result), $operation . ' ' . json_encode($versionArgs));
                }
            }

            $deleted = self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::assertSame('null', self::versionHeader($deleted));
            self::assertTrue($deleted['DeleteMarker']);
            foreach (['getObject', 'headObject'] as $operation) {
                foreach ([[null, 404], ['null', 405]] as [$requestedVersion, $expectedStatus]) {
                    $request = ['Bucket' => $bucket, 'Key' => 'key'];
                    if ($requestedVersion !== null) {
                        $request['VersionId'] = $requestedVersion;
                    }
                    try {
                        self::$s3->{$operation}($request);
                        self::fail('A current or selected delete marker must not return object data.');
                    } catch (S3Exception $exception) {
                        $response = $exception->getResponse();
                        self::assertNotNull($response);
                        self::assertSame($expectedStatus, $exception->getStatusCode());
                        self::assertSame('true', $response->getHeaderLine('x-amz-delete-marker'));
                        self::assertSame('null', $response->getHeaderLine('x-amz-version-id'));
                        if ($requestedVersion !== null) {
                            self::assertNotSame('', $response->getHeaderLine('Last-Modified'));
                        }
                    }
                }
            }

            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'key', 'Body' => 'after-marker']);
            self::assertSame('after-marker', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'key'])['Body']);
            $numberedMarker = self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::assertNotSame('null', $numberedMarker['VersionId']);
            foreach (['getObject', 'headObject'] as $operation) {
                foreach ([[[], 404], [['VersionId' => $numberedMarker['VersionId']], 405]] as [$extra, $expectedStatus]) {
                    try {
                        self::$s3->{$operation}(['Bucket' => $bucket, 'Key' => 'key', ...$extra]);
                        self::fail('A numbered delete marker must not return object data.');
                    } catch (S3Exception $exception) {
                        $response = $exception->getResponse();
                        self::assertNotNull($response);
                        self::assertSame($expectedStatus, $exception->getStatusCode());
                        self::assertSame('true', $response->getHeaderLine('x-amz-delete-marker'));
                        self::assertSame($numberedMarker['VersionId'], $response->getHeaderLine('x-amz-version-id'));
                        if ($extra !== []) {
                            self::assertNotSame('', $response->getHeaderLine('Last-Modified'));
                        }
                    }
                }
            }
        } finally {
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $bucket, 'Key' => $version['Key'], 'VersionId' => $version['VersionId'],
                ]);
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    /** @param Result<mixed> $result */
    private static function versionHeader(Result $result): ?string
    {
        return $result['@metadata']['headers']['x-amz-version-id'] ?? null;
    }
}
