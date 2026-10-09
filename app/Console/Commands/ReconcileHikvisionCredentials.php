<?php

namespace App\Console\Commands;

use App\Services\HikvisionCredentialReconciliation;
use Illuminate\Console\Command;

class ReconcileHikvisionCredentials extends Command
{
    protected $signature = 'securegate:reconcile-credentials
        {workbook : Path to Hikvision backup workbook}
        {--dry-run : Run in dry-run mode (default)}
        {--apply : Apply exact non-conflicting matches}
        {--confirm-sha256= : Required source SHA-256 confirmation for apply mode}
        {--report= : Save report to file}';

    protected $description = 'Reconcile employee credentials from a verified Hikvision backup workbook';

    public function handle(): int
    {
        $workbookPath = (string) $this->argument('workbook');
        $dryRun = !$this->option('apply');

        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');
            return self::FAILURE;
        }

        if (!is_file($workbookPath)) {
            $this->error("Workbook not found: {$workbookPath}");
            return self::FAILURE;
        }

        try {
            $reconciliation = new HikvisionCredentialReconciliation($workbookPath, $dryRun);
            $sourceHash = strtoupper($reconciliation->sourceHash());

            $this->info('Starting credential reconciliation...');
            $this->info('Mode: '.($dryRun ? 'DRY-RUN' : 'APPLY'));
            $this->info("Workbook: {$workbookPath}");
            $this->info("Source SHA256: {$sourceHash}");
            $this->line('');

            if (!$dryRun) {
                $confirmedHash = strtoupper((string) $this->option('confirm-sha256'));
                if ($confirmedHash === '' || !hash_equals($sourceHash, $confirmedHash)) {
                    $this->error('Apply requires --confirm-sha256 matching the workbook.');
                    return self::FAILURE;
                }
            }

            $result = $reconciliation->reconcile();
            if (isset($result['error'])) {
                $this->error('Error: '.$result['error']);
                return self::FAILURE;
            }

            $batch = $result['batch'];
            $validation = $result['validation'];
            $recon = $result['reconciliation'];

            $this->line('========== SOURCE VALIDATION ==========');
            $this->info("Source rows: {$validation['source_rows']}");
            $this->info("Device users: {$validation['device_users']}");
            $this->info("With card: {$validation['with_card']}");
            $this->info("Without card: {$validation['without_card']}");
            $this->info("Normal cards: {$validation['normal_card']}");
            $this->info("Super cards: {$validation['super_card']}");
            $this->info("Patrol cards: {$validation['patrol_card']}");
            $this->info("Nameless or dummy: {$validation['nameless_or_dummy']}");
            $this->info("Invalid IDs: {$validation['invalid_ids']}");
            $this->line('');

            $this->line('========== IDENTITY RECONCILIATION ==========');
            $this->info('Exact matches: '.count($recon['exact_matches']));
            $this->info('Application only: '.count($recon['application_only']));
            $this->info('Source only: '.count($recon['source_only_valid']));
            $this->info('Nameless: '.count($recon['nameless_source']));
            $this->warn('Credential conflicts: '.count($recon['credential_conflicts']));
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
            $this->info("Unchanged: {$batch->unchanged_employees}");
            $this->info("Staged candidates: {$batch->staged_candidates}");
            $this->info("Skipped (review): {$batch->skipped_employees}");
            $this->info("Employee mutations: {$result['mutations']}");
            $this->line('');

            $this->line('========== SAFETY ==========');
            $this->info("Door assignments: {$batch->door_assignments_created}");
            $this->info("Physical device requests: {$batch->physical_device_requests}");
            $this->line('');
            $this->line("Batch ID: {$batch->batch_id}");

            if ($this->option('report')) {
                $this->saveReport($batch, $validation, $recon, (string) $this->option('report'));
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }

    protected function saveReport($batch, array $validation, array $recon, string $path): void
    {
        $report = view('reconciliation.report', [
            'batch' => $batch,
            'validation' => $validation,
            'reconciliation' => $recon,
        ])->render();
        file_put_contents($path, $report);
        $this->info("Report saved to: {$path}");
    }
}
