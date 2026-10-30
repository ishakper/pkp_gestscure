<?php

namespace Tests\Unit;

use App\Support\DbKey;
use PHPUnit\Framework\TestCase;

final class DbKeyTest extends TestCase
{
    public function test_accepts_positive_integers(): void
    {
        $this->assertTrue(DbKey::isValid(1));
        $this->assertTrue(DbKey::isValid(42));
        $this->assertFalse(DbKey::isValid(0));
        $this->assertFalse(DbKey::isValid(-5));
    }

    public function test_accepts_numeric_strings_within_signed_bigint_range(): void
    {
        $this->assertTrue(DbKey::isValid('1'));
        $this->assertTrue(DbKey::isValid('42'));
        $this->assertTrue(DbKey::isValid('123456789012345678')); // 18 digits
    }

    public function test_accepts_max_signed_64bit_bigint_19_digits(): void
    {
        $this->assertTrue(DbKey::isValid('9223372036854775807')); // Exactly 2^63 - 1
    }

    public function test_rejects_overflow_19_digits_and_beyond(): void
    {
        $this->assertFalse(DbKey::isValid('9223372036854775808')); // 2^63 (overflow)
        $this->assertFalse(DbKey::isValid('9999999999999999999')); // 19 digits overflow
        $this->assertFalse(DbKey::isValid('12345678901234567890')); // 20 digits
    }

    public function test_rejects_zero_and_negative_strings(): void
    {
        $this->assertFalse(DbKey::isValid('0'));
        $this->assertFalse(DbKey::isValid('-1'));
        $this->assertFalse(DbKey::isValid('-42'));
    }

    public function test_rejects_leading_zeros(): void
    {
        // Leading zeros indicate alphanumeric codes/person numbers, NOT auto-increment primary keys
        $this->assertFalse(DbKey::isValid('01'));
        $this->assertFalse(DbKey::isValid('007'));
        $this->assertFalse(DbKey::isValid('000123'));
    }

    public function test_rejects_non_numeric_and_empty_strings(): void
    {
        $this->assertFalse(DbKey::isValid(''));
        $this->assertFalse(DbKey::isValid('DOOR-B'));
        $this->assertFalse(DbKey::isValid('12a'));
        $this->assertFalse(DbKey::isValid('USR-100'));
        $this->assertFalse(DbKey::isValid('   '));
    }

    public function test_filter_removes_invalid_keys(): void
    {
        $input = [
            'DOOR-B',
            1,
            '42',
            '01',
            '9223372036854775808', // overflow
            '9223372036854775807', // max valid
            'DOOR-A',
        ];

        $filtered = DbKey::filter($input);

        $this->assertSame([1, '42', '9223372036854775807'], $filtered);
    }
}
