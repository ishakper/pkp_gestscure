<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Splits "is this device person the employee?" (device_link_status) from "is the
 * employee's identity verified by HR?" (identity_status). `status` keeps the combined sync
 * status. Rows written before this migration have NULL here until the next read-only run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_person_states', function (Blueprint $table) {
            $table->string('device_link_status', 20)->nullable()->after('status');
            $table->string('identity_status', 20)->nullable()->after('device_link_status');
        });
    }

    public function down(): void
    {
        Schema::table('device_person_states', function (Blueprint $table) {
            $table->dropColumn(['device_link_status', 'identity_status']);
        });
    }
};
