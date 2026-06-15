<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Exception;

use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Exception\S3Exception;
use PHPUnit\Framework\TestCase;

final class S3ExceptionTest extends TestCase
{
    public function test_no_such_bucket(): void
    {
        $e = new NoSuchBucketException;
        $this->assertSame('NoSuchBucket', $e->getErrorCode());
        $this->assertSame(404, $e->getHttpStatus());
        $this->assertInstanceOf(S3Exception::class, $e);
    }

    public function test_access_denied(): void
    {
        $e = new AccessDeniedException('Custom message');
        $this->assertSame('AccessDenied', $e->getErrorCode());
        $this->assertSame(403, $e->getHttpStatus());
        $this->assertSame('Custom message', $e->getMessage());
    }

    public function test_not_implemented(): void
    {
        $e = new NotImplementedException('Test op');
        $this->assertSame('NotImplemented', $e->getErrorCode());
        $this->assertSame(501, $e->getHttpStatus());
    }
}
