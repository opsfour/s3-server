<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Inert schema-v2 heartbeat. Validation does not read a file, inspect a host,
 * contact EFS or admit serving. The host pin and clock must be independently
 * supplied by a future production reader.
 */
final readonly class ProductionEfsWatcherEvidence
{
    private const int MAX_JSON_BYTES = 4096;

    private const int MAX_LEASE_NS = 20_000_000_000;

    private function __construct(
        public ProductionEfsIdentity $identity,
        public ProductionEfsHostPin $hostPin,
        public int $issuedMonotonicNs,
        public int $expiresMonotonicNs,
    ) {}

    public static function fromJson(
        string $json,
        ProductionEfsIdentity $identity,
        ProductionEfsHostPin $hostPin,
        int $nowMonotonicNs,
    ): self {
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES || ! str_ends_with($json, "\n")
            || str_contains($json, '\\')) {
            throw new \InvalidArgumentException('Production EFS heartbeat framing is invalid.');
        }
        try {
            $record = json_decode($json, true, 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Production EFS heartbeat JSON is invalid.');
        }
        $fields = [
            'schemaVersion', 'profile', 'deploymentId', 'instanceId', 'efsId',
            'sourceSha256', 'metadataDriver', 'mountPoint', 'storageRoot',
            'containerId', 'bootId', 'hostMountId', 'storageDev', 'storageIno',
            'epoch', 'issuedMonotonicNs', 'expiresMonotonicNs',
        ];
        if (! is_array($record) || array_is_list($record) || count($record) !== count($fields)) {
            throw new \InvalidArgumentException('Production EFS heartbeat fields are invalid.');
        }
        $keys = array_keys($record);
        sort($keys);
        sort($fields);
        preg_match_all('/"([A-Za-z][A-Za-z0-9]*)"\s*:/', $json, $matches);
        $rawKeys = $matches[1];
        sort($rawKeys);
        if ($keys !== $fields || $rawKeys !== $fields
            || $record['schemaVersion'] !== 2 || $record['profile'] !== 'production'
            || $hostPin->deploymentId !== $identity->deploymentId
            || $hostPin->instanceId !== $identity->instanceId
            || $record['deploymentId'] !== $identity->deploymentId
            || $record['instanceId'] !== $identity->instanceId
            || $record['efsId'] !== $identity->efsId
            || $record['sourceSha256'] !== $identity->sourceSha256
            || $record['metadataDriver'] !== $identity->metadataDriver
            || $record['mountPoint'] !== $identity->mountPoint
            || $record['storageRoot'] !== $identity->storageRoot
            || $record['containerId'] !== $hostPin->containerId
            || $record['bootId'] !== $hostPin->bootId
            || $record['hostMountId'] !== $hostPin->hostMountId
            || $record['storageDev'] !== $hostPin->storageDev
            || $record['storageIno'] !== $hostPin->storageIno
            || $record['epoch'] !== $hostPin->epoch) {
            throw new \InvalidArgumentException('Production EFS heartbeat scope is invalid.');
        }
        $issued = $record['issuedMonotonicNs'];
        $expires = $record['expiresMonotonicNs'];
        if (! is_int($issued) || ! is_int($expires) || $issued < 0 || $nowMonotonicNs < 0
            || $issued > $nowMonotonicNs || $expires <= $nowMonotonicNs
            || $expires <= $issued
            || $expires - $issued > self::MAX_LEASE_NS) {
            throw new \InvalidArgumentException('Production EFS heartbeat lease is invalid.');
        }

        return new self($identity, $hostPin, $issued, $expires);
    }
}
