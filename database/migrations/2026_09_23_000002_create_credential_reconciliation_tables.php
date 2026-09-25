<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_reconciliation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->unique();
            $table->string('source_filename');
            $table->string('source_sha256');
            $table->enum('mode', ['dry-run', 'apply']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('operator')->nullable();
            
            // Counts
            $table->integer('source_rows')->default(0);
            $table->integer('exact_matches')->default(0);
            $table->integer('source_only_valid')->default(0);
            $table->integer('application_only')->default(0);
            $table->integer('nameless_source')->default(0);
            $table->integer('duplicate_ids')->default(0);
            $table->integer('case_conflicts')->default(0);
            $table->integer('credential_conflicts')->default(0);
            
            // Credential breakdown
            $table->integer('card_confirmed')->default(0);
            $table->integer('fingerprint_expected')->default(0);
            $table->integer('fingerprint_verified')->default(0);
            $table->integer('unknown')->default(0);
            $table->integer('review')->default(0);
            
            // Mutations
            $table->integer('created_employees')->default(0);
            $table->integer('updated_employees')->default(0);
            $table->integer('staged_candidates')->default(0);
            $table->integer('unchanged_employees')->default(0);
            $table->integer('skipped_employees')->default(0);
            $table->integer('door_assignments_created')->default(0);
            $table->integer('physical_device_requests')->default(0);
            
            // Status
            $table->enum('status', ['pending', 'success', 'partial', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('rollback_data')->nullable();
            
            $table->timestamps();
            $table->index('batch_id');
            $table->index('status');
        });

        Schema::create('credential_reconciliation_audits', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id');
            $table->foreignId('employee_id')->nullable()->constrained()->onDelete('set null');
            $table->string('source_person_number')->nullable();
            $table->string('source_display_name')->nullable();
            $table->string('source_card_status')->nullable();
            $table->integer('source_card_count')->default(0);
            $table->string('source_card_type')->nullable();
            
            $table->enum('action', ['created', 'updated', 'unchanged', 'skipped', 'review']);
            
            $table->string('before_credential_method')->nullable();
            $table->string('before_credential_status')->nullable();
            $table->string('after_credential_method')->nullable();
            $table->string('after_credential_status')->nullable();
            
            $table->text('conflict_reason')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->foreign('batch_id')
                  ->references('batch_id')
                  ->on('credential_reconciliation_batches')
                  ->onDelete('cascade');
            $table->index(['batch_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_reconciliation_audits');
        Schema::dropIfExists('credential_reconciliation_batches');
    }
};
