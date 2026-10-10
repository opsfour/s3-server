<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\BucketContinuationToken;
use PHPUnit\Framework\TestCase;

final class BucketContinuationTokenTest extends TestCase
{
    public function test_marker_is_bound_to_account_and_filters(): void
    {
        $token = BucketContinuationToken::encode('account-a', 'reports-', 'us-east-1', 'reports-02');
        self::assertSame('reports-02', BucketContinuationToken::decode($token, 'account-a', 'reports-', 'us-east-1'));

        foreach ([
            ['account-b', 'reports-', 'us-east-1'],
            ['account-a', 'reports-0', 'us-east-1'],
            ['account-a', 'reports-', 'us-west-2'],
        ] as [$owner, $prefix, $region]) {
            try {
                BucketContinuationToken::decode($token, $owner, $prefix, $region);
                self::fail('A continuation token was accepted outside its original scope.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }
        }
    }

    public function test_malformed_tokens_are_rejected(): void
    {
        foreach (['', 'not-a-token', str_repeat('a', 1025)] as $token) {
            try {
                BucketContinuationToken::decode($token, 'account-a', null, null);
                self::fail('Malformed continuation token was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(400, $exception->getHttpStatus());
            }
        }
    }
}
