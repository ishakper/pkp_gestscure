<?php

namespace App\Services;

use App\Models\CredentialReconciliationAudit;
use App\Models\CredentialReconciliationBatch;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HikvisionCredentialReconciliation
{
    protected string $workbookPath;
    protected string $sourceHash;
    protected bool $dryRun;
    protected string $batchId;
    protected ?int $summaryNamelessOrDummy = null;

    public function __construct(string $workbookPath, bool $dryRun = true)
    {
        if (!is_file($workbookPath)) {
            throw new \InvalidArgumentException("Workbook not found: {$workbookPath}");
        }

        $this->workbookPath = $workbookPath;
        $this->sourceHash = hash_file('sha256', $workbookPath);
        $this->dryRun = $dryRun;
        $this->batchId = (string) Str::uuid();
    }

    public function sourceHash(): string
    {
        return $this->sourceHash;
    }

    /**
     * Parse the fixed nine-column backup format.
     */
    public function parseWorkbook(): array
    {
        $workbook = IOFactory::load($this->workbookPath);
        $sheet = null;
        $headerRow = null;

        foreach ($workbook->getWorksheetIterator() as $candidate) {
            $lastProbeRow = min($candidate->getHighestRow(), 20);
            for ($rowNumber = 1; $rowNumber <= $lastProbeRow; $rowNumber++) {
                $first = trim((string) $candidate->getCell("A{$rowNumber}")->getFormattedValue());
                $second = trim((string) $candidate->getCell("B{$rowNumber}")->getFormattedValue());
                $seventh = trim((string) $candidate->getCell("G{$rowNumber}")->getFormattedValue());

                if ($first === 'No'
                    && $second === 'Employee/Person No'
                    && $seventh === 'Card Registered') {
                    $sheet = $candidate;
                    $headerRow = $rowNumber;
                    break 2;
                }
            }
        }

        if ($sheet === null || $headerRow === null) {
            throw new \RuntimeException('Workbook does not contain the expected nine-column credential table.');
        }

        $this->summaryNamelessOrDummy = $this->readSummaryMetric(
            $workbook,
            'Dummy-only employees'
        );

        $data = [];
        for ($rowNumber = $headerRow + 1; $rowNumber <= $sheet->getHighestRow(); $rowNumber++) {
            $rowData = [];
            for ($column = 1; $column <= 9; $column++) {
                // Formatted values preserve leading zeroes (for example, 00001).
                $rowData[] = $sheet->getCell([$column, $rowNumber])->getFormattedValue();
            }

            // The fixed export uses a numeric sequence in column A for each
            // device user. This excludes titles, headers, blank rows, and notes.
            if (!ctype_digit(trim((string) ($rowData[0] ?? '')))) {
                continue;
            }

            $data[] = $rowData;
        }

        return $data;
    }

    protected function readSummaryMetric($workbook, string $metric): ?int
    {
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            for ($rowNumber = 1; $rowNumber <= $sheet->getHighestRow(); $rowNumber++) {
                $label = trim((string) $sheet->getCell("A{$rowNumber}")->getFormattedValue());
                if ($label !== $metric) {
                    continue;
                }

                $value = $sheet->getCell("B{$rowNumber}")->getCalculatedValue();
                return is_numeric($value) ? (int) $value : null;
            }
        }

        return null;
    }
    public function validateSource(array $rawData): array
    {
        $validation = [
            'source_rows' => 0,
            'device_users' => 0,
            'with_card' => 0,
            'without_card' => 0,
            'normal_card' => 0,
            'super_card' => 0,
            'patrol_card' => 0,
            'nameless_or_dummy' => 0,
            'exact_duplicate_person_ids' => 0,
            'case_variant_ids' => 0,
            'duplicate_display_names' => 0,
            'inconsistent_card_rows' => 0,
            'invalid_ids' => 0,
            'records' => [],
        ];

        $seenIds = [];
        $seenFoldedIds = [];
        $seenNames = [];

        foreach ($rawData as $row) {
            $personNo = trim((string) ($row[1] ?? ''));
            $displayName = trim((string) ($row[2] ?? ''));
            $cardRegistered = strtoupper(trim((string) ($row[6] ?? ''))) === 'YES';
            $cardCount = (int) ($row[7] ?? 0);
            $cardType = trim((string) ($row[8] ?? ''));

            if ($personNo === '') {
                $validation['invalid_ids']++;
                continue;
            }

            if (isset($seenIds[$personNo])) {
                $validation['exact_duplicate_person_ids']++;
                continue;
            }

            $foldedId = strtolower($personNo);
            if (isset($seenFoldedIds[$foldedId])) {
                $validation['case_variant_ids']++;
                continue;
            }

            $seenIds[$personNo] = true;
            $seenFoldedIds[$foldedId] = true;

            $record = [
                'no' => (string) ($row[0] ?? ''),
                'person_no' => $personNo,
                'display_name' => $displayName,
                'cards' => (string) ($row[3] ?? ''),
                'status' => (string) ($row[4] ?? ''),
                'securegate_mapping' => (string) ($row[5] ?? ''),
                'card_registered' => $cardRegistered,
                'card_count' => $cardCount,
                'card_type' => $cardType,
            ];

            if ($displayName === '' || $displayName === '-') {
                $validation['nameless_or_dummy']++;
            } else {
                if (isset($seenNames[$displayName])) {
                    $validation['duplicate_display_names']++;
                }
                $seenNames[$displayName] = true;
            }

            if ($cardRegistered) {
                $validation['with_card']++;
                if ($cardCount <= 0) {
                    $validation['inconsistent_card_rows']++;
                    $record['conflict'] = 'Card registered YES but count is 0';
                } else {
                    match ($cardType) {
                        'normalCard' => $validation['normal_card']++,
                        'superCard' => $validation['super_card']++,
                        'patrolCard' => $validation['patrol_card']++,
                        default => null,
                    };
                }
            } else {
                $validation['without_card']++;
                if ($cardCount > 0) {
                    $validation['inconsistent_card_rows']++;
                    $record['conflict'] = 'Card registered NO but count > 0';
                }
            }

            $validation['source_rows']++;
            $validation['device_users']++;
            $validation['records'][] = $record;
        }

        if ($this->summaryNamelessOrDummy !== null) {
            $validation['nameless_or_dummy'] = $this->summaryNamelessOrDummy;
        }

        return $validation;
    }

    public function reconcileIdentities(array $validation): array
    {
        $reconciliation = [
            'exact_matches' => [],
            'source_only_valid' => [],
            'application_only' => [],
            'duplicate_ids' => [],
            'case_conflicts' => [],
            'nameless_source' => [],
            'invalid_source' => [],
            'credential_conflicts' => [],
        ];

        $appEmployees = Employee::all();
        $employeesByIdentifier = $appEmployees->keyBy(
            fn (Employee $employee) => (string) ($employee->hikvision_employee_no ?: $employee->employee_id)
        );
        $matchedEmployeeIds = [];

        foreach ($validation['records'] as $record) {
            if (isset($employeesByIdentifier[$record['person_no']])) {
                $employee = $employeesByIdentifier[$record['person_no']];
                $matchedEmployeeIds[] = $employee->id;

                if (isset($record['conflict'])) {
                    $reconciliation['credential_conflicts'][] = [
                        'source' => $record,
                        'employee' => $employee,
                    ];
                    continue;
                }

                $reconciliation['exact_matches'][] = [
                    'source' => $record,
                    'employee' => $employee,
                ];
            } elseif ($record['display_name'] === '' || $record['display_name'] === '-') {
                $reconciliation['nameless_source'][] = $record;
            } elseif (isset($record['conflict'])) {
                $reconciliation['credential_conflicts'][] = ['source' => $record, 'employee' => null];
            } else {
                $reconciliation['source_only_valid'][] = $record;
            }
        }

        $reconciliation['application_only'] = $appEmployees
            ->reject(fn (Employee $employee) => in_array($employee->id, $matchedEmployeeIds, true))
            ->values()
            ->all();

        return $reconciliation;
    }

    public function classifyCredential(array $record): array
    {
        if (isset($record['conflict'])) {
            return ['method' => 'review', 'status' => 'conflict'];
        }

        $displayName = trim((string) ($record['display_name'] ?? ''));
        if (array_key_exists('display_name', $record)
            && ($displayName === '' || $displayName === '-')) {
            return ['method' => 'review', 'status' => 'needs_verification'];
        }

        if ($record['card_registered'] && $record['card_count'] > 0) {
            return ['method' => 'card', 'status' => 'confirmed_from_backup'];
        }

        if (!$record['card_registered'] && $record['card_count'] === 0) {
            return ['method' => 'fingerprint', 'status' => 'expected_from_backup'];
        }

        return ['method' => 'unknown', 'status' => 'unknown'];
    }

    /**
     * Dry-run is strictly read-only. Apply only mutates exact, non-conflicting IDs.
     */
    public function reconcile(): array
    {
        $startedAt = now();

        try {
            $validation = $this->validateSource($this->parseWorkbook());
            $reconciliation = $this->reconcileIdentities($validation);
            $totalReview = count($reconciliation['credential_conflicts'])
                + count($reconciliation['nameless_source']);

            $batchAttributes = $this->batchAttributes(
                $startedAt,
                $validation,
                $reconciliation,
                0,
                0,
                0,
                $totalReview
            );

            if ($this->dryRun) {
                return [
                    'batch' => new CredentialReconciliationBatch($batchAttributes),
                    'validation' => $validation,
                    'reconciliation' => $reconciliation,
                    'mutations' => 0,
                ];
            }

            if (!$this->canApply($validation)) {
                throw new \RuntimeException(
                    'Apply blocked: duplicate or case-variant person identifiers were found.'
                );
            }

            $totalUpdated = 0;
            $totalUnchanged = 0;

            $batch = DB::transaction(function () use (
                $reconciliation,
                $batchAttributes,
                &$totalUpdated,
                &$totalUnchanged
            ) {
                $batch = CredentialReconciliationBatch::create($batchAttributes);

                foreach ($reconciliation['exact_matches'] as $match) {
                    $credential = $this->classifyCredential($match['source']);
                    $employee = $match['employee'];
                    $before = [
                        'credential_method' => $employee->credential_method,
                        'credential_status' => $employee->credential_status,
                    ];
                    $changes = [
                        'credential_method' => $credential['method'],
                        'credential_status' => $credential['status'],
                        'credential_source' => 'hikvision_backup',
                        'card_registered' => $match['source']['card_registered'],
                        'card_count' => $match['source']['card_count'],
                        'card_type' => $match['source']['card_registered']
                            ? ($match['source']['card_type'] ?: null)
                            : null,
                        'fingerprint_verified' => false,
                        'source_person_number' => $match['source']['person_no'],
                    ];

                    $employee->fill($changes);
                    if (!$employee->isDirty()) {
                        $totalUnchanged++;
                        continue;
                    }

                    $employee->last_reconciled_at = now();
                    $employee->reconciliation_batch_id = $batch->batch_id;
                    $employee->save();
                    $totalUpdated++;

                    CredentialReconciliationAudit::create([
                        'batch_id' => $batch->batch_id,
                        'employee_id' => $employee->id,
                        'source_person_number' => $match['source']['person_no'],
                        'source_display_name' => $match['source']['display_name'],
                        'source_card_status' => $match['source']['card_registered'] ? 'YES' : 'NO',
                        'source_card_count' => $match['source']['card_count'],
                        'source_card_type' => $match['source']['card_type'] ?: null,
                        'action' => 'updated',
                        'before_credential_method' => $before['credential_method'],
                        'before_credential_status' => $before['credential_status'],
                        'after_credential_method' => $credential['method'],
                        'after_credential_status' => $credential['status'],
                    ]);
                }

                $batch->update([
                    'updated_employees' => $totalUpdated,
                    'unchanged_employees' => $totalUnchanged,
                    'status' => 'success',
                    'completed_at' => now(),
                ]);

                return $batch->fresh();
            });

            return [
                'batch' => $batch,
                'validation' => $validation,
                'reconciliation' => $reconciliation,
                'mutations' => $totalUpdated,
            ];
        } catch (\Throwable $error) {
            return ['error' => $error->getMessage(), 'batch' => null];
        }
    }

    protected function batchAttributes(
        $startedAt,
        array $validation,
        array $reconciliation,
        int $totalCreated,
        int $totalUpdated,
        int $totalUnchanged,
        int $totalReview
    ): array {
        return [
            'batch_id' => $this->batchId,
            'source_filename' => basename($this->workbookPath),
            'source_sha256' => $this->sourceHash,
            'mode' => $this->dryRun ? 'dry-run' : 'apply',
            'started_at' => $startedAt,
            'operator' => auth()->user()?->name ?? 'cli',
            'source_rows' => $validation['source_rows'],
            'exact_matches' => count($reconciliation['exact_matches']),
            'source_only_valid' => count($reconciliation['source_only_valid']),
            'application_only' => count($reconciliation['application_only']),
            'nameless_source' => count($reconciliation['nameless_source']),
            'duplicate_ids' => $validation['exact_duplicate_person_ids'],
            'case_conflicts' => $validation['case_variant_ids'],
            'credential_conflicts' => count($reconciliation['credential_conflicts']),
            'card_confirmed' => $validation['with_card'],
            'fingerprint_expected' => $validation['without_card'],
            'fingerprint_verified' => 0,
            'unknown' => 0,
            'review' => $totalReview,
            'created_employees' => $totalCreated,
            'updated_employees' => $totalUpdated,
            'staged_candidates' => count($reconciliation['source_only_valid']),
            'unchanged_employees' => $totalUnchanged,
            'skipped_employees' => $totalReview,
            'door_assignments_created' => 0,
            'physical_device_requests' => 0,
            'status' => $this->dryRun ? 'pending' : 'success',
        ];
    }

    protected function canApply(array $validation): bool
    {
        return $validation['case_variant_ids'] === 0
            && $validation['exact_duplicate_person_ids'] === 0;
    }
}
