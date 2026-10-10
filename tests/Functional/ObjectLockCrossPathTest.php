<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;

final class ObjectLockCrossPathTest extends S3FunctionalTestCase
{
    public function test_presigned_object_lock_mutations_validate_declared_and_supplied_checksums(): void
    {
        $bucket = 'lock-digest-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        $put = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'held.txt', 'Body' => 'protected']);
        $versionId = (string) $put['VersionId'];
        $retainUntil = new \DateTimeImmutable('+10 days', new \DateTimeZone('UTC'));
        self::$s3->putObjectRetention([
            'Bucket' => $bucket,
            'Key' => 'held.txt',
            'VersionId' => $versionId,
            'Retention' => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => $retainUntil],
        ]);
        self::$s3->putObjectLegalHold([
            'Bucket' => $bucket,
            'Key' => 'held.txt',
            'VersionId' => $versionId,
            'LegalHold' => ['Status' => 'ON'],
        ]);

        $base = sprintf('http://%s:%d/%s', self::$host, self::$port, $bucket);
        $object = $base . '/held.txt?versionId=' . rawurlencode($versionId);
        $retentionXml = '<Retention><Mode>GOVERNANCE</Mode><RetainUntilDate>'
            . $retainUntil->modify('+10 days')->format('Y-m-d\TH:i:s\Z')
            . '</RetainUntilDate></Retention>';
        $legalHoldOffXml = '<LegalHold><Status>OFF</Status></LegalHold>';
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $client = new Client(['http_errors' => false, 'timeout' => 5]);
        foreach ([
            'configuration' => [$base . '?object-lock', '<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled><Rule><DefaultRetention><Mode>GOVERNANCE</Mode><Days>30</Days></DefaultRetention></Rule></ObjectLockConfiguration>'],
            'retention' => [$object . '&retention', $retentionXml],
            'legal hold' => [$object . '&legal-hold', $legalHoldOffXml],
        ] as $case => [$url, $xml]) {
            $signed = $signer->presign(
                new Request('PUT', $url, ['x-amz-sdk-checksum-algorithm' => 'SHA256'], $xml),
                $credentials,
                '+5 minutes',
            );
            $response = $client->send($signed);
            self::assertSame(400, $response->getStatusCode(), $case . ': ' . (string) $response->getBody());
            self::assertStringContainsString('<Code>InvalidRequest</Code>', (string) $response->getBody(), $case);
        }

        self::assertArrayNotHasKey('Rule', self::$s3->getObjectLockConfiguration(['Bucket' => $bucket])['ObjectLockConfiguration']);
        $storedRetention = self::$s3->getObjectRetention(['Bucket' => $bucket, 'Key' => 'held.txt', 'VersionId' => $versionId]);
        self::assertSame($retainUntil->format('Y-m-d\TH:i:s'), $storedRetention['Retention']['RetainUntilDate']->format('Y-m-d\TH:i:s'));
        self::assertSame('ON', self::$s3->getObjectLegalHold([
            'Bucket' => $bucket,
            'Key' => 'held.txt',
            'VersionId' => $versionId,
        ])['LegalHold']['Status']);

        $checksum = base64_encode(hash('sha256', $legalHoldOffXml, true));
        $signed = $signer->presign(
            new Request('PUT', $object . '&legal-hold', ['x-amz-checksum-sha256' => $checksum], $legalHoldOffXml),
            $credentials,
            '+5 minutes',
        );
        $tampered = $client->send($signed->withBody(Utils::streamFor('<LegalHold><Status>ON</Status></LegalHold>')));
        self::assertSame(400, $tampered->getStatusCode(), (string) $tampered->getBody());
        self::assertStringContainsString('<Code>BadDigest</Code>', (string) $tampered->getBody());

        $valid = $client->send($signed);
        self::assertSame(200, $valid->getStatusCode(), (string) $valid->getBody());
        self::assertSame('OFF', self::$s3->getObjectLegalHold([
            'Bucket' => $bucket,
            'Key' => 'held.txt',
            'VersionId' => $versionId,
        ])['LegalHold']['Status']);
    }

    public function test_batch_delete_cannot_bypass_compliance_or_legal_hold_and_plain_delete_only_adds_marker(): void
    {
        $bucket = 'lock-cross-path-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        $key = 'protected.txt';
        $put = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'protected bytes']);
        $versionId = (string) $put['VersionId'];
        $retainUntil = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
        self::$s3->putObjectRetention([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'Retention' => ['Mode' => 'COMPLIANCE', 'RetainUntilDate' => $retainUntil],
        ]);
        self::$s3->putObjectLegalHold([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'LegalHold' => ['Status' => 'ON'],
        ]);

        $batch = self::$s3->deleteObjects([
            'Bucket' => $bucket,
            'Delete' => ['Objects' => [['Key' => $key, 'VersionId' => $versionId]]],
            'BypassGovernanceRetention' => true,
        ]);
        self::assertCount(1, $batch['Errors']);
        self::assertSame('AccessDenied', $batch['Errors'][0]['Code']);
        self::assertEmpty($batch['Deleted'] ?? []);

        $marker = self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        self::assertTrue((bool) $marker['DeleteMarker']);
        self::assertNotSame($versionId, (string) $marker['VersionId']);
        self::assertSame('protected bytes', (string) self::$s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $versionId,
        ])['Body']);
        self::assertSame('COMPLIANCE', self::$s3->getObjectRetention([
            'Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId,
        ])['Retention']['Mode']);
        self::assertSame('ON', self::$s3->getObjectLegalHold([
            'Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId,
        ])['LegalHold']['Status']);
    }
}
