<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Handler\Bucket\BucketOwnershipControlsHandler;
use OpsFour\S3Server\Handler\Bucket\PutPublicAccessBlockHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketConfigurationMalformedXmlTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function structurallyInvalidDocuments(): iterable
    {
        yield 'ownership missing rule' => [BucketOwnershipControlsHandler::class, 'parseMode', '<OwnershipControls/>'];
        yield 'ownership nested value' => [BucketOwnershipControlsHandler::class, 'parseMode', '<OwnershipControls><Rule><ObjectOwnership><Mode>ObjectWriter</Mode></ObjectOwnership></Rule></OwnershipControls>'];
        yield 'public access unknown field' => [PutPublicAccessBlockHandler::class, 'parsePublicAccessBlock', '<PublicAccessBlockConfiguration><Unknown>true</Unknown></PublicAccessBlockConfiguration>'];
        yield 'public access invalid boolean' => [PutPublicAccessBlockHandler::class, 'parsePublicAccessBlock', '<PublicAccessBlockConfiguration><BlockPublicAcls>yes</BlockPublicAcls></PublicAccessBlockConfiguration>'];
        yield 'public access uppercase boolean' => [PutPublicAccessBlockHandler::class, 'parsePublicAccessBlock', '<PublicAccessBlockConfiguration><BlockPublicAcls>TRUE</BlockPublicAcls></PublicAccessBlockConfiguration>'];
    }

    #[DataProvider('structurallyInvalidDocuments')]
    public function test_structural_errors_use_malformed_xml(string $class, string $methodName, string $xml): void
    {
        $method = new \ReflectionMethod($class, $methodName);

        $this->expectException(MalformedXmlException::class);
        $method->invoke(null, $xml);
    }
}
