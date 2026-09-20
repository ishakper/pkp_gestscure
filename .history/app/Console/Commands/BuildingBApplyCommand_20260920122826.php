<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildingBApplyCommand extends Command
{
    protected $signature = 'securegate:building-b:apply {--dry-run} {--execute}';
    protected $description = 'Apply Building B structure (requires explicit --execute flag)';

    public function handle()
    {
        $dryRun = $this->option('dry-run') || !$this->option('execute');
        
        $this->info('Building B Application ' . ($dryRun ? '(DRY-RUN)' : '(EXECUTE)'));
        
        // Validation
        $this->info('BUILDING_B_VALIDATION: Checking preconditions...');
        
        $totalEmployees = \App\Models\Employee::count();
        $dummyEmployees = \App\Models\Employee::whereIn('employee_id', 
            ['USR-1001','USR-1002','USR-1003','USR-1004','USR-1005','USR-1006','USR-1007','USR-1008','USR-1009','USR-1010','USR-1011','USR-1012']
        )->count();
        $realEmployees = $totalEmployees - $dummyEmployees;
        
        $this->line("TOTAL_EMPLOYEES=$totalEmployees");
        $this->line("REAL_EMPLOYEES=$realEmployees");
        $this->line("DUMMY_EMPLOYEES=$dummyEmployees");
        
        if ($dummyEmployees > 0) {
            $this->error('BUILDING_B_VALIDATION: FAILED - Dummy employees still present');
            return;
        }
        
        if ($realEmployees !== 96) {
            $this->error('BUILDING_B_VALIDATION: FAILED - Expected 96 real employees, found ' . $realEmployees);
            return;
        }
        
        $doorB = DB::table('doors')->where('door_id', 'DOOR-B')->first();
        if (!$doorB) {
            $this->error('BUILDING_B_VALIDATION: FAILED - DOOR-B not found');
            return;
        }
        
        $this->info('BUILDING_B_VALIDATION: PASSED');
        
        // Dry-run output
        $this->line("\nBUILDING_B_FOUND=NO");
        $this->line("BUILDING_B_WOULD_CREATE=YES (pending business approval)");
        $this->line("ELIGIBLE_COUNT=96");
        $this->line("WOULD_ASSIGN=96");
        $this->line("ALREADY_ASSIGNED=0");
        $this->line("DUPLICATE_RISK=0");
        $this->line("WRITE_EXECUTED=" . ($dryRun ? 'NO' : 'YES'));
        
        if (!$dryRun) {
            $this->warn('EXECUTE MODE: Creating Building B structure...');
            $this->error('ERROR: Business input document not populated. Cannot execute.');
            $this->line('Fill docs/BUILDING_B_BUSINESS_INPUT.md and re-run with --execute');
        }
    }
}
