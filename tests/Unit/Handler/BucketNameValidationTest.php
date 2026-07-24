<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use OpsFour\S3Server\Exception\InvalidBucketNameException;
use OpsFour\S3Server\Handler\Bucket\CreateBucketHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketNameValidationTest extends TestCase
{
    #[DataProvider('reservedNames')]
    public function test_strict_validation_rejects_aws_reserved_names(string $name): void
    {
        $method = new \ReflectionMethod(CreateBucketHandler::class, 'validateBucketName');

        $this->expectException(InvalidBucketNameException::class);
        $method->invoke(null, $name, true);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reservedNames(): array
    {
        return [
            'S3 Express suffix' => ['bucket--x-s3'],
            'table bucket suffix' => ['bucket--table-s3'],
            'multi-region access point suffix' => ['bucket.mrap'],
            'account-regional namespace suffix' => ['bucket-an'],
        ];
    }
}
