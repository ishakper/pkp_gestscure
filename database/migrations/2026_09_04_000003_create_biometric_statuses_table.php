<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->boolean('has_fingerprint')->default(false);
            $table->boolean('fingerprint_enrolled')->default(false);
            $table->boolean('card_enrolled')->default(false);
            $table->longText('biometric_template')->nullable(); // Biometric template storage
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_statuses');
    }
};
