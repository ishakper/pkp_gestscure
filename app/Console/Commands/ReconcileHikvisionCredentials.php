<?php

namespace App\Console\Commands;

use App\Services\HikvisionCredentialReconciliation;
use Illuminate\Console\Command;

class ReconcileHikvisionCredentials extends Command
{
    protected $signature = 'securegate:reconcile-credentials 
        {workbook : Path to Hikvision backup workbook}
        {--dry-run : Run in dry-run mode (default)}
        {--apply : Apply changes to local database}
        {--report= : Save report to file}';

    protected $description = 'Reconcile employee credentials from Hikvision backup workbook';

    public function handle()
    {
        $workbookPath = $this->argument('workbook');
        $dryRun = !$this->option('apply');
        $reportPath = $this->option('report');

        if (!file_exists($workbookPath)) {
            $this->error("Workbook not found: $workbookPath");
            return 1;
        }

        $this->info('Starting credential reconciliation...');
        $this->info("Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY'));
        $this->info("Workbook: $workbookPath");
        $this->line('');

        try {
            $reconciliation = new HikvisionCredentialReconciliation($workbookPath, $dryRun);
            $result = $reconciliation->reconcile();

            if (isset($result['error'])) {
                $this->error("Error: " . $result['error']);
                return 1;
            }

            $batch = $result['batch'];
            $validation = $result['validation'];
            $recon = $result['reconciliation'];

            // Display results
            $this->line('========== SOURCE VALIDATION ==========');
            $this->info("Source rows: {$validation['source_rows']}");
            $this->info("Device users: {$validation['device_users']}");
            $this->info("With card: {$validation['with_card']}");
            $this->info("Without card: {$validation['without_card']}");
            $this->info("Normal cards: {$validation['normal_card']}");
            $this->info("Super cards: {$validation['super_card']}");
            $this->info("Patrol cards: {$validation['patrol_card']}");
            $this->line('');

            $this->line('========== IDENTITY RECONCILIATION ==========');
            $this->info("Exact matches: " . count($recon['exact_matches']));
            $this->info("Source only: " . count($recon['source_only_valid']));
            $this->info("Nameless: " . count($recon['nameless_source']));
            $this->warn("Credential conflicts: " . count($recon['credential_conflicts']));
            $this->warn("Case conflicts: {$validation['case_variant_ids']}");
            $this->warn("Duplicate IDs: {$validation['exact_duplicate_person_ids']}");
            $this->line('');

            $this->line('========== CREDENTIAL CLASSIFICATION ==========');
            $this->info("Card confirmed: {$batch->card_confirmed}");
            $this->info("Fingerprint expected: {$batch->fingerprint_expected}");
            $this->info("Fingerprint verified: {$batch->fingerprint_verified}");
            $this->line('');

            $this->line('========== MUTATIONS ==========');
            $this->info("Updated: {$batch->updated_employees}");
            $this->info("Staged candidates: {$batch->staged_candidates}");
            $this->info("Skipped (review): {$batch->skipped_employees}");
            $this->line('');

            $this->line('========== SAFETY ==========');
            $this->info("Door assignments: {$batch->door_assignments_created}");
            $this->info("Physical device requests: {$batch->physical_device_requests}");
            $this->line('');

            $this->line("Batch ID: {$batch->batch_id}");

            if ($reportPath) {
                $this->saveReport($batch, $validation, $recon, $reportPath);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    protected function saveReport($batch, $validation, $recon, $path)
    {
        $report = view('reconciliation.report', [
            'batch' => $batch,
            'validation' => $validation,
            'reconciliation' => $recon,
        ])->render();

        file_put_contents($path, $report);
        $this->info("Report saved to: $path");
    }
}
