<?php

declare(strict_types=1);

namespace OpsFour\S3Server\ObjectLock;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\OperationAbortedException;

/**
 * Keeps the Object Lock bucket configuration stable across a streamed write.
 *
 * @internal
 */
final class ObjectLockConfigurationSnapshot
{
    public const string MULTIPART_METADATA_KEY = '__mpu-object-lock-configuration';

    private const string ATTRIBUTE = 's3.objectLockConfigurationSnapshot';

    private function __construct() {}

    /** @param array<string, mixed>|null $config */
    public static function capture(Request $request, ?array $config): void
    {
        $request->setAttribute(self::ATTRIBUTE, self::fingerprint($config));
    }

    /** @param array<string, mixed>|null $config */
    public static function assertCurrent(Request $request, ?array $config): void
    {
        if (! $request->hasAttribute(self::ATTRIBUTE)) {
            return;
        }
        $expected = $request->getAttribute(self::ATTRIBUTE);
        if (! is_string($expected) || ! hash_equals($expected, self::fingerprint($config))) {
            throw new OperationAbortedException(
                'Object Lock configuration changed while the object write was in progress.',
            );
        }
    }

    /** @param array<string, mixed>|null $config */
    public static function assertFingerprint(string $expected, ?array $config): void
    {
        if (! hash_equals($expected, self::fingerprint($config))) {
            throw new OperationAbortedException(
                'Object Lock configuration changed after the multipart upload was initiated.',
            );
        }
    }

    /** @param array<string, mixed>|null $config */
    public static function fingerprint(?array $config): string
    {
        return hash('sha256', serialize(self::canonicalize($config)));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
