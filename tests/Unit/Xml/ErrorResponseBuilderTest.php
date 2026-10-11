<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Xml\ErrorResponseBuilder;
use PHPUnit\Framework\TestCase;

final class ErrorResponseBuilderTest extends TestCase
{
    public function test_build_error_xml(): void
    {
        $xml = ErrorResponseBuilder::build(
            code: 'NoSuchBucket',
            message: 'The specified bucket does not exist.',
            resource: '/mybucket',
            requestId: 'req-123',
            hostId: 'host-456',
        );

        $this->assertStringContainsString('<Code>NoSuchBucket</Code>', $xml);
        $this->assertStringContainsString('<Message>The specified bucket does not exist.</Message>', $xml);
        $this->assertStringContainsString('<Resource>/mybucket</Resource>', $xml);
        $this->assertStringContainsString('<RequestId>req-123</RequestId>', $xml);
        $this->assertStringContainsString('<HostId>host-456</HostId>', $xml);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
    }

    public function test_build_always_returns_well_formed_xml_for_invalid_input_bytes(): void
    {
        $xml = ErrorResponseBuilder::build(
            code: "Invalid\x01Code",
            message: "Invalid \xFF message",
            resource: '/bucket/<key>',
            requestId: 'req&123',
            hostId: 'host<456',
        );

        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('<Resource>/bucket/&lt;key&gt;</Resource>', $xml);
        self::assertStringContainsString('<RequestId>req&amp;123</RequestId>', $xml);
        self::assertStringContainsString('<HostId>host&lt;456</HostId>', $xml);
    }
}
