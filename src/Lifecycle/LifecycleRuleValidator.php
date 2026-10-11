<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Validates the normalized lifecycle shape loaded from metadata.
 *
 * Public requests are validated while parsing XML. This second boundary keeps
 * legacy, imported, or corrupted metadata fail closed before any action runs.
 */
final class LifecycleRuleValidator
{
    /** @param array<string, mixed> $rule */
    public static function assertValid(array $rule): void
    {
        $id = $rule['id'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 255) {
            throw new \UnexpectedValueException('Stored lifecycle rule ID is invalid.');
        }

        if (! in_array($rule['status'] ?? null, ['Enabled', 'Disabled'], true)) {
            throw new \UnexpectedValueException('Stored lifecycle rule status is invalid.');
        }

        self::assertFilter($rule);

        $hasAction = false;
        $expiration = $rule['expiration'] ?? null;
        if ($expiration !== null) {
            $hasAction = true;
            self::assertExpiration($expiration);
        }

        $transitions = $rule['transitions'] ?? null;
        if ($transitions !== null) {
            $hasAction = true;
            self::assertTransitions($transitions, false);
        }

        $noncurrentTransitions = $rule['noncurrentTransitions'] ?? null;
        if ($noncurrentTransitions !== null) {
            $hasAction = true;
            self::assertTransitions($noncurrentTransitions, true);
        }

        $noncurrentExpiration = $rule['noncurrentExpiration'] ?? null;
        if ($noncurrentExpiration !== null) {
            $hasAction = true;
            if (! is_array($noncurrentExpiration)
                || array_keys($noncurrentExpiration) !== ['noncurrentDays']) {
                throw new \UnexpectedValueException('Stored lifecycle noncurrent expiration is invalid.');
            }
            self::assertDays($noncurrentExpiration['noncurrentDays'], false, 'noncurrent expiration');
        }

        $abortDays = $rule['abortIncompleteDays'] ?? null;
        if ($abortDays !== null) {
            $hasAction = true;
            self::assertDays($abortDays, false, 'multipart abort');
        }

        if (! $hasAction) {
            throw new \UnexpectedValueException('Stored lifecycle rule contains no action.');
        }

        $hasTagFilter = self::hasTagFilter($rule);
        if ($hasTagFilter && ($abortDays !== null
            || (is_array($expiration) && array_key_exists('expiredObjectDeleteMarker', $expiration)))) {
            throw new \UnexpectedValueException('Stored lifecycle tag filter is incompatible with its action.');
        }
    }

    /** @param array<string, mixed> $rule */
    private static function assertFilter(array $rule): void
    {
        $prefix = $rule['prefix'] ?? null;
        $filter = $rule['filter'] ?? null;
        if ($prefix !== null && ! is_string($prefix)) {
            throw new \UnexpectedValueException('Stored lifecycle prefix is invalid.');
        }
        if ($filter !== null && ! is_array($filter)) {
            throw new \UnexpectedValueException('Stored lifecycle filter is invalid.');
        }
        if ($prefix !== null && $filter !== null) {
            throw new \UnexpectedValueException('Stored lifecycle rule combines Prefix and Filter.');
        }
        if ($filter === null || $filter === []) {
            return;
        }

        if (count($filter) !== 1) {
            throw new \UnexpectedValueException('Stored lifecycle filter contains conflicting predicates.');
        }
        if (array_key_exists('prefix', $filter)) {
            if (! is_string($filter['prefix'])) {
                throw new \UnexpectedValueException('Stored lifecycle filter prefix is invalid.');
            }

            return;
        }
        if (array_key_exists('tag', $filter)) {
            self::assertTag($filter['tag']);

            return;
        }
        if (! array_key_exists('and', $filter) || ! is_array($filter['and'])) {
            throw new \UnexpectedValueException('Stored lifecycle filter predicate is invalid.');
        }

        $and = $filter['and'];
        if (array_diff(array_keys($and), ['prefix', 'tags']) !== []) {
            throw new \UnexpectedValueException('Stored lifecycle And filter contains an unsupported predicate.');
        }
        $predicateCount = 0;
        if (array_key_exists('prefix', $and)) {
            if (! is_string($and['prefix'])) {
                throw new \UnexpectedValueException('Stored lifecycle And prefix is invalid.');
            }
            $predicateCount++;
        }

        $seen = [];
        if (array_key_exists('tags', $and)) {
            if (! is_array($and['tags']) || ! array_is_list($and['tags'])) {
                throw new \UnexpectedValueException('Stored lifecycle And tags are invalid.');
            }
            foreach ($and['tags'] as $tag) {
                $key = self::assertTag($tag);
                if (isset($seen["\0{$key}"])) {
                    throw new \UnexpectedValueException('Stored lifecycle filter contains duplicate tag keys.');
                }
                $seen["\0{$key}"] = true;
                $predicateCount++;
            }
        }
        if ($predicateCount < 2) {
            throw new \UnexpectedValueException('Stored lifecycle And filter has fewer than two predicates.');
        }
    }

