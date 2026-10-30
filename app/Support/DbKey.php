<?php

namespace App\Support;

/**
 * Guards lookups that accept either a numeric primary key or an alphanumeric code (e.g. "DOOR-B").
 *
 * PostgreSQL strongly-typed BIGINT columns reject comparisons with non-numeric strings
 * (raising SQLSTATE[22P02]: invalid input syntax for type bigint). Furthermore, values
 * exceeding the signed 64-bit BIGINT limit (9,223,372,036,854,775,807) cause integer overflow.
 * Strings with leading zeros (e.g. Hikvision person numbers like "01" or "007") must also
 * be excluded from numeric primary key lookups to avoid accidental record collisions.
 */
final class DbKey
{
    public const MAX_BIGINT_STRING = '9223372036854775807';

    public static function isValid(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (!is_string($value) || $value === '' || !ctype_digit($value) || $value[0] === '0') {
            return false;
        }

        $length = strlen($value);

        if ($length < 19) {
            return true;
        }

        if ($length === 19) {
            return strcmp($value, self::MAX_BIGINT_STRING) <= 0;
        }

        return false;
    }

    /**
     * Filter an array of identifiers to only those valid as PostgreSQL BIGINT primary keys.
     *
     * @param array<int, mixed> $values
     * @return array<int, int|string>
     */
    public static function filter(array $values): array
    {
        return array_values(array_filter($values, [self::class, 'isValid']));
    }
}
