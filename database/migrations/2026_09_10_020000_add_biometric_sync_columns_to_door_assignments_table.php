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
        Schema::table('door_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('door_assignments', 'user_info_synced_at')) {
                $table->timestamp('user_info_synced_at')->nullable()->after('last_synced_at');
            }
            if (!Schema::hasColumn('door_assignments', 'card_synced_at')) {
                $table->timestamp('card_synced_at')->nullable()->after('user_info_synced_at');
            }
            if (!Schema::hasColumn('door_assignments', 'sync_type')) {
                $table->string('sync_type', 32)->default('FULL')->after('card_synced_at');
            }
            if (!Schema::hasColumn('door_assignments', 'last_payload')) {
                $table->text('last_payload')->nullable()->after('sync_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('door_assignments', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['user_info_synced_at', 'card_synced_at', 'sync_type', 'last_payload'] as $col) {
                if (Schema::hasColumn('door_assignments', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
