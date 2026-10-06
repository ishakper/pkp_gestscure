<?php

namespace Tests\Unit;

use App\Database\Import\PgsqlColumnValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PgsqlColumnValueTest extends TestCase
{
    public function test_identity_text_is_never_changed(): void
    {
        foreach (['00001', '0001', '1', 'pkp-0004', 'PKP-0004', ' 007 ', "Siti Nur'aini Ž"] as $identity) {
            $this->assertSame($identity, PgsqlColumnValue::toBinding($identity, 'character varying', 255));
            $this->assertSame($identity, PgsqlColumnValue::canonical($identity, 'character varying'));
        }
        $this->assertNotSame(
            PgsqlColumnValue::canonical('00001', 'character varying'),
            PgsqlColumnValue::canonical('1', 'character varying')
        );
    }

    public function test_text_that_postgres_cannot_store_unchanged_is_rejected(): void
    {
        $this->assertRejected(str_repeat('x', 256), 'character varying', 255);
        $this->assertRejected("a\0b", 'text');
        $this->assertRejected("\xC3\x28", 'text');
    }

    public function test_booleans_accept_only_zero_and_one(): void
    {
        $this->assertTrue(PgsqlColumnValue::toBinding(1, 'boolean'));
        $this->assertFalse(PgsqlColumnValue::toBinding('0', 'boolean'));
        $this->assertSame('t', PgsqlColumnValue::canonical(true, 'boolean'));
        $this->assertSame(PgsqlColumnValue::canonical(1, 'boolean'), PgsqlColumnValue::canonical(true, 'boolean'));
        $this->assertRejected(2, 'boolean');
        $this->assertRejected('true', 'boolean');
    }

    public function test_json_is_validated_and_passed_through_verbatim(): void
    {
        $json = '{"note":"0001 ≠ 1",  "b": [1,2]}';
        $this->assertSame($json, PgsqlColumnValue::toBinding($json, 'json'));
        $this->assertRejected('[broken', 'json');
    }

    public function test_numbers_and_dates_compare_by_value(): void
    {
        $this->assertSame('12.5', PgsqlColumnValue::canonical('12.50', 'numeric'));
        $this->assertSame('12.5', PgsqlColumnValue::canonical(12.5, 'numeric'));
        $this->assertSame('100', PgsqlColumnValue::canonical('100.00', 'numeric'));
        $this->assertSame('0.5', PgsqlColumnValue::canonical('.50', 'numeric'));
        $this->assertSame('123456789012345678', (string) PgsqlColumnValue::toBinding('123456789012345678', 'bigint'));
        $this->assertRejected('12a', 'integer');
        $this->assertRejected(1.5, 'integer');

        $this->assertSame('2026-09-01 08:00:00', PgsqlColumnValue::canonical('2026-09-01T08:00:00.000000Z', 'timestamp without time zone'));
        $this->assertSame('2026-09-01 08:00:00.12', PgsqlColumnValue::canonical('2026-09-01 08:00:00.120', 'timestamp without time zone'));
        $this->assertSame('2024-02-01', PgsqlColumnValue::canonical('2024-02-01 00:00:00', 'date'));
        $this->assertSame('08:30:00', PgsqlColumnValue::canonical('08:30', 'time without time zone'));
        $this->assertRejected('2024-02-01 13:45:00', 'date');
        $this->assertRejected('1725177600', 'timestamp without time zone');
        $this->assertRejected('01/09/2026', 'date');
    }

    public function test_null_passes_through(): void
    {
        $this->assertNull(PgsqlColumnValue::toBinding(null, 'boolean'));
        $this->assertNull(PgsqlColumnValue::canonical(null, 'text'));
        $this->assertSame('\\N', PgsqlColumnValue::canonical('\\N', 'text'));
    }

    public function test_unknown_types_are_refused(): void
    {
        $this->assertFalse(PgsqlColumnValue::isSupportedType('bytea'));
        $this->assertFalse(PgsqlColumnValue::isSupportedType('timestamp with time zone'));
        $this->assertRejected('x', 'bytea');
    }

    private function assertRejected(mixed $value, string $type, ?int $max = null): void
    {
        try {
            PgsqlColumnValue::toBinding($value, $type, $max);
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('expected '.var_export($value, true)." to be rejected for {$type}");
    }
}
