<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique(); // e.g. USR-1001
            $table->string('nik')->unique();         // e.g. NIK-882101
            $table->string('name');
            $table->string('card_no')->nullable()->index(); // RFID Mifare card number
            $table->string('department');
            $table->string('role')->nullable();
            $table->string('role_jabatan')->default('Staff');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
