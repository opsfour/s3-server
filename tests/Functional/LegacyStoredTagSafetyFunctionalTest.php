<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;

final class LegacyStoredTagSafetyFunctionalTest extends S3FunctionalTestCase
{
    public function test_corrupt_legacy_tags_return_internal_error_and_copy_preserves_destination(): void
    {
        $bucket = 'legacy-tag-safety-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'source', 'Body' => 'source']);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'destination',
            'Body' => 'destination',
            'Tagging' => 'baseline=unchanged',
        ]);

        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        $metadata->putBucketTagging($bucket, [
            ['key' => 'legacy', 'value' => "control\u{000B}"],
        ]);
        $metadata->putObjectTagging($bucket, 'source', [
            ['key' => "legacy\u{FDD0}", 'value' => 'value'],
        ]);

        $this->assertInternalError(static fn() => self::$s3->getBucketTagging([
            'Bucket' => $bucket,
        ]));
        $this->assertInternalError(static fn() => self::$s3->getObjectTagging([
            'Bucket' => $bucket,
            'Key' => 'source',
        ]));
        $this->assertInternalError(static fn() => self::$s3->copyObject([
            'Bucket' => $bucket,
            'Key' => 'destination',
            'CopySource' => $bucket . '/source',
        ]));

        self::assertSame('destination', (string) self::$s3->getObject([
            'Bucket' => $bucket,
            'Key' => 'destination',
        ])['Body']);
        self::assertSame(
            [['Key' => 'baseline', 'Value' => 'unchanged']],
            self::$s3->getObjectTagging([
                'Bucket' => $bucket,
                'Key' => 'destination',
            ])['TagSet'],
        );
    }

    /** @param \Closure(): mixed $request */
    private function assertInternalError(\Closure $request): void
    {
        try {
            $request();
            self::fail('Corrupt stored tags must not produce a successful S3 response.');
        } catch (S3Exception $error) {
            self::assertSame(500, $error->getStatusCode());
            self::assertSame('InternalError', $error->getAwsErrorCode());
        }
    }
}
