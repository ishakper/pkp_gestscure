<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\CredentialReconciliationBatch;
use App\Models\CredentialReconciliationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HikvisionCredentialReconciliation
{
    protected $workbookPath;
    protected $sourceHash;
    protected $dryRun;
    protected $batchId;
    protected $results = [];

    public function __construct($workbookPath, $dryRun = true)
    {
        if (!file_exists($workbookPath)) {
            throw new \Exception("Workbook not found: $workbookPath");
        }

        $this->workbookPath = $workbookPath;
        $this->sourceHash = hash_file('sha256', $workbookPath);
        $this->dryRun = $dryRun;
        $this->batchId = Str::uuid()->toString();
    }

    /**
     * Parse workbook using built-in ZipArchive + DOMDocument (no external dependencies)
     * Handles shared strings properly for modern XLSX format.
     */
    public function parseWorkbook()
    {
        $zip = new \ZipArchive();
        
        if ($zip->open($this->workbookPath) !== true) {
            throw new \Exception("Cannot open workbook as ZIP: " . $this->workbookPath);
        }

        // 1. Load shared strings (if present)
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ssDom = new \DOMDocument();
            $ssDom->loadXML($ssXml);
            $ssElements = $ssDom->getElementsByTagName('si');
            foreach ($ssElements as $si) {
                $tNode = $si->getElementsByTagName('t')->item(0);
                $sharedStrings[] = $tNode ? $tNode->textContent : '';
            }
        }

        // 2. Load sheet1
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $zip->close();
            throw new \Exception("No sheet1.xml found in workbook");
        }

        $dom = new \DOMDocument();
        $dom->loadXML($sheetXml);
        $rows = $dom->getElementsByTagName('row');

        $data = [];
        foreach ($rows as $rowNode) {
            $rowData = [];
            $cells = $rowNode->getElementsByTagName('c');
            
            foreach ($cells as $cellNode) {
                $t = $cellNode->getAttribute('t'); // Cell type: s=shared string, n=number
                $vNode = $cellNode->getElementsByTagName('v')->item(0);
                $value = '';
                
                if ($t === 's' && $vNode) {
                    // Shared string reference (index into sharedStrings array)
                    $idx = intval($vNode->textContent);
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($vNode) {
                    // Direct value (number, etc.)
                    $value = $vNode->textContent;
                }
                
                $rowData[] = $value;
            }
            
            // Only include non-empty rows
            if (array_filter($rowData)) {
                $data[] = $rowData;
            }
        }

        $zip->close();
        return $data;
    }

    /**
     * Validate and count source records
     */
    public function validateSource($rawData)
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
            'records' => []
        ];

        $exactIds = [];
        $foldedIds = [];
        $seenNames = [];

        // Auto-detect header row: skip rows until we find "No", "Employee/Person No", "Display Name"
        $headerIdx = 0;
        foreach ($rawData as $idx => $row) {
            if (!empty($row[0]) && in_array(trim($row[0]), ['No', 'INDEX'])) {
                $headerIdx = $idx + 1; // Start processing from next row
                break;
            }
        }

        foreach ($rawData as $idx => $row) {
            if ($idx < $headerIdx) continue; // Skip everything before data rows

            $record = [
                'no' => $row[0] ?? '',
                'person_no' => (string)($row[1] ?? ''),
                'display_name' => trim($row[2] ?? ''),
                'cards' => $row[3] ?? '',
                'status' => $row[4] ?? '',
                'securegate_mapping' => $row[5] ?? '',
                'card_registered' => strtoupper(trim($row[6] ?? '')) === 'YES',
                'card_count' => (int)($row[7] ?? 0),
                'card_type' => $row[8] ?? '',
            ];

            // Validate person_no
            if (empty($record['person_no'])) {
                $validation['invalid_ids']++;
                continue;
            }

            $personNumber = $record['person_no'];
            $exactKey = "id\0" . $personNumber;
            $foldKey = "fold\0" . mb_strtolower($personNumber, 'UTF-8');

            // Check exact duplicates before storing current ID.
            if (isset($exactIds[$exactKey])) {
                $validation['exact_duplicate_person_ids']++;
                continue;
            }
            // Check case-folded collisions without changing original ID value.
            if (isset($foldedIds[$foldKey])) {
                $validation['case_variant_ids']++;
                continue;
            }
            $exactIds[$exactKey] = true;
            $foldedIds[$foldKey] = true;

            // Check nameless
            if ($record['display_name'] === '-' || empty($record['display_name'])) {
                $validation['nameless_or_dummy']++;
            } else {
                if (isset($seenNames[$record['display_name']])) {
                    $validation['duplicate_display_names']++;
                }
                $seenNames[$record['display_name']] = true;
            }

            // Validate card consistency
            if ($record['card_registered']) {
                $validation['with_card']++;
                if ($record['card_count'] <= 0) {
                    $validation['inconsistent_card_rows']++;
                    $record['conflict'] = 'Card registered YES but count is 0';
                } else {
                    match($record['card_type']) {
                        'normalCard' => $validation['normal_card']++,
                        'superCard' => $validation['super_card']++,
                        'patrolCard' => $validation['patrol_card']++,
                    };
                }
            } else {
                $validation['without_card']++;
                if ($record['card_count'] > 0) {
                    $validation['inconsistent_card_rows']++;
                    $record['conflict'] = 'Card registered NO but count > 0';
                }
            }

            $validation['source_rows']++;
            $validation['device_users']++;
            $validation['records'][] = $record;
        }

        return $validation;
    }

    /**
     * Reconcile employee identities
     */
    public function reconcileIdentities($validation)
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

        $appEmployees = Employee::all()->keyBy('hikvision_employee_no');

        foreach ($validation['records'] as $record) {
            if (isset($appEmployees[$record['person_no']])) {
                $reconciliation['exact_matches'][] = [
                    'source' => $record,
                    'employee' => $appEmployees[$record['person_no']]
                ];
            } elseif ($record['display_name'] === '-') {
                $reconciliation['nameless_source'][] = $record;
            } elseif (isset($record['conflict'])) {
                $reconciliation['credential_conflicts'][] = $record;
            } else {
                $reconciliation['source_only_valid'][] = $record;
            }
        }

        return $reconciliation;
    }

    /**
     * Classify credential method for a record
     */
    public function classifyCredential($record)
    {
        $method = 'unknown';
        $status = 'unknown';

        if ($record['card_registered'] && $record['card_count'] > 0) {
            $method = 'card';
            $status = 'confirmed_from_backup';
        } elseif (!$record['card_registered'] && $record['card_count'] === 0) {
            $method = 'fingerprint';
            $status = 'expected_from_backup'; // NOT verified without explicit proof
        } elseif (isset($record['conflict'])) {
            $method = 'review';
            $status = 'conflict';
        }

        return compact('method', 'status');
    }

    /**
     * Execute dry-run or apply
     */
    public function reconcile()
    {
        $startedAt = now();

        try {
            // Parse workbook
            $rawData = $this->parseWorkbook();
            $validation = $this->validateSource($rawData);
            $reconciliation = $this->reconcileIdentities($validation);

            // Calculate totals
            $totalCreated = count($reconciliation['source_only_valid']);
            $totalUpdated = count($reconciliation['exact_matches']);
            $totalReview = count($reconciliation['credential_conflicts']) + count($reconciliation['nameless_source']);

            // Create batch record
            $batch = $this->createBatchRecord(
                $startedAt,
                $validation,
                $reconciliation,
                $totalCreated,
                $totalUpdated,
                $totalReview
            );

            // If apply mode and all checks pass, mutate database
            if (!$this->dryRun && $this->canApply($validation, $reconciliation)) {
                DB::transaction(function () use ($reconciliation, $batch) {
                    // Update exact matches
                    foreach ($reconciliation['exact_matches'] as $match) {
                        $credential = $this->classifyCredential($match['source']);
                        $employee = $match['employee'];

                        $before = [
                            'credential_method' => $employee->credential_method,
                            'credential_status' => $employee->credential_status,
                        ];

                        $cardType = $match['source']['card_registered'] ? ($match['source']['card_type'] ?? 'normalCard') : null;

                        $employee->update([
                            'credential_method' => $credential['method'],
                            'credential_status' => $credential['status'],
                            'credential_source' => 'hikvision_backup',
                            'card_registered' => $match['source']['card_registered'],
                            'card_count' => $match['source']['card_count'],
                            'card_type' => $cardType,
                            'source_person_number' => $match['source']['person_no'],
                            'last_reconciled_at' => now(),
                            'reconciliation_batch_id' => $batch->batch_id,
                        ]);

                        CredentialReconciliationAudit::create([
                            'batch_id' => $batch->batch_id,
                            'employee_id' => $employee->id,
                            'source_person_number' => $match['source']['person_no'],
                            'source_display_name' => $match['source']['display_name'],
                            'source_card_status' => $match['source']['card_registered'] ? 'YES' : 'NO',
                            'source_card_count' => $match['source']['card_count'],
                            'source_card_type' => $match['source']['card_type'],
                            'action' => 'updated',
                            'before_credential_method' => $before['credential_method'],
                            'before_credential_status' => $before['credential_status'],
                            'after_credential_method' => $credential['method'],
                            'after_credential_status' => $credential['status'],
                        ]);
                    }
                });

                $batch->update([
                    'status' => 'success',
                    'completed_at' => now(),
                ]);
            }

            return [
                'batch' => $batch,
                'validation' => $validation,
                'reconciliation' => $reconciliation,
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'batch' => null,
            ];
        }
    }

    /**
     * Create batch record
     */
    protected function createBatchRecord($startedAt, $validation, $reconciliation, $totalCreated, $totalUpdated, $totalReview)
    {
        return CredentialReconciliationBatch::create([
            'batch_id' => $this->batchId,
            'source_filename' => basename($this->workbookPath),
            'source_sha256' => $this->sourceHash,
            'mode' => $this->dryRun ? 'dry-run' : 'apply',
            'started_at' => $startedAt,
            'operator' => auth()->user()?->name ?? 'cli',
            'source_rows' => $validation['source_rows'],
            'exact_matches' => count($reconciliation['exact_matches']),
            'source_only_valid' => count($reconciliation['source_only_valid']),
            'application_only' => 0, // Will need separate query
            'nameless_source' => count($reconciliation['nameless_source']),
            'duplicate_ids' => $validation['exact_duplicate_person_ids'],
            'case_conflicts' => $validation['case_variant_ids'],
            'credential_conflicts' => count($reconciliation['credential_conflicts']),
            'card_confirmed' => $validation['with_card'],
            'fingerprint_expected' => $validation['without_card'],
            'fingerprint_verified' => 0,
            'created_employees' => $this->dryRun ? 0 : $totalCreated,
            'updated_employees' => $this->dryRun ? 0 : $totalUpdated,
            'unchanged_employees' => 0,
            'skipped_employees' => $totalReview,
            'door_assignments_created' => 0, // Permission mapping not available
            'physical_device_requests' => 0,
            'status' => $this->dryRun ? 'pending' : 'success',
        ]);
    }

    /**
     * Check if apply is safe
     */
    protected function canApply($validation, $reconciliation)
    {
        // Must not have conflicts
        if (count($reconciliation['credential_conflicts']) > 0) {
            return false;
        }

        // Must not have case variants
        if ($validation['case_variant_ids'] > 0) {
            return false;
        }

        // Must not have duplicate IDs
        if ($validation['exact_duplicate_person_ids'] > 0) {
            return false;
        }

        return true;
    }
}
