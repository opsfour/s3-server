<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\S3ResponseHeaders;
use PHPUnit\Framework\TestCase;

final class S3ResponseHeadersOverrideTest extends TestCase
{
    public function test_object_and_restore_http_dates_are_normalized_to_utc(): void
    {
        $headers = S3ResponseHeaders::build(new ObjectInfo(
            'bucket',
            'key',
            1,
            '"etag"',
            restoreStatus: 'restored',
            restoreExpiresAt: new \DateTimeImmutable('2026-10-21T11:28:00+04:00'),
            lastModified: new \DateTimeImmutable('2026-10-20T12:34:56+04:00'),
        ), 1);

        self::assertSame('Tue, 20 Oct 2026 08:34:56 GMT', $headers['Last-Modified']);
        self::assertSame(
            'ongoing-request="false", expiry-date="Wed, 21 Oct 2026 07:28:00 GMT"',
            $headers['x-amz-restore'],
        );
    }

    public function test_only_six_supported_headers_can_be_overridden(): void
    {
        $headers = S3ResponseHeaders::withAuthenticatedOverrides(
            ['ETag' => '"etag"', 'Content-Length' => '3'],
            [
                'response-content-type' => 'text/html',
                'response-content-language' => 'de',
                'response-content-disposition' => 'attachment',
                'response-content-encoding' => 'gzip',
                'response-cache-control' => 'no-store',
                'response-expires' => 'Wed, 21 Oct 2026 07:28:00 GMT',
                'response-content-length' => '999',
                'response-etag' => '"fake"',
            ],
            true,
        );

        self::assertSame('"etag"', $headers['ETag']);
        self::assertSame('3', $headers['Content-Length']);
        self::assertSame('text/html', $headers['Content-Type']);
        self::assertSame('de', $headers['Content-Language']);
        self::assertSame('attachment', $headers['Content-Disposition']);
        self::assertSame('gzip', $headers['Content-Encoding']);
        self::assertSame('no-store', $headers['Cache-Control']);
        self::assertSame('Wed, 21 Oct 2026 07:28:00 GMT', $headers['Expires']);
        self::assertCount(8, $headers);
    }

    public function test_anonymous_requests_cannot_apply_overrides(): void
    {
        self::assertSame(
            ['Content-Type' => 'text/plain'],
            S3ResponseHeaders::withAuthenticatedOverrides(
                ['Content-Type' => 'text/plain'],
                ['response-content-type' => 'text/html'],
                false,
            ),
        );
    }

    public function test_signed_override_rejects_control_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        S3ResponseHeaders::withAuthenticatedOverrides(
            ['Content-Type' => 'text/plain'],
            ['response-content-disposition' => "attachment\r\nX-Injected: yes"],
            true,
        );
    }
}
