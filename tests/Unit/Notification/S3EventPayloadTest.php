<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Notification;

use OpsFour\S3Server\Notification\S3EventPayload;
use PHPUnit\Framework\TestCase;

final class S3EventPayloadTest extends TestCase
{
    public function test_delete_and_restore_events_omit_unknown_object_properties(): void
    {
        foreach (['s3:ObjectRemoved:Delete', 's3:ObjectRemoved:DeleteMarkerCreated', 's3:ObjectRestore:Completed'] as $eventName) {
            $record = json_decode(S3EventPayload::build($eventName, 'bucket', 'a key'), true, flags: JSON_THROW_ON_ERROR)['Records'][0];

            self::assertSame(urlencode('a key'), $record['s3']['object']['key']);
            self::assertArrayNotHasKey('size', $record['s3']['object']);
            self::assertArrayNotHasKey('eTag', $record['s3']['object']);
        }
    }

    public function test_zero_byte_create_event_keeps_its_real_size_and_etag(): void
    {
        $record = json_decode(S3EventPayload::build(
            's3:ObjectCreated:Put',
            'bucket',
            'empty',
            0,
            '"d41d8cd98f00b204e9800998ecf8427e"',
        ), true, flags: JSON_THROW_ON_ERROR)['Records'][0];

        self::assertSame(0, $record['s3']['object']['size']);
        self::assertSame('d41d8cd98f00b204e9800998ecf8427e', $record['s3']['object']['eTag']);
    }
}
