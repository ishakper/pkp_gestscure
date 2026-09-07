<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_id')->unique();
            $table->foreignId('door_id')->constrained('doors')->onDelete('cascade');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('nik')->nullable();
            $table->string('device_ip')->nullable();
            $table->string('auth_method')->nullable();
            $table->enum('verify_method', ['Fingerprint', 'Card'])->nullable();
            $table->enum('status', ['Granted', 'Denied'])->default('Granted');
            $table->enum('access_status', ['Granted', 'Denied'])->default('Granted');
            $table->string('reason')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();

            // Performance indexes for frequent log filter queries
            $table->index('door_id');
            $table->index('employee_id');
            $table->index('nik');
            $table->index('scanned_at');
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
