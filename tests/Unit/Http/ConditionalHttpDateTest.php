<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Http;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NotModifiedException;
use OpsFour\S3Server\Exception\PreconditionFailedException;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use OpsFour\S3Server\Http\CopySourceConditionEvaluator;
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
        $object = new ObjectInfo(
            'bucket',
            'key',
            1,
            '"etag"',
            versionId: $versionId,
            lastModified: new \DateTimeImmutable('2026-10-20T12:34:56+04:00'),
        );

        try {
            ConditionalHeaderEvaluator::evaluate($request, $object, $emitNullVersion);
            self::fail('Matching If-None-Match must return NotModified.');
        } catch (NotModifiedException $exception) {
            self::assertSame($expected, $exception->getExtraHeaders()['x-amz-version-id'] ?? null);
            self::assertSame('Tue, 20 Oct 2026 08:34:56 GMT', $exception->getExtraHeaders()['Last-Modified']);
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

    #[DataProvider('malformedEntityTags')]
    public function test_malformed_entity_tags_never_match(string $header): void
    {
        self::assertFalse(ConditionalHeaderEvaluator::etagMatches($header, '"etag"'));
        self::assertFalse(ConditionalHeaderEvaluator::etagMatches($header, '"etag"', allowWeak: true));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEntityTags(): iterable
    {
        yield 'unquoted' => ['etag'];
        yield 'missing closing quote' => ['"etag'];
        yield 'missing opening quote' => ['etag"'];
        yield 'extra quotes' => ['""etag""'];
        yield 'empty list member' => ['"other", , "etag"'];
        yield 'trailing comma' => ['"etag",'];
        yield 'wildcard in list' => ['"other", *'];
        yield 'space inside tag' => ['"eta g"'];
        yield 'lowercase weak marker' => ['w/"etag"'];
    }

    public function test_valid_entity_tag_lists_preserve_quotes_commas_and_weak_semantics(): void
    {
        self::assertTrue(ConditionalHeaderEvaluator::etagMatches(' "other" , "etag" ', '"etag"'));
        self::assertTrue(ConditionalHeaderEvaluator::etagMatches('"tag,part"', '"tag,part"'));
        self::assertFalse(ConditionalHeaderEvaluator::etagMatches('W/"etag"', '"etag"'));
        self::assertTrue(ConditionalHeaderEvaluator::etagMatches('W/"etag"', '"etag"', allowWeak: true));
        self::assertTrue(ConditionalHeaderEvaluator::etagMatches("\t* ", '"etag"'));
        self::assertFalse(ConditionalHeaderEvaluator::etagMatches('*, "etag"', '"etag"'));
    }

    public function test_repeated_if_none_match_lines_are_evaluated_as_one_list(): void
    {
        $request = $this->request(['If-None-Match' => ['"other"', '"etag"']]);

        $this->expectException(NotModifiedException::class);
        ConditionalHeaderEvaluator::evaluate($request, new ObjectInfo('bucket', 'key', 1, '"etag"'));
    }

    public function test_repeated_if_match_lines_are_evaluated_as_one_list(): void
    {
        $request = $this->request(['If-Match' => ['"other"', '"etag"']]);

        ConditionalHeaderEvaluator::evaluate($request, new ObjectInfo('bucket', 'key', 1, '"etag"'));
        self::addToAssertionCount(1);
    }

    public function test_repeated_copy_source_if_none_match_lines_are_evaluated_as_one_list(): void
    {
        $request = $this->request(['x-amz-copy-source-if-none-match' => ['"other"', '"etag"']]);

        $this->expectException(PreconditionFailedException::class);
        CopySourceConditionEvaluator::assertSatisfied($request, new ObjectInfo('bucket', 'key', 1, '"etag"'));
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
