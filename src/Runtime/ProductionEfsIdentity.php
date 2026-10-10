<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Inert production EFS scope. Parsing this value never reads a path, checks a
 * watcher or authorizes serving. The root-owned file and live evidence are
 * separate prerequisites for a future production bootstrap.
 */
final readonly class ProductionEfsIdentity
{
    private const int MAX_JSON_BYTES = 4096;

    private function __construct(
        public string $deploymentId,
        public string $instanceId,
        public string $efsId,
        public string $sourceSha256,
        public string $metadataDriver,
        public string $mountPoint,
        public string $storageRoot,
        public string $scratchPath,
        public string $evidenceDirectory,
    ) {}

    public static function fromJson(string $json): self
    {
        // The scope consists only of printable ASCII identifiers and paths.
        // Reject JSON escapes so duplicate keys cannot hide behind an alias.
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES || str_contains($json, '\\')) {
            throw new \InvalidArgumentException('Production EFS identity framing is invalid.');
        }

        try {
            $value = json_decode($json, true, 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Production EFS identity JSON is invalid.');
        }
        $fields = [
            'schemaVersion', 'profile', 'deploymentId', 'instanceId', 'efsId',
            'sourceSha256', 'metadataDriver', 'mountPoint', 'storageRoot',
            'scratchPath', 'evidenceDirectory',
        ];
        if (! is_array($value) || array_is_list($value) || count($value) !== count($fields)) {
            throw new \InvalidArgumentException('Production EFS identity fields are invalid.');
        }
        $keys = array_keys($value);
        sort($keys);
        sort($fields);
        preg_match_all('/"([A-Za-z][A-Za-z0-9]*)"\s*:/', $json, $matches);
        $rawKeys = $matches[1];
        sort($rawKeys);
        if ($keys !== $fields || $rawKeys !== $fields
            || $value['schemaVersion'] !== 2 || $value['profile'] !== 'production') {
            throw new \InvalidArgumentException('Production EFS identity schema or profile is invalid.');
        }

        $deploymentId = self::stringField($value, 'deploymentId');
        $instanceId = self::stringField($value, 'instanceId');
        $efsId = self::stringField($value, 'efsId');
        $sourceSha256 = self::stringField($value, 'sourceSha256');
        $metadataDriver = self::stringField($value, 'metadataDriver');
        $mountPoint = self::pathField($value, 'mountPoint');
        $storageRoot = self::pathField($value, 'storageRoot');
        $scratchPath = self::pathField($value, 'scratchPath');
        $evidenceDirectory = self::pathField($value, 'evidenceDirectory');

        if (preg_match('/^[a-f0-9]{32}$/D', $deploymentId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $instanceId) !== 1
            || preg_match('/^fs-[a-f0-9]{8,17}$/D', $efsId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
            || ! in_array($metadataDriver, ['postgres', 'mysql'], true)
            || ! self::isWithin($storageRoot, $mountPoint)
            || self::overlaps($scratchPath, $mountPoint)
            || self::overlaps($evidenceDirectory, $mountPoint)
            || self::overlaps($scratchPath, $evidenceDirectory)) {
            throw new \InvalidArgumentException('Production EFS identity scope is invalid.');
        }

        return new self(
            $deploymentId,
            $instanceId,
            $efsId,
            $sourceSha256,
            $metadataDriver,
            $mountPoint,
            $storageRoot,
            $scratchPath,
            $evidenceDirectory,
        );
    }

    /** @param array<string, mixed> $value */
    private static function stringField(array $value, string $name): string
    {
        $field = $value[$name] ?? null;
        if (! is_string($field)) {
            throw new \InvalidArgumentException('Production EFS identity field type is invalid.');
        }

        return $field;
    }

    /** @param array<string, mixed> $value */
    private static function pathField(array $value, string $name): string
    {
        $path = self::stringField($value, $name);
        if (! str_starts_with($path, '/') || $path === '/' || str_ends_with($path, '/')
            || preg_match('#^/[A-Za-z0-9._/-]+$#D', $path) !== 1) {
            throw new \InvalidArgumentException('Production EFS identity path is invalid.');
        }
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Production EFS identity path is invalid.');
            }
        }

        return $path;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, $directory . '/');
    }

    private static function overlaps(string $first, string $second): bool
    {
        return $first === $second || self::isWithin($first, $second) || self::isWithin($second, $first);
    }
}
