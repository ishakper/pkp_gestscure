<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->after('division_id')->constrained()->nullOnDelete();
            $table->string('email')->nullable()->unique()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('photo_path')->nullable()->after('phone');
            $table->string('employment_type')->nullable()->after('department');
            $table->string('employment_status')->default('ACTIVE')->after('employment_type');
            $table->date('hire_date')->nullable()->after('employment_status');
            $table->string('hikvision_employee_no')->nullable()->unique()->after('employee_id');
        });
        DB::table('employees')->whereNull('hikvision_employee_no')->update(['hikvision_employee_no' => DB::raw('employee_id')]);
        Schema::table('doors', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('location')->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
        });
    }
    public function down(): void
    {
        DB::table('employees')->whereNull('hikvision_employee_no')->update(['hikvision_employee_no' => DB::raw('employee_id')]);
        Schema::table('doors', function (Blueprint $table) { $table->dropConstrainedForeignId('zone_id'); $table->dropConstrainedForeignId('building_id'); });
        Schema::table('employees', function (Blueprint $table) { $table->dropUnique(['email']); $table->dropUnique(['hikvision_employee_no']); $table->dropColumn(['email','phone','photo_path','employment_type','employment_status','hire_date','hikvision_employee_no']); $table->dropConstrainedForeignId('position_id'); $table->dropConstrainedForeignId('division_id'); $table->dropConstrainedForeignId('building_id'); });
    }
};
