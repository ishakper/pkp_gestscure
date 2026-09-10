<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop last_payload to guarantee zero persistence of raw biometric or ISAPI payloads.
     */
    public function up(): void
    {
        if (Schema::hasColumn('door_assignments', 'last_payload')) {
            Schema::table('door_assignments', function (Blueprint $table) {
                $table->dropColumn('last_payload');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('door_assignments', 'last_payload')) {
            Schema::table('door_assignments', function (Blueprint $table) {
                $table->text('last_payload')->nullable();
            });
        }
    }
};
