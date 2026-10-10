<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Encodes S3 Select results in the AWS event stream binary format.
 *
 * Message format:
 * - Prelude: total_length (4 bytes) + headers_length (4 bytes) + prelude_crc (4 bytes)
 * - Headers: key-value pairs
 * - Payload: message body
 * - Message CRC: (4 bytes)
 */
final class EventStreamEncoder
{
    /** Keep individual Records frames small enough for incremental SDK decoding. */
    private const int RECORD_PAYLOAD_BYTES = 64 * 1024;

    /**
     * Encode a complete S3 Select response as event stream messages.
     *
     * @param string $data The result data.
     * @param int $bytesScanned Bytes scanned.
     * @param int $bytesProcessed Bytes processed.
     * @param int $bytesReturned Bytes returned.
     * @return string Binary event stream.
     */
    public static function encode(
        string $data,
        int $bytesScanned = 0,
        int $bytesProcessed = 0,
        int $bytesReturned = 0,
        ?SelectMemoryGuard $memoryGuard = null,
    ): string {
        $output = '';

        // AWS permits partial records across Records frames. Bound each frame
        // rather than sending a potentially hundreds-of-megabytes-long message.
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += self::RECORD_PAYLOAD_BYTES) {
            $frame = self::encodeMessage(
                [':message-type' => 'event', ':event-type' => 'Records', ':content-type' => 'application/octet-stream'],
                substr($data, $offset, self::RECORD_PAYLOAD_BYTES),
            );
            $memoryGuard?->assertOutputSize(strlen($output), strlen($frame));
            $output .= $frame;
            $memoryGuard?->check();
        }

        // Stats message.
        $statsXml = <<<XML
        <Stats><BytesScanned>{$bytesScanned}</BytesScanned><BytesProcessed>{$bytesProcessed}</BytesProcessed><BytesReturned>{$bytesReturned}</BytesReturned></Stats>
        XML;
        $stats = self::encodeMessage(
            [':message-type' => 'event', ':event-type' => 'Stats', ':content-type' => 'text/xml'],
            $statsXml,
        );
        $memoryGuard?->assertOutputSize(strlen($output), strlen($stats));
        $output .= $stats;

        // End message.
        $end = self::encodeMessage(
            [':message-type' => 'event', ':event-type' => 'End'],
            '',
        );
        $memoryGuard?->assertOutputSize(strlen($output), strlen($end));
        $output .= $end;

        return $output;
    }

    /**
     * Encode a single event stream message.
     *
     * @param array<string, string> $headers Header key-value pairs.
     * @param string $payload Message payload.
     * @return string Binary encoded message.
     */
    public static function encodeMessage(array $headers, string $payload): string
    {
        // Encode headers.
        $headerBytes = '';
        foreach ($headers as $key => $value) {
            if (strlen($key) > 255 || strlen($value) > 65_535) {
                throw new \InvalidArgumentException('Event stream header exceeds its encoded length limit.');
            }

            $headerBytes .= pack('C', strlen($key));
            $headerBytes .= $key;
            $headerBytes .= chr(7); // String type.
            $headerBytes .= pack('n', strlen($value));
            $headerBytes .= $value;
        }

        $headersLength = strlen($headerBytes);
        $payloadLength = strlen($payload);
        $totalLength = 4 + 4 + 4 + $headersLength + $payloadLength + 4; // prelude + prelude_crc + headers + payload + message_crc

        // Prelude: total_length + headers_length.
        $prelude = pack('N', $totalLength) . pack('N', $headersLength);

        // Prelude CRC (mask to 32-bit unsigned for portability on 64-bit systems).
        $preludeCrc = pack('N', crc32($prelude) & 0xFFFFFFFF);

        // Full message without final CRC.
        $message = $prelude . $preludeCrc . $headerBytes . $payload;

        // Message CRC (mask to 32-bit unsigned for portability on 64-bit systems).
        $messageCrc = pack('N', crc32($message) & 0xFFFFFFFF);

        return $message . $messageCrc;
    }
}
