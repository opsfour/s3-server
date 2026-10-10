<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Select;

use Aws\Api\Parser\DecodingEventStreamIterator;
use GuzzleHttp\Psr7\Utils;
use OpsFour\S3Server\Select\EventStreamEncoder;
use PHPUnit\Framework\TestCase;

final class EventStreamEncoderTest extends TestCase
{
    public function test_large_output_is_split_into_sdk_decodable_records_frames(): void
    {
        $records = str_repeat('quoted,"partial record"\n', 10_000);
        $wire = EventStreamEncoder::encode($records, 17, 17, strlen($records));
        $decodedRecords = '';
        $recordFrames = 0;
        $events = [];

        foreach (new DecodingEventStreamIterator(Utils::streamFor($wire)) as $event) {
            $type = $event['headers'][':event-type'];
            $events[] = $type;
            if ($type === 'Records') {
                ++$recordFrames;
                $payload = (string) $event['payload'];
                self::assertLessThanOrEqual(64 * 1024, strlen($payload));
                $decodedRecords .= $payload;
            } elseif ($type === 'Stats') {
                self::assertStringContainsString('<BytesReturned>' . strlen($records) . '</BytesReturned>', (string) $event['payload']);
            }
        }

        self::assertGreaterThan(1, $recordFrames);
        self::assertSame($records, $decodedRecords);
        self::assertSame(['Stats', 'End'], array_slice($events, -2));
    }

    public function test_maximum_header_lengths_are_encoded_without_truncation(): void
    {
        $message = EventStreamEncoder::encodeMessage([str_repeat('k', 255) => str_repeat('v', 65_535)], '');
        $valueLength = unpack('n', substr($message, 269, 2));
        $messageLength = unpack('N', substr($message, 0, 4));

        $this->assertSame(255, ord($message[12]));
        $this->assertIsArray($valueLength);
        $this->assertIsArray($messageLength);
        $this->assertSame(65_535, $valueLength[1]);
        $this->assertSame(strlen($message), $messageLength[1]);
    }

    public function test_oversized_header_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EventStreamEncoder::encodeMessage([str_repeat('k', 256) => 'value'], '');
    }

    public function test_oversized_header_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EventStreamEncoder::encodeMessage(['key' => str_repeat('v', 65_536)], '');
    }
}
