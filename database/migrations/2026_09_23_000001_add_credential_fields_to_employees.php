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
        Schema::table('employees', function (Blueprint $table) {
            // Credential method field
            $table->enum('credential_method', [
                'card',
                'fingerprint',
                'unknown',
                'review'
            ])->default('unknown')->nullable()->after('card_no');

            // Credential status from backup or app
            $table->enum('credential_status', [
                'confirmed_from_backup',
                'expected_from_backup',
                'verified',
                'conflict',
                'needs_verification',
                'unknown'
            ])->default('unknown')->nullable()->after('credential_method');

            // Credential source
            $table->enum('credential_source', [
                'hikvision_backup',
                'application',
                'manual_verified'
            ])->nullable()->after('credential_status');

            // Card-related metadata from backup
            $table->boolean('card_registered')->default(false)->nullable()->after('credential_source');
            $table->integer('card_count')->default(0)->nullable()->after('card_registered');
            $table->enum('card_type', [
                'normalCard',
                'superCard',
                'patrolCard'
            ])->nullable()->after('card_count');

            // Fingerprint status (never auto-set to verified without proof)
            $table->boolean('fingerprint_verified')->default(false)->nullable()->after('card_type');

            // Source reconciliation tracking
            $table->string('source_person_number')->nullable()->after('fingerprint_verified');
            $table->timestamp('last_reconciled_at')->nullable()->after('source_person_number');
            $table->string('reconciliation_batch_id')->nullable()->after('last_reconciled_at');

            // Indexes
            $table->index('credential_method');
            $table->index('credential_status');
            $table->index('source_person_number');
            $table->index('reconciliation_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['credential_method']);
            $table->dropIndex(['credential_status']);
            $table->dropIndex(['source_person_number']);
            $table->dropIndex(['reconciliation_batch_id']);

            $table->dropColumn([
                'credential_method',
                'credential_status',
                'credential_source',
                'card_registered',
                'card_count',
                'card_type',
                'fingerprint_verified',
                'source_person_number',
                'last_reconciled_at',
                'reconciliation_batch_id'
            ]);
        });
    }
};