    private static function assertTag(mixed $tag): string
    {
        if (! is_array($tag) || count($tag) !== 2 || array_diff(array_keys($tag), ['key', 'value']) !== []
            || ! is_string($tag['key']) || $tag['key'] === '' || ! is_string($tag['value'])) {
            throw new \UnexpectedValueException('Stored lifecycle tag filter is invalid.');
        }

        return $tag['key'];
    }

    private static function assertExpiration(mixed $expiration): void
    {
        if (! is_array($expiration) || count($expiration) !== 1) {
            throw new \UnexpectedValueException('Stored lifecycle expiration is invalid.');
        }
        if (array_key_exists('days', $expiration)) {
            self::assertDays($expiration['days'], false, 'expiration');

            return;
        }
        if (array_key_exists('date', $expiration)) {
            self::assertDate($expiration['date'], 'expiration');

            return;
        }
        if (! array_key_exists('expiredObjectDeleteMarker', $expiration)
            || ! is_bool($expiration['expiredObjectDeleteMarker'])) {
            throw new \UnexpectedValueException('Stored lifecycle expiration is invalid.');
        }
    }

    private static function assertTransitions(mixed $transitions, bool $noncurrent): void
    {
        if (! is_array($transitions) || ! array_is_list($transitions) || $transitions === []) {
            throw new \UnexpectedValueException('Stored lifecycle transitions are invalid.');
        }

        foreach ($transitions as $transition) {
            if (! is_array($transition)) {
                throw new \UnexpectedValueException('Stored lifecycle transition is invalid.');
            }
            $expectedKeys = $noncurrent ? ['noncurrentDays', 'storageClass'] : ['days', 'date', 'storageClass'];
            if (array_diff(array_keys($transition), $expectedKeys) !== []) {
                throw new \UnexpectedValueException('Stored lifecycle transition contains an unsupported field.');
            }
            if (! is_string($transition['storageClass'] ?? null) || $transition['storageClass'] === '') {
                throw new \UnexpectedValueException('Stored lifecycle transition storage class is invalid.');
            }
            if ($noncurrent) {
                if (count($transition) !== 2 || array_diff(array_keys($transition), ['noncurrentDays', 'storageClass']) !== []) {
                    throw new \UnexpectedValueException('Stored lifecycle noncurrent transition is invalid.');
                }
                self::assertDays($transition['noncurrentDays'], false, 'noncurrent transition');

                continue;
            }

            $hasDays = array_key_exists('days', $transition);
            $hasDate = array_key_exists('date', $transition);
            if ($hasDays === $hasDate) {
                throw new \UnexpectedValueException('Stored lifecycle transition must contain exactly one schedule.');
            }
            if ($hasDays) {
                self::assertDays($transition['days'], true, 'transition');
            } else {
                self::assertDate($transition['date'], 'transition');
            }
        }
    }

    private static function assertDays(mixed $days, bool $allowZero, string $field): void
    {
        $minimum = $allowZero ? 0 : 1;
        if (! is_int($days) || $days < $minimum || $days > XmlRequestParser::MAX_LIFECYCLE_DAYS) {
            throw new \UnexpectedValueException("Stored lifecycle {$field} days are outside the supported range.");
        }
    }

    private static function assertDate(mixed $date, string $field): void
    {
        if (! is_string($date)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})T00:00:00(?:\.0+)?(?:Z|\+00:00)$/', $date, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \UnexpectedValueException("Stored lifecycle {$field} date is invalid.");
        }
    }

    /** @param array<string, mixed> $rule */
    private static function hasTagFilter(array $rule): bool
    {
        $filter = $rule['filter'] ?? null;

        return is_array($filter)
            && (array_key_exists('tag', $filter)
                || (is_array($filter['and'] ?? null) && array_key_exists('tags', $filter['and'])));
    }
}
