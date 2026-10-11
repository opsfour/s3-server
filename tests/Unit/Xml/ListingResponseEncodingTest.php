<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Xml;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Xml\XmlResponseBuilder;
use PHPUnit\Framework\TestCase;

final class ListingResponseEncodingTest extends TestCase
{
    public function test_url_encoding_covers_every_documented_listing_key_field(): void
    {
        $v1 = XmlResponseBuilder::listObjectsResult([
            'name' => 'bucket',
            'prefix' => 'dir/*',
            'marker' => 'dir/*/before',
            'nextMarker' => 'dir/*/next',
            'maxKeys' => 1,
            'delimiter' => '/',
            'isTruncated' => true,
            'encodingType' => 'url',
            'objects' => [new ObjectInfo('bucket', 'dir/*/file(1)', 1, '"etag"')],
            'commonPrefixes' => ['dir/*/nested/'],
        ]);

        foreach ([
            '<Prefix>dir%2F%2A</Prefix>',
            '<Marker>dir%2F%2A%2Fbefore</Marker>',
            '<NextMarker>dir%2F%2A%2Fnext</NextMarker>',
            '<Delimiter>%2F</Delimiter>',
            '<Key>dir%2F%2A%2Ffile%281%29</Key>',
            '<Prefix>dir%2F%2A%2Fnested%2F</Prefix>',
        ] as $field) {
            self::assertStringContainsString($field, $v1);
        }

        $uploads = XmlResponseBuilder::listMultipartUploadsResult([
            'bucket' => 'bucket',
            'prefix' => 'dir/*',
            'delimiter' => '/',
            'keyMarker' => 'dir/*/before',
            'uploadIdMarker' => 'upload/id',
            'nextKeyMarker' => 'dir/*/next',
            'nextUploadIdMarker' => 'next/upload',
            'encodingType' => 'url',
            'uploads' => [[
                'key' => 'dir/*/file(1)',
                'uploadId' => 'upload/id',
                'initiated' => '2026-01-01T00:00:00Z',
            ]],
            'commonPrefixes' => ['dir/*/nested/'],
        ]);

        self::assertStringContainsString('<KeyMarker>dir%2F%2A%2Fbefore</KeyMarker>', $uploads);
        self::assertStringContainsString('<NextKeyMarker>dir%2F%2A%2Fnext</NextKeyMarker>', $uploads);
        self::assertStringContainsString('<UploadIdMarker>upload/id</UploadIdMarker>', $uploads);
        self::assertStringContainsString('<NextUploadIdMarker>next/upload</NextUploadIdMarker>', $uploads);
        self::assertStringContainsString('<Key>dir%2F%2A%2Ffile%281%29</Key>', $uploads);

        $versions = XmlResponseBuilder::listObjectVersionsResult([
            'name' => 'bucket',
            'prefix' => 'dir/*',
            'delimiter' => '/',
            'keyMarker' => 'dir/*/before',
            'versionIdMarker' => 'version/id',
            'nextKeyMarker' => 'dir/*/next',
            'nextVersionIdMarker' => 'next/version',
            'encodingType' => 'url',
            'versions' => [[
                'key' => 'dir/*/file(1)',
                'versionId' => 'version/id',
                'isLatest' => true,
                'lastModified' => '2026-01-01T00:00:00.000Z',
                'etag' => '"etag"',
                'size' => 1,
            ]],
            'commonPrefixes' => ['dir/*/nested/'],
        ]);

        self::assertStringContainsString('<KeyMarker>dir%2F%2A%2Fbefore</KeyMarker>', $versions);
        self::assertStringContainsString('<NextKeyMarker>dir%2F%2A%2Fnext</NextKeyMarker>', $versions);
        self::assertStringContainsString('<VersionIdMarker>version/id</VersionIdMarker>', $versions);
        self::assertStringContainsString('<NextVersionIdMarker>next/version</NextVersionIdMarker>', $versions);
        self::assertStringContainsString('<Key>dir%2F%2A%2Ffile%281%29</Key>', $versions);
    }
}
