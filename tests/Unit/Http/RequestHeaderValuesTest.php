<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\RequestHeaderValues;
use PHPUnit\Framework\TestCase;

final class RequestHeaderValuesTest extends TestCase
{
    public function test_combined_preserves_every_field_line(): void
    {
        $request = $this->request(['If-Match' => ['"first"', '"second"']]);

        self::assertSame('"first", "second"', RequestHeaderValues::combined($request, 'if-match'));
    }

    public function test_singleton_rejects_repeated_field_lines(): void
    {
        $request = $this->request(['x-amz-copy-source' => ['/bucket/first', '/bucket/second']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('x-amz-copy-source must be supplied at most once.');
        RequestHeaderValues::singleton($request, 'x-amz-copy-source');
    }

    /** @param array<non-empty-string, string|list<string>> $headers */
    private function request(array $headers): Request
    {
        return new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
    }
}
