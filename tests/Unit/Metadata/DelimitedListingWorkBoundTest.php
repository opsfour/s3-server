<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\DelimitedMultipartListing;
use OpsFour\S3Server\Metadata\DelimitedObjectListing;
use OpsFour\S3Server\Metadata\DelimitedVersionListing;
use OpsFour\S3Server\Metadata\ListingPrefixRange;
use PHPUnit\Framework\TestCase;

final class DelimitedListingWorkBoundTest extends TestCase
{
    public function test_object_listing_seeks_past_a_large_common_prefix_and_uses_the_prefix_token(): void
    {
        $calls = [];
        $page = DelimitedObjectListing::page(
            fetchRows: function (?string $cursor, int $limit, ?string $afterPrefix) use (&$calls): array {
                $calls[] = [$cursor, $limit, $afterPrefix];

                if ($afterPrefix === 'group/a/') {
                    return [['key_name' => 'group/b/item']];
                }

                return array_map(
                    static fn(int $number): array => ['key_name' => sprintf('group/a/%06d', $number)],
                    range(1, $limit),
                );
            },
            toObjectInfo: static fn(array $row): ObjectInfo => self::object((string) $row['key_name']),
            bucket: 'bucket',
            prefix: 'group/',
            delimiter: '/',
            maxKeys: 1,
            startAfter: null,
            continuationToken: null,
            effectiveStartAfter: null,
        );

        self::assertSame(['group/a/'], $page->commonPrefixes);
        self::assertTrue($page->isTruncated);
        self::assertSame(base64_encode('group/a/'), $page->nextContinuationToken);
        self::assertSame([[null, 512, null], [null, 512, 'group/a/']], $calls);
    }

    public function test_version_listing_seeks_past_a_large_common_prefix_without_a_version_marker(): void
    {
        $calls = [];
        $page = DelimitedVersionListing::page(
            fetchRows: function (?array $cursor, int $limit, ?string $afterPrefix) use (&$calls): array {
                $calls[] = [$cursor, $limit, $afterPrefix];

                if ($afterPrefix === 'group/a/') {
                    return [$this->versionRow('group/b/item', 1000)];
                }

                return array_map(
                    fn(int $number): array => $this->versionRow(sprintf('group/a/%06d', $number), $number),
                    range(1, $limit),
                );
            },
            toObjectInfo: static fn(array $row): ObjectInfo => self::object((string) $row['key_name']),
            prefix: 'group/',
            delimiter: '/',
            maxKeys: 1,
            keyMarker: null,
        );

        self::assertSame(['group/a/'], $page['commonPrefixes']);
        self::assertTrue($page['isTruncated']);
        self::assertSame('group/a/', $page['nextKeyMarker']);
        self::assertNull($page['nextVersionIdMarker']);
        self::assertSame([[null, 512, null], [null, 512, 'group/a/']], $calls);
    }

    public function test_multipart_listing_seeks_past_a_large_common_prefix_without_an_upload_marker(): void
    {
        $calls = [];
        $page = DelimitedMultipartListing::page(
            fetchRows: function (?array $cursor, int $limit, ?string $afterPrefix) use (&$calls): array {
                $calls[] = [$cursor, $limit, $afterPrefix];

                if ($afterPrefix === 'group/a/') {
                    return [$this->uploadRow('group/b/item', 'next')];
                }

                return array_map(
                    fn(int $number): array => $this->uploadRow(sprintf('group/a/%06d', $number), 'upload-' . $number),
                    range(1, $limit),
                );
            },
            prefix: 'group/',
            delimiter: '/',
            maxUploads: 1,
            keyMarker: null,
        );

        self::assertSame(['group/a/'], $page['commonPrefixes']);
        self::assertTrue($page['isTruncated']);
        self::assertSame('group/a/', $page['nextKeyMarker']);
        self::assertNull($page['nextUploadIdMarker']);
        self::assertSame([[null, 512, null], [null, 512, 'group/a/']], $calls);
    }

    public function test_prefix_upper_bound_preserves_utf8_byte_order(): void
    {
        self::assertSame('group/b', ListingPrefixRange::exclusiveUpperBound('group/a'));
        self::assertSame("group/\u{E000}", ListingPrefixRange::exclusiveUpperBound("group/\u{D7FF}"));
        self::assertSame('group0', ListingPrefixRange::exclusiveUpperBound("group/\u{10FFFF}"));
        self::assertNull(ListingPrefixRange::exclusiveUpperBound("\u{10FFFF}"));
    }

    /** @return array<string, mixed> */
    private function versionRow(string $key, int $id): array
    {
        return [
            'key_name' => $key,
            'version_id' => 'version-' . $id,
            'is_latest' => 1,
            'is_delete_marker' => 0,
            'created_at' => '2026-01-01T00:00:00Z',
            'id' => $id,
        ];
    }

    /** @return array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, created_at: string} */
    private function uploadRow(string $key, string $uploadId): array
    {
        return [
            'upload_id' => $uploadId,
            'bucket' => 'bucket',
            'key_name' => $key,
            'owner_id' => 'owner',
            'content_type' => 'application/octet-stream',
            'created_at' => '2026-01-01T00:00:00Z',
        ];
    }

    private static function object(string $key): ObjectInfo
    {
        return new ObjectInfo(
            bucket: 'bucket',
            key: $key,
            ownerId: 'owner',
            etag: '"etag"',
            size: 1,
            contentType: 'application/octet-stream',
        );
    }
}
