<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Canonical process roles and the shared required-role policy. */
final class ClusterRoleSet
{
    public const string API = 'api';
    public const string LIFECYCLE = 'lifecycle';
    public const string WEBHOOK = 'webhook';
    public const string TRANSITION = 'transition';
    public const string RESTORE = 'restore';
    public const string GARBAGE = 'garbage';
    public const string RECONCILE = 'reconcile';

    public const string PROTOCOL = 'explicit-process-roles-v1';

    /** @var list<string> */
    public const array ALL = [
        self::API,
        self::GARBAGE,
        self::LIFECYCLE,
        self::RECONCILE,
        self::RESTORE,
        self::TRANSITION,
        self::WEBHOOK,
    ];

    /**
     * @param array<array-key, mixed>|string|null $value
     * @return list<string>|null
     */
    public static function parse(array|string|null $value, string $name): ?array
    {
        if ($value === null) {
            return null;
        }

        $items = is_string($value) ? explode(',', $value) : array_values($value);
        if ($items === []) {
            throw new \InvalidArgumentException("{$name} must contain at least one cluster role.");
        }

        $roles = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                throw new \InvalidArgumentException("{$name} must contain only role names.");
            }
            $role = trim($item);
            if ($role === '') {
                throw new \InvalidArgumentException("{$name} must not contain blank role names.");
            }
            if (! in_array($role, self::ALL, true)) {
                throw new \InvalidArgumentException("{$name} contains unknown cluster role '{$role}'.");
            }
            if (isset($roles[$role])) {
                throw new \InvalidArgumentException("{$name} contains duplicate cluster role '{$role}'.");
            }
            $roles[$role] = true;
        }

        $canonical = array_keys($roles);
        sort($canonical, SORT_STRING);

        return $canonical;
    }

    /** @param list<string> $roles */
    public static function contains(array $roles, string $role): bool
    {
        return in_array($role, $roles, true);
    }
}
