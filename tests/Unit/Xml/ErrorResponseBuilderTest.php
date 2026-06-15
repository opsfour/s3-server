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
        );

        $this->assertStringContainsString('<Code>NoSuchBucket</Code>', $xml);
        $this->assertStringContainsString('<Message>The specified bucket does not exist.</Message>', $xml);
        $this->assertStringContainsString('<Resource>/mybucket</Resource>', $xml);
        $this->assertStringContainsString('<RequestId>req-123</RequestId>', $xml);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
    }
}
