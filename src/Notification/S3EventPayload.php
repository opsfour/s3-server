<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

/**
 * Builds S3-style event notification JSON payloads from available event data.
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
     * @param string $bucketOwnerId The bucket owner identity.
     * @param string $configurationId The matching bucket notification configuration ID.
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
        string $bucketOwnerId = '',
        string $configurationId = '',
        ?string $sourceIp = null,
        ?string $requestId = null,
        ?string $hostId = null,
        ?string $versionId = null,
        ?string $sequencer = null,
        ?string $eventTime = null,
        bool $sizeKnown = false,
        ?string $restoreExpiresAt = null,
        ?string $sourceStorageClass = null,
    ): string {
        $object = ['key' => urlencode($key)];
        if (str_starts_with($eventName, 's3:ObjectCreated:') || $size > 0 || $sizeKnown) {
            $object['size'] = $size;
        }
        if ($etag !== '') {
            $object['eTag'] = trim($etag, '"');
        }
        if ($versionId !== null && $versionId !== '' && $versionId !== 'null') {
            $object['versionId'] = $versionId;
        }
        if ($sequencer !== null) {
            $object['sequencer'] = $sequencer;
        }

        $payload = [
            'Records' => [
                [
                    'eventVersion' => '2.1',
                    'eventSource' => 'aws:s3',
                    'awsRegion' => $region,
                    'eventTime' => $eventTime ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                    'eventName' => str_starts_with($eventName, 's3:') ? substr($eventName, 3) : $eventName,
                    'userIdentity' => [
                        'principalId' => $ownerId,
                    ],
                    's3' => [
                        's3SchemaVersion' => '1.0',
                        'configurationId' => $configurationId,
                        'bucket' => [
                            'name' => $bucket,
                            'ownerIdentity' => [
                                'principalId' => $bucketOwnerId !== '' ? $bucketOwnerId : $ownerId,
                            ],
                            'arn' => "arn:aws:s3:::{$bucket}",
                        ],
                        'object' => $object,
                    ],
                ],
            ],
        ];

        $record = &$payload['Records'][0];
        if ($sourceIp !== null && $sourceIp !== '') {
            $record['requestParameters'] = ['sourceIPAddress' => $sourceIp];
        }
        if ($requestId !== null && $requestId !== '') {
            $record['responseElements'] = ['x-amz-request-id' => $requestId];
            if ($hostId !== null && $hostId !== '') {
                $record['responseElements']['x-amz-id-2'] = $hostId;
            }
        }
        if ($eventName === 's3:ObjectRestore:Completed'
            && $restoreExpiresAt !== null && $sourceStorageClass !== null) {
            $record['glacierEventData'] = [
                'restoreEventData' => [
                    'lifecycleRestorationExpiryTime' => $restoreExpiresAt,
                    'lifecycleRestoreStorageClass' => $sourceStorageClass,
                ],
            ];
        }
        unset($record);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
