<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

final class AwsSdkSupportMatrixTest extends TestCase
{
    /**
     * SDK method aliases that hit the same REST operation/query route as the
     * canonical operation names used by the package handlers.
     *
     * @var array<string, string>
     */
    private const array COVERAGE_ALIASES = [
        'getBucketLifecycle' => 'getBucketLifecycleConfiguration',
        'putBucketLifecycle' => 'putBucketLifecycleConfiguration',
        'getBucketNotification' => 'getBucketNotificationConfiguration',
        'putBucketNotification' => 'putBucketNotificationConfiguration',
    ];

    public function test_every_current_aws_sdk_s3_operation_is_classified(): void
    {
        $matrix = self::matrix();
        $classified = self::classifiedOperations($matrix);
        $sdkOperations = self::sdkOperations();

        $missing = array_values(array_diff($sdkOperations, $classified));
        $stale = array_values(array_diff($classified, $sdkOperations));

        $this->assertSame([], $missing, 'AWS SDK S3 operations missing from support matrix.');
        $this->assertSame([], $stale, 'Support matrix entries no longer present in the installed AWS SDK.');
    }

    public function test_supported_operations_have_functional_sdk_coverage(): void
    {
        $matrix = self::matrix();
        $testedOperations = self::testedFunctionalOperations();

        $missing = [];
        foreach ($matrix['supported'] as $operation) {
            $coveredBy = self::COVERAGE_ALIASES[$operation] ?? $operation;

            if (!isset($testedOperations[$coveredBy])) {
                $missing[] = $operation;
            }
        }

        sort($missing);

        $this->assertSame([], $missing, 'Supported S3 operations without functional AWS SDK coverage.');
    }

    /**
     * @return array{supported: list<string>, unsupported: list<string>, optional_external: list<string>}
     */
    private static function matrix(): array
    {
        /** @var array{supported: list<string>, unsupported: list<string>, optional_external: list<string>} $matrix */
        $matrix = require __DIR__ . '/../Support/aws-s3-operations.php';

        return $matrix;
    }

    /**
     * @param array{supported: list<string>, unsupported: list<string>, optional_external: list<string>} $matrix
     * @return list<string>
     */
    private static function classifiedOperations(array $matrix): array
    {
        $classified = array_merge(
            $matrix['supported'],
            $matrix['unsupported'],
            $matrix['optional_external'],
        );
        sort($classified);

        return array_values(array_unique($classified));
    }

    /**
     * @return list<string>
     */
    private static function sdkOperations(): array
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => [
                'key' => 'matrix-key',
                'secret' => 'matrix-secret',
            ],
        ]);

        $operations = [];
        foreach ($client->getApi()->getOperations() as $name => $_operation) {
            $operations[] = lcfirst((string) $name);
        }

        sort($operations);

        return $operations;
    }

    /**
     * @return array<string, true>
     */
    private static function testedFunctionalOperations(): array
    {
        $operations = [];

        foreach (glob(__DIR__ . '/../Functional/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            preg_match_all('/self::\$s3->([A-Za-z0-9_]+)/', $contents, $matches);
            foreach ($matches[1] as $operation) {
                $operations[$operation] = true;
            }
        }

        return $operations;
    }
}
