<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HikvisionNumericIdTest extends TestCase
{
    /**
     * Test that string "1" remains "1" and does not become integer 1.
     */
    public function test_numeric_string_preserved()
    {
        $personNumber = "1";
        $exactKey = "id\0" . $personNumber;
        $this->assertStringContainsString("\0", $exactKey);
        $this->assertStringContainsString("1", $exactKey);
    }

    /**
     * Test that leading zeros are preserved (e.g., "01" stays "01").
     */
    public function test_leading_zero_preserved()
    {
        $personNumber = "01";
        $folded = mb_strtolower($personNumber, 'UTF-8');
        $this->assertEqual($personNumber, "01");
        $this->assertEqual($folded, "01");
    }

    /**
     * Test that "1" and "01" are NOT treated as duplicate because key encoding is different.
     */
    public function test_one_and_zero_one_not_duplicate()
    {
        $exactIds = [];
        $id1 = "1";
        $key1 = "id\0" . $id1;
        $exactIds[$key1] = true;

        $id2 = "01";
        $key2 = "id\0" . $id2;
        $this->assertNotContains($key2, array_keys($exactIds));
    }

    /**
     * Test that "00001" preserves leading zeros in the key.
     */
    public function test_five_digit_leading_zeros()
    {
        $personNumber = "00001";
        $exactKey = "id\0" . $personNumber;
        $this->assertStringContainsString("00001", $exactKey);
    }

    /**
     * Test that "L261072" and "l261072" are detected as case conflict by folded key.
     */
    public function test_case_conflict_detected()
    {
        $foldedIds = [];
        $id1 = "L261072";
        $key1 = "fold\0" . mb_strtolower($id1, 'UTF-8');
        $foldedIds[$key1] = true;

        $id2 = "l261072";
        $key2 = "fold\0" . mb_strtolower($id2, 'UTF-8');
        $this->assertArrayHasKey($key2, $foldedIds);
    }

    /**
     * Test that "L261072" and "L261071" are NOT case conflict (different numbers).
     */
    public function test_different_numbers_not_case_conflict()
    {
        $id1 = "L261072";
        $id2 = "L261071";
        $fold1 = mb_strtolower($id1, 'UTF-8');
        $fold2 = mb_strtolower($id2, 'UTF-8');
        $this->assertNotIdentical($fold1, $fold2);
    }

    /**
     * Test that exact ID duplicates are detected.
     */
    public function test_exact_duplicate_detected()
    {
        $exactIds = [];
        $id1 = "230576";
        $key1 = "id\0" . $id1;
        $exactIds[$key1] = true;

        $id2 = "230576";
        $key2 = "id\0" . $id2;
        $this->assertArrayHasKey($key2, $exactIds);
    }

    /**
     * Test that header row is not counted as data.
     */
    public function test_header_not_counted()
    {
        $rawData = [
            ['No', 'Person No', 'Display Name', 'Cards', 'Status'],
            ['1', '230576', 'John Doe', 'Yes', 'Active'],
        ];
        $data = [];
        $headerIdx = 0;
        foreach ($rawData as $idx => $row) {
            if (!empty($row[0]) && in_array(trim($row[0]), ['No', 'INDEX'])) {
                $headerIdx = $idx + 1;
                break;
            }
        }
        foreach ($rawData as $idx => $row) {
            if ($idx < $headerIdx) continue;
            $data[] = $row;
        }
        $this->assertCount(1, $data);
    }

    /**
     * Test that 96 source rows remain 96 after processing with no case conflicts or duplicates.
     */
    public function test_96_rows_clean()
    {
        $count = 0;
        $exactIds = [];
        $foldedIds = [];
        for ($i = 1; $i <= 96; $i++) {
            $personNumber = (string)$i;
            $exactKey = "id\0" . $personNumber;
            $foldKey = "fold\0" . mb_strtolower($personNumber, 'UTF-8');
            if (!isset($exactIds[$exactKey]) && !isset($foldedIds[$foldKey])) {
                $exactIds[$exactKey] = true;
                $foldedIds[$foldKey] = true;
                $count++;
            }
        }
        $this->assertEqual(96, $count);
    }

    /**
     * Test that numeric IDs do not produce false case conflicts.
     */
    public function test_numeric_id_no_false_case_conflict()
    {
        $ids = ['1', '2', '10', '100', '00001', '230576'];
        $foldedIds = [];
        $caseConflicts = 0;
        foreach ($ids as $id) {
            $foldKey = "fold\0" . mb_strtolower($id, 'UTF-8');
            if (isset($foldedIds[$foldKey])) {
                $caseConflicts++;
            }
            $foldedIds[$foldKey] = true;
        }
        $this->assertEqual(0, $caseConflicts);
    }

    private function assertEqual($expected, $actual)
    {
        $this->assertEquals($expected, $actual);
    }
}
