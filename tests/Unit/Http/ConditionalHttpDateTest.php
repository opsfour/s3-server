<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NotModifiedException;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConditionalHttpDateTest extends TestCase
{
    /** @return iterable<string, array{?string, bool, ?string}> */
    public static function versionHeaders(): iterable
    {
        yield 'numbered version' => ['version-1', false, 'version-1'];
        yield 'suspended null version' => [null, true, 'null'];
        yield 'never versioned' => [null, false, null];
    }

    #[DataProvider('versionHeaders')]
    public function test_not_modified_response_preserves_selected_version(?string $versionId, bool $emitNullVersion, ?string $expected): void
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
            ['If-None-Match' => '"etag"'],
        );
        $object = new ObjectInfo('bucket', 'key', 1, '"etag"', versionId: $versionId);

        try {
            ConditionalHeaderEvaluator::evaluate($request, $object, $emitNullVersion);
            self::fail('Matching If-None-Match must return NotModified.');
        } catch (NotModifiedException $exception) {
            self::assertSame($expected, $exception->getExtraHeaders()['x-amz-version-id'] ?? null);
        }
    }

    public function test_accepts_all_three_http_date_formats(): void
    {
        $expected = strtotime('1994-11-06 08:49:37 UTC');
        self::assertIsInt($expected);

        foreach ([
            'Sun, 06 Nov 1994 08:49:37 GMT',
            'Sunday, 06-Nov-94 08:49:37 GMT',
            'Sun Nov  6 08:49:37 1994',
        ] as $date) {
            self::assertSame($expected, ConditionalHeaderEvaluator::parseHttpDate($date), $date);
        }
    }

    public function test_rejects_relative_and_normalized_invalid_dates(): void
    {
        foreach ([
            'tomorrow',
            'yesterday',
            'Sun, 32 Nov 1994 08:49:37 GMT',
            'Mon, 06 Nov 1994 08:49:37 GMT',
            'Sun, 06 Nov 1994 08:49:37 UTC',
        ] as $date) {
            self::assertNull(ConditionalHeaderEvaluator::parseHttpDate($date), $date);
        }
    }
}
