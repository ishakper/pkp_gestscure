<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{if(!Schema::hasColumn('admins','employee_id'))Schema::table('admins',fn(Blueprint $t)=>$t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete());}public function down():void{}};
