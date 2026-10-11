<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\CancelledException;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class AclCancellationTest extends TestCase
{
    public function test_acl_lookup_cancellation_is_not_treated_as_an_empty_acl(): void
    {
        $cancellation = new CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucketOwnershipControls')->with('bucket')->willReturn(null);
        $metadata->method('getObjectMetadata')->with('bucket', 'key')->willReturn(null);
        $metadata->expects(self::once())->method('getAcl')
            ->with('object', 'bucket/key?versionId=null')
            ->willThrowException($cancellation);
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://localhost/bucket/key'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('s3.cachedBucketOwner', 'owner');
        $request->setAttribute('ownerId', 'reader');
        $terminal = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204);
            }
        };

        try {
            (new AclEnforcementMiddleware($metadata))->handleRequest($request, $terminal);
            self::fail('Cancellation must leave the ACL boundary unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }
}
