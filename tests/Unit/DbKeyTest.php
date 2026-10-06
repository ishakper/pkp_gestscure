<?php

namespace Tests\Unit;

use App\Support\DbKey;
use PHPUnit\Framework\TestCase;

class DbKeyTest extends TestCase
{
    public function test_accepts_positive_integers_and_digit_strings(): void
    {
        $this->assertTrue(DbKey::isValid(7));
        $this->assertTrue(DbKey::isValid('42'));
    }

    public function test_rejects_codes_and_non_keys(): void
    {
        foreach (['DOOR-B', 'USR-1001', '', '1.5', '-3', ' 4', null, 0, '1234567890123456789'] as $value) {
            $this->assertFalse(DbKey::isValid($value), var_export($value, true));
        }
    }

    public function test_filter_keeps_only_valid_keys(): void
    {
        $this->assertSame(['3', 5], DbKey::filter(['DOOR-A', '3', 5, 'DOOR-B']));
    }
}
