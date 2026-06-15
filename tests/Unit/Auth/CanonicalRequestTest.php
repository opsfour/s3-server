<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\CanonicalRequest;
use PHPUnit\Framework\TestCase;

final class CanonicalRequestTest extends TestCase
{
    public function test_build_basic_get_request(): void
    {
        $result = CanonicalRequest::build(
            method: 'GET',
            uri: '/',
            queryString: '',
            headers: ['host' => ['examplebucket.s3.amazonaws.com'], 'x-amz-date' => ['20130524T000000Z']],
            signedHeaders: ['host', 'x-amz-date'],
            hashedPayload: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        );

        $lines = explode("\n", $result);
        $this->assertSame('GET', $lines[0]);
        $this->assertSame('/', $lines[1]);
        $this->assertSame('', $lines[2]); // empty query string
        $this->assertStringContainsString('host:examplebucket.s3.amazonaws.com', $lines[3]);
    }

    public function test_build_with_query_string(): void
    {
        $result = CanonicalRequest::build(
            method: 'GET',
            uri: '/',
            queryString: 'prefix=photos&max-keys=10',
            headers: ['host' => ['example.com']],
            signedHeaders: ['host'],
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );

        $lines = explode("\n", $result);
        // Query params should be sorted alphabetically by key
        $this->assertStringContainsString('max-keys=10', $lines[2]);
        $this->assertStringContainsString('prefix=photos', $lines[2]);
    }
}
