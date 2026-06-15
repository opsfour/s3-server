<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

/**
 * Builds standard AWS S3 event notification JSON payloads.
 */
final class S3EventPayload
{
    /**
     * Build an S3 event notification payload.
     *
     * @param string $eventName e.g., 's3:ObjectCreated:Put'
     * @param string $bucket The bucket name.
     * @param string $key The object key.
     * @param int $size The object size.
     * @param string $etag The object ETag.
     * @param string $ownerId The request principal.
     * @param string $region The server region.
     * @return string JSON-encoded event payload.
     */
    public static function build(
        string $eventName,
        string $bucket,
        string $key,
        int $size = 0,
        string $etag = '',
        string $ownerId = '',
        string $region = 'us-east-1',
    ): string {
        $payload = [
            'Records' => [
                [
                    'eventVersion' => '2.1',
                    'eventSource' => 'aws:s3',
                    'awsRegion' => $region,
                    'eventTime' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.000\Z'),
                    'eventName' => $eventName,
                    'userIdentity' => [
                        'principalId' => $ownerId,
                    ],
                    'requestParameters' => [
                        'sourceIPAddress' => '127.0.0.1',
                    ],
                    'responseElements' => [
                        'x-amz-request-id' => bin2hex(random_bytes(8)),
                        'x-amz-id-2' => base64_encode(random_bytes(16)),
                    ],
                    's3' => [
                        's3SchemaVersion' => '1.0',
                        'configurationId' => '',
                        'bucket' => [
                            'name' => $bucket,
                            'ownerIdentity' => [
                                'principalId' => $ownerId,
                            ],
                            'arn' => "arn:aws:s3:::{$bucket}",
                        ],
                        'object' => [
                            'key' => rawurlencode($key),
                            'size' => $size,
                            'eTag' => trim($etag, '"'),
                            'sequencer' => bin2hex(random_bytes(8)),
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
