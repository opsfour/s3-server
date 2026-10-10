<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class XmlMutationAmbiguityWireTest extends S3FunctionalTestCase
{
    public function test_signed_bucket_tagging_rejects_duplicate_and_foreign_fields_without_replacement(): void
    {
        $bucket = 'xml-tags-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketTagging(['Bucket' => $bucket, 'Tagging' => ['TagSet' => [['Key' => 'baseline', 'Value' => '1']]]]);
        try {
            foreach ([
                '<Tagging><TagSet><Tag><Key>changed</Key><Key>baseline</Key><Value>2</Value></Tag></TagSet></Tagging>',
                '<Tagging xmlns:x="urn:other"><TagSet><Tag><Key>changed</Key><Value>2</Value><x:Value>1</x:Value></Tag></TagSet></Tagging>',
            ] as $xml) {
                $response = $this->signedXml('PUT', "/{$bucket}?tagging", $xml);
                self::assertMalformed($response);
                self::assertSame([['Key' => 'baseline', 'Value' => '1']], self::$s3->getBucketTagging(['Bucket' => $bucket])['TagSet']);
            }
        } finally {
            self::$s3->deleteBucketTagging(['Bucket' => $bucket]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_signed_bucket_acl_rejects_duplicate_permission_without_replacement(): void
    {
        $bucket = 'xml-acl-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            $before = self::$s3->getBucketAcl(['Bucket' => $bucket]);
            $owner = $before['Owner']['ID'];
            $xml = '<AccessControlPolicy><AccessControlList><Grant><Grantee><ID>' . $owner
                . '</ID></Grantee><Permission>READ</Permission><Permission>FULL_CONTROL</Permission>'
                . '</Grant></AccessControlList></AccessControlPolicy>';
            self::assertMalformed($this->signedXml('PUT', "/{$bucket}?acl", $xml));
            self::assertSame($before['Grants'], self::$s3->getBucketAcl(['Bucket' => $bucket])['Grants']);
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_signed_website_rejects_nested_scalar_without_replacing_configuration(): void
    {
        $bucket = 'xml-website-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketWebsite([
            'Bucket' => $bucket,
            'WebsiteConfiguration' => ['IndexDocument' => ['Suffix' => 'baseline.html']],
        ]);
        try {
            $xml = '<WebsiteConfiguration><IndexDocument><Suffix>changed<Ignored/>.html</Suffix>'
                . '</IndexDocument></WebsiteConfiguration>';
            self::assertMalformed($this->signedXml('PUT', "/{$bucket}?website", $xml));
            self::assertSame('baseline.html', self::$s3->getBucketWebsite(['Bucket' => $bucket])['IndexDocument']['Suffix']);

            $emptyError = '<WebsiteConfiguration><IndexDocument><Suffix>changed.html</Suffix></IndexDocument>'
                . '<ErrorDocument><Key/></ErrorDocument></WebsiteConfiguration>';
            self::assertMalformed($this->signedXml('PUT', "/{$bucket}?website", $emptyError));
            self::assertSame('baseline.html', self::$s3->getBucketWebsite(['Bucket' => $bucket])['IndexDocument']['Suffix']);
        } finally {
            self::$s3->deleteBucketWebsite(['Bucket' => $bucket]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_signed_object_lock_rejects_duplicate_period_without_replacement(): void
    {
        $bucket = 'xml-lock-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        try {
            $before = self::$s3->getObjectLockConfiguration(['Bucket' => $bucket])['ObjectLockConfiguration'];
            $xml = '<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled><Rule><DefaultRetention>'
                . '<Mode>GOVERNANCE</Mode><Days>2</Days><Days>3</Days>'
                . '</DefaultRetention></Rule></ObjectLockConfiguration>';
            self::assertMalformed($this->signedXml('PUT', "/{$bucket}?object-lock", $xml));
            self::assertSame($before, self::$s3->getObjectLockConfiguration(['Bucket' => $bucket])['ObjectLockConfiguration']);
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_signed_retention_and_legal_hold_reject_nested_scalar_before_object_mutation(): void
    {
        $bucket = 'xml-object-lock-' . bin2hex(random_bytes(4));
        $key = 'object';
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        $versionId = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'payload'])['VersionId'];
        try {
            $date = (new \DateTimeImmutable('+2 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            $retention = '<Retention xmlns:x="urn:other"><Mode>GOVERNANCE<x:Ignored/></Mode>'
                . '<RetainUntilDate>' . $date . '</RetainUntilDate></Retention>';
            self::assertMalformed($this->signedXml('PUT', "/{$bucket}/{$key}?retention&versionId=" . rawurlencode($versionId), $retention));

            $hold = '<LegalHold xmlns:x="urn:other"><Status>ON<x:Ignored/></Status></LegalHold>';
            self::assertMalformed($this->signedXml('PUT', "/{$bucket}/{$key}?legal-hold&versionId=" . rawurlencode($versionId), $hold));

            foreach (['getObjectRetention', 'getObjectLegalHold'] as $operation) {
                try {
                    self::$s3->$operation(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId]);
                    self::fail($operation . ' unexpectedly returned a configuration.');
                } catch (S3Exception $e) {
                    self::assertSame(404, $e->getStatusCode());
                }
            }
        } finally {
            self::$s3->putObjectLegalHold([
                'Bucket' => $bucket,
                'Key' => $key,
                'VersionId' => $versionId,
                'LegalHold' => ['Status' => 'OFF'],
            ]);
            self::$s3->deleteObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'VersionId' => $versionId,
                'BypassGovernanceRetention' => true,
            ]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    private static function assertMalformed(ResponseInterface $response): void
    {
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $response->getBody());
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
