<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Bucket\GetBucketPolicyStatusHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class GetBucketPolicyStatusHandlerTest extends TestCase
{
    public function test_bucket_without_policy_has_non_public_status(): void
    {
        $path = sys_get_temp_dir() . '/s3-policy-status-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket?policyStatus'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        try {
            $response = (new GetBucketPolicyStatusHandler($metadata))->handleRequest($request);
            self::assertSame(200, $response->getStatus());
            $xml = new \SimpleXMLElement(ByteStream\buffer($response->getBody()));
            self::assertSame('false', (string) $xml->IsPublic);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
