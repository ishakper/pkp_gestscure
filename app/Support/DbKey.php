<?php

namespace App\Support;

/**
 * Guards lookups that accept either a numeric primary key or a code (e.g. "DOOR-B").
 * Postgres rejects comparing a bigint column with a non-numeric string, so the `id`
 * branch of such a lookup must only be added when the value can actually be a key.
 */
final class DbKey
{
    public static function isValid(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && $value !== '' && strlen($value) <= 18 && ctype_digit($value);
    }

    /**
     * @return array<int, int|string>
     */
    public static function filter(array $values): array
    {
        return array_values(array_filter($values, [self::class, 'isValid']));
    }
}
