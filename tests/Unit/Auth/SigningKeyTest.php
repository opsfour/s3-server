<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\SigningKey;
use PHPUnit\Framework\TestCase;

final class SigningKeyTest extends TestCase
{
    public function test_derive_matches_aws_test_vector(): void
    {
        // Official AWS SigV4 test vector
        $key = SigningKey::derive(
            secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            date: '20150830',
            region: 'us-east-1',
            service: 'iam',
        );

        $this->assertSame(
            'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9',
            bin2hex($key),
        );
    }
}
