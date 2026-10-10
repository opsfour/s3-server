<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

final class StandaloneBucketXmlNamespaceTest extends S3FunctionalTestCase
{
    private static string $source = 'standalone-xml-source';

    private static string $target = 'standalone-xml-target';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$s3->createBucket(['Bucket' => self::$source]);
        self::$s3->createBucket(['Bucket' => self::$target]);
    }

    public function test_logging_rejects_foreign_namespaces_without_disabling_configuration(): void
    {
        $status = ['LoggingEnabled' => ['TargetBucket' => self::$target, 'TargetPrefix' => 'original/']];
        self::$s3->putBucketLogging(['Bucket' => self::$source, 'BucketLoggingStatus' => $status]);

        try {
            foreach ([
                '<BucketLoggingStatus xmlns="urn:unexpected"/>',
                '<BucketLoggingStatus xmlns:x="urn:unexpected"><x:LoggingEnabled/></BucketLoggingStatus>',
                '<BucketLoggingStatus>disable logging</BucketLoggingStatus>',
                '<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$target . '</TargetBucket><TargetPrefix>new/</TargetPrefix>ignored</LoggingEnabled></BucketLoggingStatus>',
                '<BucketLoggingStatus xmlns:x="urn:unexpected"><LoggingEnabled><TargetBucket>' . self::$target . '</TargetBucket><TargetPrefix>new/</TargetPrefix><x:TargetPrefix>ignored/</x:TargetPrefix></LoggingEnabled></BucketLoggingStatus>',
                '<BucketLoggingStatus xmlns:x="urn:unexpected"><LoggingEnabled><TargetBucket>' . self::$target . '</TargetBucket><TargetPrefix>new/<x:Suffix>ignored</x:Suffix></TargetPrefix></LoggingEnabled></BucketLoggingStatus>',
            ] as $xml) {
                $response = $this->sendRaw('PutBucketLogging', ['BucketLoggingStatus' => $status], $xml);
                self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
                self::assertSame('original/', self::$s3->getBucketLogging(['Bucket' => self::$source])['LoggingEnabled']['TargetPrefix']);
            }

            $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
            $valid = '<s3:BucketLoggingStatus xmlns:s3="' . $namespace . '"><s3:LoggingEnabled>'
                . '<s3:TargetBucket>' . self::$target . '</s3:TargetBucket><s3:TargetPrefix>prefixed/</s3:TargetPrefix>'
                . '</s3:LoggingEnabled></s3:BucketLoggingStatus>';
            self::assertSame(200, $this->sendRaw('PutBucketLogging', ['BucketLoggingStatus' => $status], $valid)->getStatusCode());
            self::assertSame('prefixed/', self::$s3->getBucketLogging(['Bucket' => self::$source])['LoggingEnabled']['TargetPrefix']);

            $plain = '<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$target
                . '</TargetBucket><TargetPrefix>plain/</TargetPrefix></LoggingEnabled></BucketLoggingStatus>';
            self::assertSame(200, $this->sendRaw('PutBucketLogging', ['BucketLoggingStatus' => $status], $plain)->getStatusCode());
            self::assertSame('plain/', self::$s3->getBucketLogging(['Bucket' => self::$source])['LoggingEnabled']['TargetPrefix']);
        } finally {
            self::$s3->putBucketLogging(['Bucket' => self::$source, 'BucketLoggingStatus' => []]);
        }
    }

    public function test_public_access_block_rejects_foreign_documents_without_relaxing_protection(): void
    {
        $protected = [
            'BlockPublicAcls' => true,
            'IgnorePublicAcls' => true,
            'BlockPublicPolicy' => true,
            'RestrictPublicBuckets' => true,
        ];
        self::$s3->putPublicAccessBlock(['Bucket' => self::$source, 'PublicAccessBlockConfiguration' => $protected]);

        try {
            foreach ([
                '<PublicAccessBlockConfiguration xmlns="urn:unexpected"><BlockPublicAcls>false</BlockPublicAcls></PublicAccessBlockConfiguration>',
                '<PublicAccessBlockConfiguration xmlns:x="urn:unexpected"><x:BlockPublicAcls>false</x:BlockPublicAcls></PublicAccessBlockConfiguration>',
                '<PublicAccessBlockConfiguration xmlns:x="urn:unexpected"><BlockPublicAcls>true</BlockPublicAcls><x:IgnorePublicAcls>false</x:IgnorePublicAcls></PublicAccessBlockConfiguration>',
            ] as $xml) {
                $response = $this->sendRaw('PutPublicAccessBlock', ['PublicAccessBlockConfiguration' => $protected], $xml);
                self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
                self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $response->getBody());
                self::assertSame($protected, self::$s3->getPublicAccessBlock(['Bucket' => self::$source])['PublicAccessBlockConfiguration']);
            }

            $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
            $valid = '<s3:PublicAccessBlockConfiguration xmlns:s3="' . $namespace . '">'
                . '<s3:BlockPublicAcls>true</s3:BlockPublicAcls><s3:IgnorePublicAcls>true</s3:IgnorePublicAcls>'
                . '<s3:BlockPublicPolicy>true</s3:BlockPublicPolicy><s3:RestrictPublicBuckets>true</s3:RestrictPublicBuckets>'
                . '</s3:PublicAccessBlockConfiguration>';
            self::assertSame(200, $this->sendRaw('PutPublicAccessBlock', ['PublicAccessBlockConfiguration' => $protected], $valid)->getStatusCode());
            self::assertSame($protected, self::$s3->getPublicAccessBlock(['Bucket' => self::$source])['PublicAccessBlockConfiguration']);

            $plain = '<PublicAccessBlockConfiguration><BlockPublicAcls>true</BlockPublicAcls>'
                . '<IgnorePublicAcls>true</IgnorePublicAcls><BlockPublicPolicy>true</BlockPublicPolicy>'
                . '<RestrictPublicBuckets>true</RestrictPublicBuckets></PublicAccessBlockConfiguration>';
            self::assertSame(200, $this->sendRaw('PutPublicAccessBlock', ['PublicAccessBlockConfiguration' => $protected], $plain)->getStatusCode());
            self::assertSame($protected, self::$s3->getPublicAccessBlock(['Bucket' => self::$source])['PublicAccessBlockConfiguration']);

            // AWS permits omitted Boolean fields; a valid partial PUT replaces
            // the configuration and omitted fields default to false.
            $partial = '<PublicAccessBlockConfiguration><BlockPublicAcls>true</BlockPublicAcls></PublicAccessBlockConfiguration>';
            self::assertSame(200, $this->sendRaw('PutPublicAccessBlock', ['PublicAccessBlockConfiguration' => $protected], $partial)->getStatusCode());
            self::assertSame([
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => false,
            ], self::$s3->getPublicAccessBlock(['Bucket' => self::$source])['PublicAccessBlockConfiguration']);
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$source]);
        }
    }

    public function test_ownership_controls_reject_foreign_fields_without_changing_mode(): void
    {
        $controls = ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]];
        self::assertSame('ObjectWriter', self::$s3->getBucketOwnershipControls(['Bucket' => self::$source])['OwnershipControls']['Rules'][0]['ObjectOwnership']);

        try {
            foreach ([
                '<OwnershipControls/>',
                '<OwnershipControls><Rule><ObjectOwnership>ObjectWriter</ObjectOwnership></Rule><Rule><ObjectOwnership>BucketOwnerEnforced</ObjectOwnership></Rule></OwnershipControls>',
                '<OwnershipControls><Unknown/></OwnershipControls>',
                '<OwnershipControls><Rule><Unknown>ObjectWriter</Unknown></Rule></OwnershipControls>',
                '<OwnershipControls><Rule><ObjectOwnership><Mode>ObjectWriter</Mode></ObjectOwnership></Rule></OwnershipControls>',
                '<OwnershipControls><Rule><ObjectOwnership>InvalidMode</ObjectOwnership></Rule></OwnershipControls>',
                '<OwnershipControls xmlns="urn:unexpected"><Rule><ObjectOwnership>BucketOwnerEnforced</ObjectOwnership></Rule></OwnershipControls>',
                '<OwnershipControls xmlns:x="urn:unexpected"><Rule><ObjectOwnership>BucketOwnerEnforced</ObjectOwnership></Rule><x:Rule><x:ObjectOwnership>ObjectWriter</x:ObjectOwnership></x:Rule></OwnershipControls>',
                '<OwnershipControls xmlns:x="urn:unexpected"><x:Rule><x:ObjectOwnership>BucketOwnerEnforced</x:ObjectOwnership></x:Rule></OwnershipControls>',
                '<OwnershipControls xmlns:x="urn:unexpected"><Rule><x:ObjectOwnership>BucketOwnerEnforced</x:ObjectOwnership></Rule></OwnershipControls>',
            ] as $xml) {
                $response = $this->sendRaw('PutBucketOwnershipControls', ['OwnershipControls' => $controls], $xml);
                self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
                self::assertSame('ObjectWriter', self::$s3->getBucketOwnershipControls(['Bucket' => self::$source])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
            }

            $unsupported = '<OwnershipControls><Rule><ObjectOwnership>BucketOwnerPreferred</ObjectOwnership></Rule></OwnershipControls>';
            self::assertSame(501, $this->sendRaw('PutBucketOwnershipControls', ['OwnershipControls' => $controls], $unsupported)->getStatusCode());
            self::assertSame('ObjectWriter', self::$s3->getBucketOwnershipControls(['Bucket' => self::$source])['OwnershipControls']['Rules'][0]['ObjectOwnership']);

            $malformed = $this->sendRaw('PutBucketOwnershipControls', ['OwnershipControls' => $controls], '<OwnershipControls/>');
            self::assertSame(400, $malformed->getStatusCode());
            self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $malformed->getBody());

            $plain = '<OwnershipControls><Rule><ObjectOwnership>ObjectWriter</ObjectOwnership></Rule></OwnershipControls>';
            self::assertSame(200, $this->sendRaw('PutBucketOwnershipControls', ['OwnershipControls' => $controls], $plain)->getStatusCode());

            $namespace = 'http://s3.amazonaws.com/doc/2006-03-01/';
            $valid = '<s3:OwnershipControls xmlns:s3="' . $namespace . '"><s3:Rule>'
                . '<s3:ObjectOwnership>BucketOwnerEnforced</s3:ObjectOwnership></s3:Rule></s3:OwnershipControls>';
            self::assertSame(200, $this->sendRaw('PutBucketOwnershipControls', ['OwnershipControls' => $controls], $valid)->getStatusCode());
            self::assertSame('BucketOwnerEnforced', self::$s3->getBucketOwnershipControls(['Bucket' => self::$source])['OwnershipControls']['Rules'][0]['ObjectOwnership']);
        } finally {
            self::$s3->deleteBucketOwnershipControls(['Bucket' => self::$source]);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function sendRaw(string $operation, array $parameters, string $xml): ResponseInterface
    {
        $command = self::$s3->getCommand($operation, ['Bucket' => self::$source] + $parameters + [
            'ContentMD5' => base64_encode(md5($xml, true)),
        ]);
        $signed = self::$s3->createPresignedRequest($command, '+5 minutes');

        return (new Client(['http_errors' => false, 'timeout' => 5]))->request('PUT', (string) $signed->getUri(), [
            'body' => $xml,
            'headers' => $signed->getHeaders(),
        ]);
    }
}
