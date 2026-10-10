<?php

declare(strict_types=1);

/** Preflight for the isolated, unsafe two-host EFS qualification runner. */
final class MultihostNodeBootstrap
{
    /** @return array<string, string|int> */
    public static function loadConfig(string $path): array
    {
        if (is_link($path)) {
            throw new RuntimeException('Qualification config must not be a symlink.');
        }
        $parent = dirname($path);
        $parentStat = @stat($parent);
        if ($parentStat === false || ! is_dir($parent)
            || ($parentStat['mode'] & 0o077) !== 0 || $parentStat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Qualification config directory must be private and owned by the API user.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open qualification config.');
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0o170000) !== 0o100000
                || ($stat['mode'] & 0o077) !== 0 || $stat['uid'] !== posix_geteuid()
                || $stat['size'] > 16_384) {
                throw new RuntimeException('Qualification config must be a private regular file owned by the API user (maximum 16 KiB).');
            }
            $json = stream_get_contents($handle, 16_385);
            if ($json === false || strlen($json) > 16_384) {
                throw new RuntimeException('Cannot read bounded qualification config.');
            }
        } finally {
            fclose($handle);
        }

        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Qualification config must be valid JSON.');
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Qualification config must be a JSON object.');
        }

        $required = [
            'nodeId', 'runId', 'efsId', 'mountPoint', 'storagePath', 'scratchPath',
            'listenIp', 'proxyIp', 'baseDomain', 'metadataDriver', 'metadataDsn',
            'accessKey', 'secretKey', 'ownerId',
        ];
        foreach ($required as $key) {
            if (! isset($value[$key]) || ! is_string($value[$key]) || trim($value[$key]) === '') {
                throw new RuntimeException("Missing qualification config field {$key}.");
            }
        }
        foreach (['mountPoint', 'storagePath', 'scratchPath'] as $key) {
            if (! str_starts_with($value[$key], '/')) {
                throw new RuntimeException("Qualification {$key} must be an absolute path.");
            }
        }
        foreach (array_keys($value) as $key) {
            if (! in_array($key, [...$required, 'port'], true)) {
                throw new RuntimeException('Unknown qualification config field.');
            }
        }
        if (! in_array($value['nodeId'], ['api-one', 'api-two'], true)
            || ! preg_match('/^[a-f0-9]{32}$/D', $value['runId'])
            || ! preg_match('/^fs-[a-f0-9]{8,17}$/D', $value['efsId'])
            || preg_match('/^(?:[a-z0-9-]+\.)+[a-z0-9-]+$/D', $value['baseDomain']) !== 1
            || ! in_array($value['metadataDriver'], ['postgres', 'mysql'], true)) {
            throw new RuntimeException('Qualification identity or metadata driver is invalid.');
        }
        foreach (['listenIp', 'proxyIp'] as $key) {
            if (! self::isPrivateIpv4($value[$key])) {
                throw new RuntimeException("Qualification {$key} must be a private IPv4 address.");
            }
        }
        $value['port'] ??= 9000;
        if (! is_int($value['port']) || $value['port'] !== 9000) {
            throw new RuntimeException('Qualification API port must be 9000, matching the isolated stack security group.');
        }
        self::assertMetadataDestination($value);

        return $value;
    }

    /** @param array<string, string|int> $config */
    public static function assertFilesystem(array $config, string $mountInfo): void
    {
        $mount = realpath((string) $config['mountPoint']);
        $storage = realpath((string) $config['storagePath']);
        $scratch = realpath((string) $config['scratchPath']);
        $temp = realpath(sys_get_temp_dir());
        if ($mount === false || $storage === false || $scratch === false || $temp === false
            || ! is_dir($mount) || ! is_dir($storage) || ! is_dir($scratch)
            || ! str_starts_with($storage, rtrim($mount, '/') . '/')
            || ! str_starts_with($scratch, rtrim($temp, '/') . '/')
            || ! is_writable($storage) || ! is_writable($scratch)) {
            throw new RuntimeException('Qualification storage and scratch paths must exist on their respective mount and local temp roots.');
        }
        $scratchStat = @stat($scratch);
        $storageStat = @stat($storage);
        $mountStat = @stat($mount);
        $tempStat = @stat($temp);
        if ($scratchStat === false || $storageStat === false || $mountStat === false || $tempStat === false
            || $scratchStat['uid'] !== posix_geteuid() || ($scratchStat['mode'] & 0o077) !== 0
            || $storageStat['uid'] !== posix_geteuid() || ($storageStat['mode'] & 0o077) !== 0
            || $scratchStat['dev'] !== $tempStat['dev']) {
            throw new RuntimeException('Qualification storage and scratch must be private and on separate EFS/local volumes.');
        }
        self::assertStorageMountDevice($mountStat['dev'], $storageStat['dev'], $scratchStat['dev']);
        self::assertMountRecord($mountInfo, $mount, (string) $config['efsId']);

        $marker = $mount . '/.s3-qualification-' . $config['runId'];
        $markerStat = @stat($marker);
        if (is_link($marker) || ! is_file($marker) || $markerStat === false
            || $markerStat['uid'] !== posix_geteuid() || ($markerStat['mode'] & 0o077) !== 0
            || $markerStat['size'] > 128
            || @file_get_contents($marker) !== $config['efsId'] . "\n" . $config['runId'] . "\n") {
            throw new RuntimeException('Qualification run marker is missing or does not match the expected EFS ID and run ID.');
        }
        self::assertPrivateLogPath($scratch . '/node-' . $config['nodeId'] . '.log');
    }

    public static function assertStorageMountDevice(int $mountDevice, int $storageDevice, int $scratchDevice): void
    {
        if ($storageDevice !== $mountDevice || $storageDevice === $scratchDevice) {
            throw new RuntimeException('Qualification storage must be on the verified EFS mount and separate from node-local scratch.');
        }
    }

    public static function assertPrivateLogPath(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        $stat = @stat($path);
        if (is_link($path) || $stat === false || ! is_file($path)
            || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0o077) !== 0) {
            throw new RuntimeException('Existing qualification node log must be a private regular file owned by the API user.');
        }
    }

    public static function assertMountRecord(string $mountInfo, string $mountPoint, string $efsId): void
    {
        foreach (explode("\n", $mountInfo) as $line) {
            $parts = explode(' - ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $left = preg_split('/\s+/', $parts[0]);
            $right = preg_split('/\s+/', $parts[1]);
            if (! is_array($left) || ! is_array($right) || count($left) < 6 || count($right) < 3
                || self::decodeMountPath($left[4]) !== $mountPoint) {
                continue;
            }
            $options = ',' . $left[5] . ',' . $right[2] . ',';
            if ($right[0] !== 'nfs4' || ! str_contains($options, ',hard,')
                || ! str_contains($options, ',vers=4.1,')) {
                throw new RuntimeException('Qualification mount must be hard NFSv4.1.');
            }
            if (preg_match('/fs-[a-f0-9]{8,17}/', $right[1], $match) === 1 && $match[0] !== $efsId) {
                throw new RuntimeException('Qualification mount source has a different EFS ID.');
            }

            return;
        }
        throw new RuntimeException('Qualification EFS mount point is not present in Linux mountinfo.');
    }

    private static function decodeMountPath(string $path): string
    {
        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $path);
    }

    private static function isPrivateIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $bytes = array_map('intval', explode('.', $address));

        return $bytes[0] === 10
            || ($bytes[0] === 172 && $bytes[1] >= 16 && $bytes[1] <= 31)
            || ($bytes[0] === 192 && $bytes[1] === 168);
    }

    /** @param array<string, string|int> $config */
    private static function assertMetadataDestination(array $config): void
    {
        try {
            $dsn = $config['metadataDriver'] === 'postgres'
                ? \Amp\Postgres\PostgresConfig::fromString((string) $config['metadataDsn'])
                : \Amp\Mysql\MysqlConfig::fromString((string) $config['metadataDsn']);
        } catch (Throwable) {
            throw new RuntimeException('Qualification metadata DSN is invalid.');
        }
        $expectedDatabase = 's3audit_' . $config['runId'] . '_' . $config['metadataDriver'];
        if ($dsn->getHost() !== $config['proxyIp'] || $dsn->getDatabase() !== $expectedDatabase) {
            throw new RuntimeException('Qualification metadata DSN must use the isolated metadata host and run-specific database.');
        }
    }
}
