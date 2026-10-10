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

    public function test_duplicate_header_value_order_changes_canonical_request(): void
    {
        $first = CanonicalRequest::build(
            method: 'PUT',
            uri: '/bucket/key',
            queryString: '',
            headers: ['host' => ['example.com'], 'x-amz-acl' => ['private', 'public-read']],
            signedHeaders: ['host', 'x-amz-acl'],
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );
        $reordered = CanonicalRequest::build(
            method: 'PUT',
            uri: '/bucket/key',
            queryString: '',
            headers: ['host' => ['example.com'], 'x-amz-acl' => ['public-read', 'private']],
            signedHeaders: ['host', 'x-amz-acl'],
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );

        self::assertNotSame($first, $reordered);
        self::assertStringContainsString("x-amz-acl:private,public-read\n", $first);
    }

    public function test_object_attributes_header_values_are_order_insensitive_for_sdk_sigv4(): void
    {
        $attributes = ['ETag', 'ObjectSize', 'StorageClass', 'Checksum'];
        $request = CanonicalRequest::build(
            method: 'GET',
            uri: '/bucket/key',
            queryString: 'attributes',
            headers: ['host' => ['example.com'], 'x-amz-object-attributes' => $attributes],
            signedHeaders: ['host', 'x-amz-object-attributes'],
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );
        $reordered = CanonicalRequest::build(
            method: 'GET',
            uri: '/bucket/key',
            queryString: 'attributes',
            headers: ['host' => ['example.com'], 'x-amz-object-attributes' => array_reverse($attributes)],
            signedHeaders: ['host', 'x-amz-object-attributes'],
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );

        self::assertSame($request, $reordered);
        self::assertStringContainsString("x-amz-object-attributes:Checksum,ETag,ObjectSize,StorageClass\n", $request);
    }
}
