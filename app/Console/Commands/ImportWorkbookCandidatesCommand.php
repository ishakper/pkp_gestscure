<?php

namespace App\Console\Commands;

use App\Models\CredentialRecord;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ImportWorkbookCandidatesCommand extends Command
{
    protected $signature = 'securegate:import-workbook-candidates
                            {--source= : Absolute path to the Excel workbook (.xlsx)}
                            {--dry-run : Preview import candidate actions without modifying the database}';

    protected $description = 'Safely reconcile and import physical user candidates and card metadata from offline backup workbook';

    public function handle(): int
    {
        $sourcePath = $this->option('source') ?: 'D:\Magang\Project\securegate-backup-20260915\SecureGate_back up.xlsx';
        $dryRun = (bool) $this->option('dry-run');

        $this->info("SecureGate Workbook Candidate Reconciliation");
        $this->line("Source workbook: {$sourcePath}");
        $this->line("Mode: " . ($dryRun ? "DRY-RUN (read-only preview)" : "APPLY (database transaction)"));

        if (!file_exists($sourcePath)) {
            $this->error("Workbook file not found: {$sourcePath}");
            return Command::FAILURE;
        }

        $candidates = $this->parseWorkbook($sourcePath);
        if ($candidates === null) {
            $this->error("Failed to parse candidates from workbook.");
            return Command::FAILURE;
        }

        $totalCandidates = count($candidates);
        $this->info("Parsed {$totalCandidates} candidate rows from workbook.");

        if ($totalCandidates !== 96) {
            $this->warn("Expected 96 candidate rows, found {$totalCandidates}. Proceeding with strict validation.");
        }

        // Validate uniqueness of Person No within workbook
        $personNos = array_column($candidates, 'person_no');
        $duplicatePersonNos = array_filter(array_count_values($personNos), fn($c) => $c > 1);
        if (!empty($duplicatePersonNos)) {
            $this->error("Duplicate person numbers found in workbook: " . implode(', ', array_keys($duplicatePersonNos)));
            return Command::FAILURE;
        }

        $existingEmployees = Employee::all()->keyBy('employee_id');
        $existingNiks = Employee::pluck('nik')->all();
        $existingCredNumbers = CredentialRecord::pluck('credential_number')->all();

        $stats = [
            'candidates_total' => $totalCandidates,
            'existing_matched' => 0,
            'new_candidates' => 0,
            'card_presence_known' => 0,
            'card_presence_absent' => 0,
            'card_identities_verified' => 0,
            'credentials_created' => 0,
            'card_types' => [],
        ];

        $actions = [];

        foreach ($candidates as $c) {
            $pNo = (string) $c['person_no'];
            $name = $c['name'];
            $hasCard = (bool) $c['has_card'];
            $cardType = $c['card_type'];

            if ($hasCard) {
                $stats['card_presence_known']++;
                $stats['card_types'][$cardType] = ($stats['card_types'][$cardType] ?? 0) + 1;
            } else {
                $stats['card_presence_absent']++;
            }

            $existing = $existingEmployees->get($pNo);
            if ($existing) {
                $stats['existing_matched']++;
                $actions[] = [
                    'person_no' => $pNo,
                    'name' => $existing->name,
                    'action' => 'MATCHED_EXISTING',
                    'card_present' => $hasCard ? 'YES' : 'NO',
                    'card_type' => $cardType,
                ];
            } else {
                $stats['new_candidates']++;
                $actions[] = [
                    'person_no' => $pNo,
                    'name' => $name,
                    'action' => 'CREATE_CANDIDATE',
                    'card_present' => $hasCard ? 'YES' : 'NO',
                    'card_type' => $cardType,
                ];
            }
        }

        if ($dryRun) {
            $this->displaySummary($stats, $actions, true);
            $this->info("Dry-run complete. Database unchanged.");
            return Command::SUCCESS;
        }

        // Apply within single transaction
        try {
            DB::transaction(function () use ($candidates, $existingEmployees, &$stats) {
                foreach ($candidates as $c) {
                    $pNo = (string) $c['person_no'];
                    $displayName = $c['name'];
                    $hasCard = (bool) $c['has_card'];
                    $cardType = $c['card_type'];

                    $emp = $existingEmployees->get($pNo) ?: Employee::create([
                        'employee_id' => $pNo,
                        'hikvision_employee_no' => $pNo,
                        'name' => $displayName !== '' ? $displayName : "Physical User {$pNo}",
                        'nik' => 'UNVERIFIED-NIK-' . $pNo,
                        'department' => 'UNASSIGNED',
                        'role' => 'staff',
                        'role_jabatan' => 'Staff',
                        'employment_status' => 'ACTIVE',
                    ]);

                    // Classify and apply credential status based on Hikvision backup data
                    $credMethod = 'unknown';
                    $credStatus = 'unknown';
                    
                    // Nameless override: If display_name is empty or '-', mark for review
                    if ($displayName === '' || $displayName === '-') {
                        $credMethod = 'review';
                        $credStatus = 'needs_verification';
                    } elseif ($hasCard) {
                        $credMethod = 'card';
                        $credStatus = 'confirmed_from_backup';
                    }

                    $emp->update([
                        'credential_method' => $credMethod,
                        'credential_status' => $credStatus,
                        'card_registered' => $hasCard,
                        'card_type' => $hasCard ? $cardType : null,
                        'source_person_number' => $pNo,
                        'last_reconciled_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            $this->error("Database transaction rolled back due to error: " . $e->getMessage());
            return Command::FAILURE;
        }

        $this->displaySummary($stats, $actions, false);
        $this->info("Workbook candidate identities successfully reconciled without fabricating card records.");
        return Command::SUCCESS;
    }

    /**
     * Parse raw OpenXML XLSX workbook without external spreadsheet package.
     */
    protected function parseWorkbook(string $filePath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $strings = [];
        $stringsXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXmlContent !== false) {
            $stringsXml = simplexml_load_string($stringsXmlContent);
            if ($stringsXml) {
                foreach ($stringsXml->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $strings[] = $text;
                    } else {
                        $strings[] = '';
                    }
                }
            }
        }

        $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXmlContent === false) {
            $zip->close();
            return null;
        }

        $sheetXml = simplexml_load_string($sheetXmlContent);
        $zip->close();

        if (!$sheetXml) {
            return null;
        }

        $candidates = [];
        foreach ($sheetXml->sheetData->row as $r) {
            $rowCells = [];
            foreach ($r->c as $c) {
                $cellRef = (string) $c['r'];
                $col = preg_replace('/[0-9]/', '', $cellRef);
                $type = (string) $c['t'];
                $val = (string) $c->v;
                if ($type === 's') {
                    $val = $strings[(int) $val] ?? '';
                }
                $rowCells[$col] = $val;
            }

            // Candidate data rows have numeric index in Column A
            if (isset($rowCells['A']) && is_numeric($rowCells['A'])) {
                $personNo = trim((string) ($rowCells['B'] ?? ''));
                $displayName = trim((string) ($rowCells['C'] ?? ''));
                $hasCard = strtoupper(trim((string) ($rowCells['G'] ?? ''))) === 'YES';
                $cardType = trim((string) ($rowCells['I'] ?? 'normalCard'));
                if ($cardType === '' || $cardType === '-') {
                    $cardType = 'normalCard';
                }

                if ($personNo !== '') {
                    $candidates[] = [
                        'index' => (int) $rowCells['A'],
                        'person_no' => $personNo,
                        'name' => $displayName,
                        'has_card' => $hasCard,
                        'card_type' => $cardType,
                    ];
                }
            }
        }

        return $candidates;
    }

    protected function displaySummary(array $stats, array $actions, bool $isDryRun): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Candidates in Workbook', $stats['candidates_total']],
                ['Matched Existing Employees', $stats['existing_matched']],
                ['New Candidates To Create', $stats['new_candidates']],
                ['Card Presence Known (Flag=YES)', $stats['card_presence_known']],
                ['Card Presence Absent (Flag=NO)', $stats['card_presence_absent']],
                ['Card Identities Verified', $stats['card_identities_verified']],
                ['Credentials Synthesized / Created', $stats['credentials_created']],
                ['Card Types Distribution', json_encode($stats['card_types'])],
            ]
        );

        $sample = array_slice($actions, 0, 15);
        $sampleRows = array_map(function ($a) {
            return [
                $a['person_no'],
                $a['name'],
                $a['action'],
                $a['card_present'],
                $a['card_type'],
            ];
        }, $sample);

        $this->line("\nSample Candidate Actions (First 15 of " . count($actions) . "):");
        $this->table(['Person No', 'Name', 'Action', 'Card Present', 'Card Type'], $sampleRows);
    }
}
