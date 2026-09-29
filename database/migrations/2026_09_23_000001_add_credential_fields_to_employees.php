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
        $addEmployeeColumn = function (string $column, \Closure $definition): void {
            if (!Schema::hasColumn('employees', $column)) {
                Schema::table('employees', $definition);
            }
        };

        $addEmployeeColumn('credential_method', function (Blueprint $table) {
            $table->enum('credential_method', [
                'card',
                'fingerprint',
                'unknown',
                'review'
            ])->default('unknown')->nullable()->after('card_no');
        });
        $addEmployeeColumn('credential_status', function (Blueprint $table) {
            $table->enum('credential_status', [
                'confirmed_from_backup',
                'expected_from_backup',
                'verified',
                'conflict',
                'needs_verification',
                'unknown'
            ])->default('unknown')->nullable()->after('credential_method');
        });
        $addEmployeeColumn('credential_source', function (Blueprint $table) {
            $table->enum('credential_source', [
                'hikvision_backup',
                'application',
                'manual_verified'
            ])->nullable()->after('credential_status');
        });
        $addEmployeeColumn('card_registered', function (Blueprint $table) {
            $table->boolean('card_registered')->default(false)->nullable()->after('credential_source');
        });
        $addEmployeeColumn('card_count', function (Blueprint $table) {
            $table->integer('card_count')->default(0)->nullable()->after('card_registered');
        });
        $addEmployeeColumn('card_type', function (Blueprint $table) {
            $table->enum('card_type', [
                'normalCard',
                'superCard',
                'patrolCard'
            ])->nullable()->after('card_count');
        });
        $addEmployeeColumn('fingerprint_verified', function (Blueprint $table) {
            $table->boolean('fingerprint_verified')->default(false)->nullable()->after('card_type');
        });
        $addEmployeeColumn('source_person_number', function (Blueprint $table) {
            $table->string('source_person_number')->nullable()->after('fingerprint_verified');
        });
        $addEmployeeColumn('last_reconciled_at', function (Blueprint $table) {
            $table->timestamp('last_reconciled_at')->nullable()->after('source_person_number');
        });
        $addEmployeeColumn('reconciliation_batch_id', function (Blueprint $table) {
            $table->string('reconciliation_batch_id')->nullable()->after('last_reconciled_at');
        });

        foreach (['credential_method', 'credential_status', 'source_person_number', 'reconciliation_batch_id'] as $column) {
            if (!Schema::hasIndex('employees', [$column])) {
                Schema::table('employees', fn (Blueprint $table) => $table->index($column));
            }
        }
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
