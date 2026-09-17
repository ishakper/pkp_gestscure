<?php

namespace Tests\Feature;

use App\Models\CredentialRecord;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class WorkbookCandidateImportTest extends TestCase
{
    use RefreshDatabase;

    private string $tempWorkbookPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempWorkbookPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_candidates_' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempWorkbookPath)) {
            @unlink($this->tempWorkbookPath);
        }
        parent::tearDown();
    }

    private function createMockWorkbook(array $rows): string
    {
        $zip = new ZipArchive();
        $zip->open($this->tempWorkbookPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets>'
            . '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // sharedStrings and sheet data
        $sharedStrings = [];
        $stringIndexMap = [];
        $getStringIndex = function (string $val) use (&$sharedStrings, &$stringIndexMap): int {
            if (isset($stringIndexMap[$val])) {
                return $stringIndexMap[$val];
            }
            $idx = count($sharedStrings);
            $sharedStrings[] = $val;
            $stringIndexMap[$val] = $idx;
            return $idx;
        };

        $sheetDataRows = '';
        // Header row
        $sheetDataRows .= '<row r="1">';
        $sheetDataRows .= '<c r="A1" t="s"><v>' . $getStringIndex('No') . '</v></c>';
        $sheetDataRows .= '<c r="B1" t="s"><v>' . $getStringIndex('Employee/Person No') . '</v></c>';
        $sheetDataRows .= '<c r="C1" t="s"><v>' . $getStringIndex('Display Name') . '</v></c>';
        $sheetDataRows .= '<c r="D1" t="s"><v>' . $getStringIndex('Cards') . '</v></c>';
        $sheetDataRows .= '<c r="E1" t="s"><v>' . $getStringIndex('Status') . '</v></c>';
        $sheetDataRows .= '<c r="F1" t="s"><v>' . $getStringIndex('SecureGate Mapping') . '</v></c>';
        $sheetDataRows .= '<c r="G1" t="s"><v>' . $getStringIndex('Card Registered') . '</v></c>';
        $sheetDataRows .= '<c r="H1" t="s"><v>' . $getStringIndex('Card Count') . '</v></c>';
        $sheetDataRows .= '<c r="I1" t="s"><v>' . $getStringIndex('Card Type') . '</v></c>';
        $sheetDataRows .= '</row>';

        $rowNum = 2;
        foreach ($rows as $item) {
            $sheetDataRows .= '<row r="' . $rowNum . '">';
            $sheetDataRows .= '<c r="A' . $rowNum . '"><v>' . ($rowNum - 1) . '</v></c>';
            $sheetDataRows .= '<c r="B' . $rowNum . '" t="s"><v>' . $getStringIndex($item['person_no']) . '</v></c>';
            $sheetDataRows .= '<c r="C' . $rowNum . '" t="s"><v>' . $getStringIndex($item['name']) . '</v></c>';
            $sheetDataRows .= '<c r="D' . $rowNum . '" t="s"><v>' . $getStringIndex('Not exposed') . '</v></c>';
            $sheetDataRows .= '<c r="E' . $rowNum . '"><v>1</v></c>';
            $sheetDataRows .= '<c r="F' . $rowNum . '" t="s"><v>' . $getStringIndex('NEW CANDIDATE') . '</v></c>';
            $sheetDataRows .= '<c r="G' . $rowNum . '" t="s"><v>' . $getStringIndex($item['has_card'] ? 'YES' : 'NO') . '</v></c>';
            $sheetDataRows .= '<c r="H' . $rowNum . '"><v>' . ($item['has_card'] ? 1 : 0) . '</v></c>';
            $sheetDataRows .= '<c r="I' . $rowNum . '" t="s"><v>' . $getStringIndex($item['card_type'] ?? 'normalCard') . '</v></c>';
            $sheetDataRows .= '</row>';
            $rowNum++;
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetDataRows . '</sheetData>'
            . '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $str) {
            $sstXml .= '<si><t>' . htmlspecialchars($str, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $sstXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $sstXml);

        $zip->close();
        return $this->tempWorkbookPath;
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $rows = [
            ['person_no' => '00001', 'name' => 'Alice Test', 'has_card' => true, 'card_type' => 'normalCard'],
            ['person_no' => '00002', 'name' => 'Bob Test', 'has_card' => false, 'card_type' => '-'],
        ];
        $path = $this->createMockWorkbook($rows);

        $initialEmployeeCount = Employee::count();
        $initialCredCount = CredentialRecord::count();

        $this->artisan('securegate:import-workbook-candidates', [
            '--source' => $path,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('Total Candidates in Workbook')
            ->assertExitCode(0);

        $this->assertSame($initialEmployeeCount, Employee::count());
        $this->assertSame($initialCredCount, CredentialRecord::count());
    }

    public function test_successful_import_creates_employees_and_credentials(): void
    {
        $rows = [
            ['person_no' => '00001', 'name' => 'Leading Zero Person', 'has_card' => true, 'card_type' => 'normalCard'],
            ['person_no' => 'L261072', 'name' => 'Prefix Alpha Person', 'has_card' => true, 'card_type' => 'superCard'],
            ['person_no' => 'CARDLESS-99', 'name' => 'Cardless Person', 'has_card' => false, 'card_type' => '-'],
            ['person_no' => 'UNNAMED-01', 'name' => '-', 'has_card' => true, 'card_type' => 'patrolCard'],
        ];
        $path = $this->createMockWorkbook($rows);

        $this->artisan('securegate:import-workbook-candidates', [
            '--source' => $path,
        ])
            ->expectsOutputToContain('successfully imported')
            ->assertExitCode(0);

        // Employee checks
        $this->assertDatabaseHas('employees', [
            'employee_id' => '00001',
            'hikvision_employee_no' => '00001',
            'name' => 'Leading Zero Person',
            'nik' => 'NIK-00001',
            'department' => 'UNASSIGNED',
            'employment_status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('employees', [
            'employee_id' => 'L261072',
            'hikvision_employee_no' => 'L261072',
            'name' => 'Prefix Alpha Person',
            'nik' => 'NIK-L261072',
        ]);

        $this->assertDatabaseHas('employees', [
            'employee_id' => 'UNNAMED-01',
            'name' => '-', // display name preserved as given
            'nik' => 'NIK-UNNAMED-01',
        ]);

        // Credential checks
        $this->assertDatabaseHas('credential_records', [
            'credential_number' => 'CRD-CARD-00001',
            'credential_type' => 'CARD',
            'card_number' => null,
            'masked_identifier' => 'CARD-REGISTERED-00001',
            'external_reference' => 'normalCard',
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('credential_records', [
            'credential_number' => 'CRD-CARD-L261072',
            'external_reference' => 'superCard',
        ]);

        // Cardless candidate must not have credential record
        $this->assertDatabaseMissing('credential_records', [
            'credential_number' => 'CRD-CARD-CARDLESS-99',
        ]);

        $this->assertSame(4, Employee::count());
        $this->assertSame(3, CredentialRecord::count());
    }

    public function test_existing_employees_are_not_duplicated(): void
    {
        // Pre-create employee
        $emp = Employee::create([
            'employee_id' => 'USR-EXISTING',
            'hikvision_employee_no' => 'USR-EXISTING',
            'name' => 'Existing Company Staff',
            'nik' => 'NIK-EXISTING-999',
            'department' => 'Engineering',
            'role' => 'staff',
            'status' => 'active',
            'employment_status' => 'ACTIVE',
        ]);

        $rows = [
            ['person_no' => 'USR-EXISTING', 'name' => 'Different Name From Device', 'has_card' => true, 'card_type' => 'normalCard'],
        ];
        $path = $this->createMockWorkbook($rows);

        $this->artisan('securegate:import-workbook-candidates', [
            '--source' => $path,
        ])
            ->assertExitCode(0);

        // Preserves original company name and department
        $emp->refresh();
        $this->assertSame('Existing Company Staff', $emp->name);
        $this->assertSame('Engineering', $emp->department);
        $this->assertSame(1, Employee::count());

        // Credential linked to existing employee
        $this->assertDatabaseHas('credential_records', [
            'credential_number' => 'CRD-CARD-USR-EXISTING',
            'employee_id' => $emp->id,
        ]);
    }

    public function test_transaction_rolls_back_on_integrity_violation(): void
    {
        // Pre-create conflicting NIK belonging to different employee
        Employee::create([
            'employee_id' => 'OTHER-EMP',
            'nik' => 'NIK-CONFLICT-01',
            'name' => 'Other Person',
            'department' => 'HR',
            'role' => 'staff',
            'status' => 'active',
            'employment_status' => 'ACTIVE',
        ]);

        $rows = [
            ['person_no' => 'VALID-01', 'name' => 'Valid One', 'has_card' => false],
            ['person_no' => 'CONFLICT-01', 'name' => 'Conflict Person', 'has_card' => false],
        ];
        $path = $this->createMockWorkbook($rows);

        $initialCount = Employee::count();

        $this->artisan('securegate:import-workbook-candidates', [
            '--source' => $path,
        ])
            ->expectsOutputToContain('Database transaction rolled back')
            ->assertExitCode(1);

        // Entire batch rolled back
        $this->assertSame($initialCount, Employee::count());
        $this->assertDatabaseMissing('employees', ['employee_id' => 'VALID-01']);
    }
}
