<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { if(!Schema::hasColumn('employees','supervisor_id')) Schema::table('employees',fn(Blueprint $t)=>$t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete()); } public function down(): void {} };
