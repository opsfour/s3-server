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
    ): string {
        $output = '';

        // Records message.
        if ($data !== '') {
            $output .= self::encodeMessage(
                [':message-type' => 'event', ':event-type' => 'Records', ':content-type' => 'application/octet-stream'],
                $data,
            );
        }

        // Stats message.
        $statsXml = <<<XML
        <Stats><BytesScanned>{$bytesScanned}</BytesScanned><BytesProcessed>{$bytesProcessed}</BytesProcessed><BytesReturned>{$bytesReturned}</BytesReturned></Stats>
        XML;
        $output .= self::encodeMessage(
            [':message-type' => 'event', ':event-type' => 'Stats', ':content-type' => 'text/xml'],
            $statsXml,
        );

        // End message.
        $output .= self::encodeMessage(
            [':message-type' => 'event', ':event-type' => 'End'],
            '',
        );

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
            $headerBytes .= chr(strlen($key));
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
