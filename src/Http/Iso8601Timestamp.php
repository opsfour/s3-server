<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

/**
 * Strict ISO-8601 timestamp parsing without PHP's date normalization.
 */
final class Iso8601Timestamp
{
    private function __construct() {}

    public static function parse(string $value, bool $requireUtc = false): ?\DateTimeImmutable
    {
        $matched = preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})'
            . 'T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})'
            . '(?:\.(?<fraction>\d{1,9}))?'
            . '(?<timezone>Z|[+-]\d{2}:\d{2})$/D',
            $value,
            $parts,
        );
        if ($matched !== 1 || ($requireUtc && $parts['timezone'] !== 'Z')) {
            return null;
        }

        $year = (int) $parts['year'];
        $month = (int) $parts['month'];
        $day = (int) $parts['day'];
        $hour = (int) $parts['hour'];
        $minute = (int) $parts['minute'];
        $second = (int) $parts['second'];
        if (!checkdate($month, $day, $year)
            || $hour > 23
            || $minute > 59
            || $second > 59
            || !self::validTimezoneOffset($parts['timezone'])) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function validTimezoneOffset(string $timezone): bool
    {
        if ($timezone === 'Z') {
            return true;
        }

        $hour = (int) substr($timezone, 1, 2);
        $minute = (int) substr($timezone, 4, 2);

        return $hour <= 23 && $minute <= 59;
    }
}
