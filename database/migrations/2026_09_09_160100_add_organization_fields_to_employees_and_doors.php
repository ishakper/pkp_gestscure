<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // SQLite can retain columns from a failed ALTER TABLE. Each field is guarded so
        // a restart safely resumes this additive migration without altering old records.
        $addEmployee = function (string $column, \Closure $definition): void { if (!Schema::hasColumn('employees', $column)) Schema::table('employees', $definition); };
        $addEmployee('building_id', fn (Blueprint $t) => $t->foreignId('building_id')->nullable()->constrained()->nullOnDelete());
        $addEmployee('division_id', fn (Blueprint $t) => $t->foreignId('division_id')->nullable()->constrained()->nullOnDelete());
        $addEmployee('position_id', fn (Blueprint $t) => $t->foreignId('position_id')->nullable()->constrained()->nullOnDelete());
        $addEmployee('email', fn (Blueprint $t) => $t->string('email')->nullable()->unique());
        $addEmployee('phone', fn (Blueprint $t) => $t->string('phone')->nullable());
        $addEmployee('photo_path', fn (Blueprint $t) => $t->string('photo_path')->nullable());
        $addEmployee('employment_type', fn (Blueprint $t) => $t->string('employment_type')->nullable());
        $addEmployee('employment_status', fn (Blueprint $t) => $t->string('employment_status')->default('ACTIVE'));
        $addEmployee('hire_date', fn (Blueprint $t) => $t->date('hire_date')->nullable());
        $addEmployee('hikvision_employee_no', fn (Blueprint $t) => $t->string('hikvision_employee_no')->nullable()->unique());
        DB::table('employees')->whereNull('hikvision_employee_no')->update(['hikvision_employee_no' => DB::raw('employee_id')]);
        $addDoor = function (string $column, \Closure $definition): void { if (!Schema::hasColumn('doors', $column)) Schema::table('doors', $definition); };
        $addDoor('building_id', fn (Blueprint $t) => $t->foreignId('building_id')->nullable()->constrained()->nullOnDelete());
        $addDoor('zone_id', fn (Blueprint $t) => $t->foreignId('zone_id')->nullable()->constrained()->nullOnDelete());
    }
    public function down(): void { /* Additive production migration: rollback is intentionally not automated. */ }
};
