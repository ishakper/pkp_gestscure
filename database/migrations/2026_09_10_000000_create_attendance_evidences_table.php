<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_evidences', function (Blueprint $table) {
            $table->id();

            // Link back to immutable AccessLog
            $table->unsignedBigInteger('access_log_id')->unique();

            // Normalized data
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('door_id')->nullable()->index();

            $table->datetime('event_timestamp');
            $table->string('direction')->default('UNKNOWN'); // ENTRY, EXIT, UNKNOWN
            $table->string('credential_type')->nullable(); // Card, Fingerprint, Face, Pin
            $table->string('hardware_serial')->nullable();

            $table->string('status')->default('MAPPED'); // MAPPED, UNMATCHED, DENIED

            $table->timestamps();

            // Constraints
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            // Since access_logs id might not be auto-increment or has a different type, we just index it if not strict foreign key, but it is standard id.
            $table->foreign('access_log_id')->references('id')->on('access_logs')->cascadeOnDelete();
            $table->foreign('door_id')->references('id')->on('doors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_evidences');
    }
};
