<?php

namespace App\Database\Import;

use InvalidArgumentException;

/**
 * Converts one SQLite value into the binding PostgreSQL needs for a column,
 * and canonicalises values from both sides so they can be compared.
 *
 * Text is never transformed: an identity such as "00001" is bound and
 * compared byte for byte. Only the representations PostgreSQL itself
 * normalises (booleans, numerics, dates and times) are canonicalised.
 */
final class PgsqlColumnValue
{
    private const INTEGER_TYPES = ['smallint', 'integer', 'bigint'];
    private const TEXT_TYPES = ['character varying', 'text', 'character'];
    // timestamptz is deliberately absent: its text depends on the session time zone.
    private const TEMPORAL_TYPES = ['timestamp without time zone', 'date', 'time without time zone'];

    private const DATETIME_PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?(Z|[+-]\d{2}(?::?\d{2})?)?$/';
    private const TIME_PATTERN = '/^(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/';

    public static function isTextType(string $type): bool
    {
        return in_array($type, self::TEXT_TYPES, true);
    }

    public static function isTemporalType(string $type): bool
    {
        return in_array($type, self::TEMPORAL_TYPES, true);
    }

    public static function isSupportedType(string $type): bool
    {
        return in_array($type, self::INTEGER_TYPES, true)
            || in_array($type, self::TEXT_TYPES, true)
            || in_array($type, self::TEMPORAL_TYPES, true)
            || in_array($type, ['boolean', 'numeric', 'json', 'jsonb', 'uuid'], true);
    }

    /**
     * Value to bind in the PostgreSQL INSERT. Throws when the value cannot be
     * stored without changing it.
     */
    public static function toBinding(mixed $value, string $type, ?int $maxLength = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($type, self::TEXT_TYPES, true) || $type === 'uuid') {
            $value = (string) $value;
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('text is not valid UTF-8');
            }
            if (str_contains($value, "\0")) {
                throw new InvalidArgumentException('text contains a NUL byte, which PostgreSQL cannot store');
            }
            if ($maxLength !== null && mb_strlen($value, 'UTF-8') > $maxLength) {
                throw new InvalidArgumentException(sprintf('text is %d characters, column allows %d', mb_strlen($value, 'UTF-8'), $maxLength));
            }

            return $value;
        }

        return match (true) {
            $type === 'boolean' => self::toBoolean($value),
            in_array($type, self::INTEGER_TYPES, true) => self::toInteger($value),
            $type === 'numeric' => self::toNumeric($value),
            $type === 'json', $type === 'jsonb' => self::toJson($value),
            $type === 'date' => self::toDate($value),
            $type === 'time without time zone' => self::toTime($value),
            in_array($type, self::TEMPORAL_TYPES, true) => self::toTimestamp($value),
            default => throw new InvalidArgumentException("unsupported column type [{$type}]"),
        };
    }

    /**
     * Canonical text of a value as PostgreSQL will hold it, for comparing the
     * converted source value with the value read back from the target.
     * NULL stays null, so it can never collide with any text.
     */
    public static function canonical(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $type === 'boolean' => self::toBoolean($value) ? 't' : 'f',
            in_array($type, self::INTEGER_TYPES, true) => (string) self::toInteger($value),
            $type === 'numeric' => self::normaliseDecimal(self::toNumeric($value)),
            $type === 'date' => self::toDate((string) $value),
            $type === 'time without time zone' => self::normaliseTime((string) $value),
            in_array($type, self::TEMPORAL_TYPES, true) => self::normaliseTimestamp((string) $value),
            default => (string) $value,
        };
    }

    private static function toBoolean(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 0, $value === '0' => false,
            $value === 1, $value === '1' => true,
            default => throw new InvalidArgumentException('boolean must be 0 or 1, got '.var_export($value, true)),
        };
    }

    private static function toInteger(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return $value;
        }

        throw new InvalidArgumentException('integer expected, got '.var_export($value, true));
    }

    private static function toNumeric(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $value)) {
            return $value;
        }

        throw new InvalidArgumentException('numeric expected, got '.var_export($value, true));
    }

    private static function toJson(mixed $value): string
    {
        $text = (string) $value;
        json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('invalid JSON: '.json_last_error_msg());
        }

        // Passed through untouched: the pg json type keeps the input text.
        return $text;
    }

    private static function toTimestamp(mixed $value): string
    {
        if (! is_string($value) || ! preg_match(self::DATETIME_PATTERN, $value)) {
            throw new InvalidArgumentException('timestamp expected (Y-m-d H:i:s), got '.var_export($value, true));
        }

        return $value;
    }

    private static function toDate(mixed $value): string
    {
        if (! is_string($value) || ! preg_match(self::DATETIME_PATTERN, $value, $m)) {
            throw new InvalidArgumentException('date expected (Y-m-d), got '.var_export($value, true));
        }
        // A date column only keeps the day: refuse to drop a real time of day.
        $timeOfDay = ($m[2] ?? '').($m[3] ?? '').($m[4] ?? '').($m[5] ?? '');
        if (trim($timeOfDay, '0') !== '') {
            throw new InvalidArgumentException("date column holds a time of day, got '{$value}'");
        }

        return $m[1];
    }

    private static function toTime(mixed $value): string
    {
        if (! is_string($value) || ! preg_match(self::TIME_PATTERN, $value)) {
            throw new InvalidArgumentException('time expected (H:i:s), got '.var_export($value, true));
        }

        return $value;
    }

    private static function normaliseDecimal(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        $value = ltrim($value, '0');
        $value = $value === '' || str_starts_with($value, '.') ? '0'.$value : $value;

        return ($negative && $value !== '0') ? '-'.$value : $value;
    }

    private static function normaliseTimestamp(string $value): string
    {
        if (! preg_match(self::DATETIME_PATTERN, $value, $m)) {
            return $value;
        }
        // timestamp without time zone ignores a zone suffix, as PostgreSQL does.
        $fraction = rtrim($m[5] ?? '', '0');

        return sprintf('%s %s:%s:%s%s', $m[1], ($m[2] ?? '') ?: '00', ($m[3] ?? '') ?: '00', ($m[4] ?? '') ?: '00', $fraction === '' ? '' : '.'.$fraction);
    }

    private static function normaliseTime(string $value): string
    {
        if (! preg_match(self::TIME_PATTERN, $value, $m)) {
            return $value;
        }
        $fraction = rtrim($m[4] ?? '', '0');

        return sprintf('%s:%s:%s%s', $m[1], $m[2], ($m[3] ?? '') ?: '00', $fraction === '' ? '' : '.'.$fraction);
    }
}
