<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit;

use Aws\S3\S3Client;
use Aws\Sdk;
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
        if (version_compare(Sdk::VERSION, $matrix['sdk_version'], '>=')) {
            $this->assertSame([], $stale, 'Support matrix entries no longer present in the installed AWS SDK.');
        }
    }

    public function test_every_operation_has_exactly_one_classification(): void
    {
        $matrix = self::matrix();
        $classified = array_merge(
            $matrix['supported'],
            $matrix['unsupported'],
            $matrix['optional_external'],
        );
        $counts = array_count_values($classified);
        $duplicates = array_keys(array_filter($counts, static fn(int $count): bool => $count !== 1));
        sort($duplicates);

        $this->assertSame([], $duplicates, 'S3 operations must occur in exactly one support category.');
    }

    public function test_supported_operations_are_referenced_by_functional_sdk_tests(): void
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

        $this->assertSame([], $missing, 'Supported S3 operations without a functional AWS SDK reference.');
    }

    public function test_functional_coverage_scan_ignores_comments_and_string_literals(): void
    {
        $source = <<<'PHP'
            <?php
            // self::$s3->deleteBucket(['Bucket' => 'comment']);
            $text = 'self::$s3->putObject()';
            self::$s3->getObject(['Bucket' => 'real']);
            PHP;

        self::assertSame(['getObject' => true], self::sdkOperationsFromSource($source));
    }

    /**
     * @return array{sdk_version: string, supported: list<string>, unsupported: list<string>, optional_external: list<string>}
     */
    private static function matrix(): array
    {
        /** @var array{sdk_version: string, supported: list<string>, unsupported: list<string>, optional_external: list<string>} $matrix */
        $matrix = require __DIR__ . '/../Support/aws-s3-operations.php';

        return $matrix;
    }

    /**
     * @param array{sdk_version: string, supported: list<string>, unsupported: list<string>, optional_external: list<string>} $matrix
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

            $operations += self::sdkOperationsFromSource($contents);
        }

        return $operations;
    }

    /** @return array<string, true> */
    private static function sdkOperationsFromSource(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn(array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $operations = [];
        for ($index = 0; $index + 4 < count($tokens); $index++) {
            $self = $tokens[$index];
            $separator = $tokens[$index + 1];
            $client = $tokens[$index + 2];
            $operator = $tokens[$index + 3];
            $method = $tokens[$index + 4];
            if (! is_array($self) || strtolower($self[1]) !== 'self'
                || ! is_array($separator) || $separator[0] !== T_DOUBLE_COLON
                || ! is_array($client) || $client[0] !== T_VARIABLE || $client[1] !== '$s3'
                || ! is_array($operator) || $operator[0] !== T_OBJECT_OPERATOR
                || ! is_array($method) || $method[0] !== T_STRING) {
                continue;
            }
            $operations[$method[1]] = true;
        }

        return $operations;
    }
}
