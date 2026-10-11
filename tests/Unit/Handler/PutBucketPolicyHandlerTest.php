<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\MalformedPolicyException;
use OpsFour\S3Server\Handler\Bucket\PutBucketPolicyHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class PutBucketPolicyHandlerTest extends TestCase
{
    public function test_success_returns_ok_and_stores_policy(): void
    {
        $path = sys_get_temp_dir() . '/s3-put-bucket-policy-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $policy = '{"Statement":[{"Effect":"Deny","Principal":"*","Action":"s3:DeleteBucket","Resource":"arn:aws:s3:::bucket"}]}';

        try {
            $response = (new PutBucketPolicyHandler($metadata))->handleRequest($this->request($policy));

            self::assertSame(200, $response->getStatus());
            self::assertSame($policy, $metadata->getBucketPolicy('bucket'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_empty_body_returns_malformed_policy_without_replacing_policy(): void
    {
        $path = sys_get_temp_dir() . '/s3-empty-bucket-policy-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $existing = '{"Statement":[{"Effect":"Deny","Principal":"*","Action":"s3:DeleteBucket","Resource":"arn:aws:s3:::bucket"}]}';
        $metadata->putBucketPolicy('bucket', $existing);

        try {
            try {
                (new PutBucketPolicyHandler($metadata))->handleRequest($this->request(''));
                self::fail('An empty policy document must be rejected.');
            } catch (MalformedPolicyException) {
                self::assertSame($existing, $metadata->getBucketPolicy('bucket'));
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    private function request(string $body): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket?policy'),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
