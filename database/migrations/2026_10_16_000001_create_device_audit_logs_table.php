<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('door_assignment_id')
                ->constrained('door_assignments')
                ->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->jsonb('payload_data');
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Deliberately non-unique. One-row-per-hash is enforced by PostgreSQL advisory locking.
            $table->index('payload_hash', 'device_audit_logs_payload_hash_index');
            $table->index(['status', 'created_at'], 'device_audit_logs_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_audit_logs');
    }
};
