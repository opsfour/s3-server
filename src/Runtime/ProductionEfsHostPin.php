<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Inert schema-v2 host identity obtained independently of the heartbeat.
 * Parsing performs no host inspection and grants no serving admission.
 */
final readonly class ProductionEfsHostPin
{
    private const int MAX_JSON_BYTES = 4096;

    private function __construct(
        public string $deploymentId,
        public string $instanceId,
        public string $containerId,
        public string $bootId,
        public string $hostMountId,
        public string $storageDev,
        public string $storageIno,
        public string $epoch,
    ) {}

    public static function fromJson(string $json): self
    {
        // The record consists only of canonical ASCII identifiers. Reject all
        // escapes so a duplicate key cannot hide behind an encoded alias.
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES || str_contains($json, '\\')) {
            throw new \InvalidArgumentException('Production EFS host pin framing is invalid.');
        }
        try {
            $record = json_decode($json, true, 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Production EFS host pin JSON is invalid.');
        }
        $fields = [
            'schemaVersion', 'profile', 'deploymentId', 'instanceId',
            'containerId', 'bootId', 'hostMountId', 'storageDev',
            'storageIno', 'epoch',
        ];
        if (! is_array($record) || array_is_list($record) || count($record) !== count($fields)) {
            throw new \InvalidArgumentException('Production EFS host pin fields are invalid.');
        }
        $keys = array_keys($record);
        sort($keys);
        sort($fields);
        preg_match_all('/"([A-Za-z][A-Za-z0-9]*)"\s*:/', $json, $matches);
        $rawKeys = $matches[1];
        sort($rawKeys);
        if ($keys !== $fields || $rawKeys !== $fields
            || $record['schemaVersion'] !== 2 || $record['profile'] !== 'production') {
            throw new \InvalidArgumentException('Production EFS host pin schema or profile is invalid.');
        }

        $deploymentId = self::stringField($record, 'deploymentId');
        $instanceId = self::stringField($record, 'instanceId');
        $containerId = self::stringField($record, 'containerId');
        $bootId = self::stringField($record, 'bootId');
        $hostMountId = self::stringField($record, 'hostMountId');
        $storageDev = self::stringField($record, 'storageDev');
        $storageIno = self::stringField($record, 'storageIno');
        $epoch = self::stringField($record, 'epoch');
        if (preg_match('/^[a-f0-9]{32}$/D', $deploymentId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $instanceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $containerId) !== 1
            || preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $bootId) !== 1
            || ! self::positiveDecimal($hostMountId)
            || ! self::positiveDecimal($storageDev)
            || ! self::positiveDecimal($storageIno)
            || preg_match('/^[a-f0-9]{32}$/D', $epoch) !== 1) {
            throw new \InvalidArgumentException('Production EFS host pin is invalid.');
        }

        return new self(
            $deploymentId,
            $instanceId,
            $containerId,
            $bootId,
            $hostMountId,
            $storageDev,
            $storageIno,
            $epoch,
        );
    }

    /** @param array<string, mixed> $record */
    private static function stringField(array $record, string $name): string
    {
        $value = $record[$name] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Production EFS host pin field type is invalid.');
        }

        return $value;
    }

    private static function positiveDecimal(string $value): bool
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $value) !== 1) {
            return false;
        }

        return strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0;
    }
}
